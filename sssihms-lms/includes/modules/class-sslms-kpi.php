<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 3d — KPI dashboard v2 shared aggregation module. No schema changes:
 * everything here is prepared SQL against existing tables, or (for the
 * learner roster) the same get_users()/capability pattern already used by
 * SSLMS_Documents::compliance_report(). Consumed by both the admin dashboard
 * (admin/class-sslms-admin-dashboard.php) and the polling REST endpoint
 * (includes/rest/class-sslms-rest-dashboard.php) so the two never diverge.
 *
 * "Department" throughout is sslms_profiles.discipline (per the build brief),
 * not sslms_rotations.department (an unrelated per-rotation ward field).
 */
class SSLMS_KPI {

	const THRESHOLD_OPTION = 'sslms_kpi_thresholds';

	/**
	 * Whitelist of alertable KPIs. 'direction' below = alert when value is
	 * LESS than the configured threshold (rates); above = alert when value
	 * EXCEEDS it (overdue counts). Thresholds are global per KPI (not
	 * per-department) — see PHASE3D-BRIEF.md example ("compliance % < 90").
	 */
	const KPI_DEFS = array(
		'compliance_pct'         => array( 'label' => 'Compliance %', 'direction' => 'below', 'is_percent' => true ),
		'overdue_signoffs_total' => array( 'label' => 'Overdue Checklist Sign-offs', 'direction' => 'above', 'is_percent' => false ),
		'overdue_hours_total'    => array( 'label' => 'Overdue Hour Approvals', 'direction' => 'above', 'is_percent' => false ),
		'ospe_pass_pct'          => array( 'label' => 'OSPE Pass Rate %', 'direction' => 'below', 'is_percent' => true ),
		'expiring_30'            => array( 'label' => 'Credentials Expiring ≤30 days', 'direction' => 'above', 'is_percent' => false ),
	);

	public static function init(): void {
		// Extends the existing daily cron event; does not schedule a second one.
		add_action( 'sslms_daily_expiry_check', array( __CLASS__, 'run_threshold_digest' ) );
	}

	/* ---------------------------------------------------------------- */
	/* Department dimension                                              */
	/* ---------------------------------------------------------------- */

	/** Distinct non-empty disciplines currently in use, for the filter dropdown. */
	public static function departments(): array {
		global $wpdb;
		$t    = SSLMS_DB::table( 'profiles' );
		$rows = $wpdb->get_col( "SELECT DISTINCT discipline FROM {$t} WHERE discipline != '' ORDER BY discipline" );
		return array_values( array_filter( (array) $rows, 'strlen' ) );
	}

	/**
	 * User IDs with the sslms_learn capability, optionally narrowed to one
	 * department (profiles.discipline). Empty string = no narrowing.
	 */
	private static function learner_ids( string $department = '' ): array {
		$ids = array_map( 'absint', get_users( array( 'capability' => 'sslms_learn', 'fields' => 'ID' ) ) );
		if ( '' === $department || ! $ids ) {
			return $department ? array() : $ids;
		}
		global $wpdb;
		$t  = SSLMS_DB::table( 'profiles' );
		$in = self::in_clause( $ids );
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT user_id FROM {$t} WHERE discipline = %s AND user_id IN ({$in})",
			$department
		) );
		return array_map( 'absint', $rows );
	}

	/**
	 * Safe integer IN() list. Every element is forced through absint() before
	 * concatenation, so this is not a SQL-injection vector despite not going
	 * through $wpdb->prepare(); '0' (matches nothing) when $ids is empty.
	 */
	private static function in_clause( array $ids ): string {
		$ids = array_map( 'absint', $ids );
		return $ids ? implode( ',', $ids ) : '0';
	}

	/**
	 * Prepared " INNER JOIN {profiles} pr_dept ON pr_dept.user_id = {$user_col}
	 * AND pr_dept.discipline = %s" fragment, or '' when $department is empty.
	 * Shared by the admin dashboard's existing chart/card queries.
	 */
	public static function department_join( string $user_col, string $department ): string {
		if ( '' === $department ) {
			return '';
		}
		global $wpdb;
		$profiles = SSLMS_DB::table( 'profiles' );
		return $wpdb->prepare(
			" INNER JOIN {$profiles} pr_dept ON pr_dept.user_id = {$user_col} AND pr_dept.discipline = %s",
			$department
		);
	}

	/* ---------------------------------------------------------------- */
	/* New KPI cards                                                     */
	/* ---------------------------------------------------------------- */

	/**
	 * Compliance % = valid required credential documents / total required.
	 * "Required" = every SSLMS_Documents::DOC_TYPES slot for every learner in
	 * scope (matches the existing F9.5 compliance matrix definition). "Valid"
	 * = the learner's most recently uploaded document of that type is not
	 * expired (expiry_date NULL or in the future) — mirrors SSLMS_Documents'
	 * own notion of a current credential, one row per (user, doc_type) via a
	 * MAX(uploaded_at) groupwise-max join.
	 */
	public static function compliance( string $department = '' ): array {
		$ids   = self::learner_ids( $department );
		$total = count( $ids ) * count( SSLMS_Documents::DOC_TYPES );
		if ( ! $total ) {
			return array( 'pct' => null, 'valid' => 0, 'total' => 0 );
		}
		global $wpdb;
		$t     = SSLMS_DB::table( 'documents' );
		$in    = self::in_clause( $ids );
		$valid = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$t} d
			 INNER JOIN (
				 SELECT user_id, doc_type, MAX(uploaded_at) AS max_uploaded
				 FROM {$t}
				 WHERE user_id IN ({$in})
				 GROUP BY user_id, doc_type
			 ) m ON m.user_id = d.user_id AND m.doc_type = d.doc_type AND m.max_uploaded = d.uploaded_at
			 WHERE d.user_id IN ({$in})
			   AND ( d.expiry_date IS NULL OR d.expiry_date > CURDATE() )"
		);
		return array(
			'pct'   => round( ( $valid / $total ) * 100, 1 ),
			'valid' => $valid,
			'total' => $total,
		);
	}

	/**
	 * Overdue checklist sign-offs with ageing. The schema has no per-item due
	 * date, so "overdue" = any assignment still open (locked=0, not
	 * completed); ageing buckets days since assigned_at.
	 */
	public static function overdue_signoffs( string $department = '' ): array {
		return self::ageing_buckets( 'checklist_assignments', 'ca', 'assigned_at', "ca.locked = 0 AND ca.completed_at IS NULL", $department );
	}

	/**
	 * Overdue hour-log approvals with ageing. No separate submission
	 * timestamp exists, so ageing buckets days since log_date.
	 */
	public static function overdue_hours( string $department = '' ): array {
		return self::ageing_buckets( 'hour_logs', 'hl', 'log_date', "hl.status = 'pending'", $department );
	}

	/** Shared ageing-bucket query for the two overdue KPIs above. */
	private static function ageing_buckets( string $table, string $alias, string $date_col, string $where, string $department ): array {
		global $wpdb;
		$t    = SSLMS_DB::table( $table );
		$join = self::department_join( "{$alias}.user_id", $department );
		$sql  = "SELECT
			COUNT(*) AS total,
			SUM(CASE WHEN DATEDIFF(CURDATE(), {$alias}.{$date_col}) <= 7 THEN 1 ELSE 0 END) AS d0_7,
			SUM(CASE WHEN DATEDIFF(CURDATE(), {$alias}.{$date_col}) BETWEEN 8 AND 14 THEN 1 ELSE 0 END) AS d8_14,
			SUM(CASE WHEN DATEDIFF(CURDATE(), {$alias}.{$date_col}) BETWEEN 15 AND 30 THEN 1 ELSE 0 END) AS d15_30,
			SUM(CASE WHEN DATEDIFF(CURDATE(), {$alias}.{$date_col}) > 30 THEN 1 ELSE 0 END) AS d30_plus
			FROM {$t} {$alias}{$join}
			WHERE {$where}";
		$row = $wpdb->get_row( $sql );
		return array(
			'total'   => (int) ( $row->total ?? 0 ),
			'buckets' => array(
				'0-7'   => (int) ( $row->d0_7 ?? 0 ),
				'8-14'  => (int) ( $row->d8_14 ?? 0 ),
				'15-30' => (int) ( $row->d15_30 ?? 0 ),
				'30+'   => (int) ( $row->d30_plus ?? 0 ),
			),
		);
	}

	/**
	 * OSPE pass rate (Phase 3c): pass / completed candidates across PUBLISHED
	 * exams only. Null (card omitted) when 3c is absent or nothing has been
	 * published yet.
	 */
	public static function ospe_pass_rate( string $department = '' ): ?array {
		if ( ! class_exists( 'SSLMS_OSPE' ) ) {
			return null;
		}
		global $wpdb;
		$c      = SSLMS_DB::table( 'ospe_candidates' );
		$e      = SSLMS_DB::table( 'ospe_exams' );
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $c ) ) );
		if ( ! $exists ) {
			return null;
		}
		$join = self::department_join( 'ca.user_id', $department );
		$row  = $wpdb->get_row(
			"SELECT COUNT(*) AS total,
				SUM(CASE WHEN ca.outcome = 'pass' THEN 1 ELSE 0 END) AS passed
			 FROM {$c} ca
			 INNER JOIN {$e} ex ON ex.id = ca.exam_id AND ex.status = 'published'
			 {$join}
			 WHERE ca.status = 'completed'"
		);
		$total = (int) ( $row->total ?? 0 );
		if ( ! $total ) {
			return null;
		}
		return array(
			'pct'   => round( 100 * (int) $row->passed / $total, 1 ),
			'pass'  => (int) $row->passed,
			'total' => $total,
		);
	}

	/**
	 * Expiring-credential forecast: three cumulative counts (documents whose
	 * current — i.e. latest-per-type — expiry falls within 30/60/90 days).
	 * Already-expired documents are excluded (they're the separate "Expired
	 * Documents" card).
	 */
	public static function expiring_forecast( string $department = '' ): array {
		$ids = self::learner_ids( $department );
		if ( ! $ids ) {
			return array( 'd30' => 0, 'd60' => 0, 'd90' => 0 );
		}
		global $wpdb;
		$t   = SSLMS_DB::table( 'documents' );
		$in  = self::in_clause( $ids );
		$row = $wpdb->get_row(
			"SELECT
				SUM(CASE WHEN d.expiry_date > CURDATE() AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS d30,
				SUM(CASE WHEN d.expiry_date > CURDATE() AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 1 ELSE 0 END) AS d60,
				SUM(CASE WHEN d.expiry_date > CURDATE() AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS d90
			 FROM {$t} d
			 INNER JOIN (
				 SELECT user_id, doc_type, MAX(uploaded_at) AS max_uploaded
				 FROM {$t}
				 WHERE user_id IN ({$in})
				 GROUP BY user_id, doc_type
			 ) m ON m.user_id = d.user_id AND m.doc_type = d.doc_type AND m.max_uploaded = d.uploaded_at
			 WHERE d.user_id IN ({$in})"
		);
		return array(
			'd30' => (int) ( $row->d30 ?? 0 ),
			'd60' => (int) ( $row->d60 ?? 0 ),
			'd90' => (int) ( $row->d90 ?? 0 ),
		);
	}

	/* ---------------------------------------------------------------- */
	/* Threshold alerts (options-based, no new table)                    */
	/* ---------------------------------------------------------------- */

	public static function get_thresholds(): array {
		$stored = get_option( self::THRESHOLD_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/** $values: kpi key => numeric|''|null ('' or null clears/disables that KPI's alert). */
	public static function update_thresholds( array $values ): array {
		$clean = self::get_thresholds();
		foreach ( self::KPI_DEFS as $key => $def ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$val = $values[ $key ];
			if ( '' === $val || null === $val ) {
				unset( $clean[ $key ] );
				continue;
			}
			if ( ! is_numeric( $val ) ) {
				continue;
			}
			$num = (float) $val;
			$num = ! empty( $def['is_percent'] ) ? max( 0, min( 100, $num ) ) : max( 0, $num );
			$clean[ $key ] = $num;
		}
		update_option( self::THRESHOLD_OPTION, $clean );
		SSLMS_Audit::log( 'kpi_thresholds_updated', 'kpi_threshold', 0, 'Keys: ' . implode( ',', array_keys( $clean ) ) );
		return $clean;
	}

	/**
	 * $values: kpi key => numeric|null (as produced by the dashboard's card
	 * builder). Returns the breached subset as
	 * [{key,label,value,threshold,direction}], most relevant for the alert
	 * banner and the digest email.
	 */
	public static function evaluate_alerts( array $values ): array {
		$thresholds = self::get_thresholds();
		$alerts     = array();
		foreach ( self::KPI_DEFS as $key => $def ) {
			if ( ! isset( $thresholds[ $key ] ) || ! isset( $values[ $key ] ) || null === $values[ $key ] ) {
				continue;
			}
			$value    = (float) $values[ $key ];
			$limit    = (float) $thresholds[ $key ];
			$breached = 'below' === $def['direction'] ? ( $value < $limit ) : ( $value > $limit );
			if ( $breached ) {
				$alerts[] = array(
					'key'       => $key,
					'label'     => $def['label'],
					'value'     => $value,
					'threshold' => $limit,
					'direction' => $def['direction'],
				);
			}
		}
		return $alerts;
	}

	/** Org-wide (all-departments) snapshot of every alertable KPI's current value. */
	public static function org_wide_values(): array {
		$compliance = self::compliance();
		$signoffs   = self::overdue_signoffs();
		$hours      = self::overdue_hours();
		$ospe       = self::ospe_pass_rate();
		$forecast   = self::expiring_forecast();
		return array(
			'compliance_pct'         => $compliance['pct'],
			'overdue_signoffs_total' => $signoffs['total'],
			'overdue_hours_total'    => $hours['total'],
			'ospe_pass_pct'          => $ospe['pct'] ?? null,
			'expiring_30'            => $forecast['d30'],
		);
	}

	/**
	 * Daily digest for breached thresholds. Hooked onto the SAME cron event
	 * SSLMS_Documents already schedules (sslms_daily_expiry_check) — this
	 * intentionally sends a second, separate email rather than editing
	 * SSLMS_Documents::run_expiry_check() (an existing module file this
	 * build must not touch); no second wp_schedule_event is registered.
	 * Evaluated org-wide only — see PHASE3D report for the deliberate
	 * per-department-digest scope decision.
	 */
	public static function run_threshold_digest(): void {
		$alerts = self::evaluate_alerts( self::org_wide_values() );
		if ( ! $alerts ) {
			return;
		}
		$lines = array();
		foreach ( $alerts as $a ) {
			$lines[] = sprintf(
				'%s: %s %s threshold %s (org-wide)',
				$a['label'],
				round( $a['value'], 1 ),
				'below' === $a['direction'] ? '<' : '>',
				round( $a['threshold'], 1 )
			);
		}
		$subject = sprintf( 'SSSIHMS LMS: %d KPI threshold alert(s)', count( $lines ) );
		$body    = "The following KPI dashboard thresholds have been breached (org-wide):\n\n" . implode( "\n", $lines )
			. "\n\nReview: " . admin_url( 'admin.php?page=sslms' );
		wp_mail( get_option( 'admin_email' ), $subject, $body );
		SSLMS_Audit::log( 'kpi_threshold_digest_sent', 'kpi_threshold', 0, count( $lines ) . ' breach(es)' );
	}
}
SSLMS_KPI::init();
