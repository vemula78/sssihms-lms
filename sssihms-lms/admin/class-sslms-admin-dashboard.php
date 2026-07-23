<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module F — analytics dashboard (SPEC F11), extended for Phase 3d (KPI
 * dashboard v2 — see ROADMAP-PHASE3.md / PHASE3D-BRIEF.md): department
 * (sslms_profiles.discipline) drill-down, new compliance/overdue/forecast
 * KPI cards, threshold alerts, 30-60s self-refresh via REST polling, and a
 * `?sslms_tv=1` full-screen board mode. Renders as the content of the
 * top-level 'sslms' admin page via the 'sslms_admin_home' action fired by
 * SSLMS_Admin_Menu::render_home(). The page itself already requires the
 * 'sslms_view_reports' capability; we re-check here for defense in depth and
 * additionally scope everything to the current instructor when they lack
 * 'sslms_manage'.
 *
 * All queries are read-only prepared SQL against sslms_ tables — no writes,
 * no reliance on other modules' classes (some may not exist yet). Card and
 * chart data builders are public so includes/rest/class-sslms-rest-dashboard.php
 * can reuse them verbatim for the polling endpoint (single source of truth).
 */
class SSLMS_Admin_Dashboard {

	public static function init(): void {
		add_action( 'sslms_admin_home', array( __CLASS__, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'sslms_view_reports' ) ) {
			echo '<div class="wrap sslms"><p>' . esc_html__( 'You do not have permission to view this page.', 'sssihms-lms' ) . '</p></div>';
			return;
		}

		$is_manager  = current_user_can( 'sslms_manage' );
		$uid         = get_current_user_id();
		$is_tv       = ! empty( $_GET['sslms_tv'] );
		$departments = SSLMS_KPI::departments();

		$department = isset( $_GET['department'] ) ? sanitize_text_field( wp_unslash( $_GET['department'] ) ) : '';
		if ( '' !== $department && ! in_array( $department, $departments, true ) ) {
			$department = '';
		}

		self::enqueue_chart_bootstrap();
		self::enqueue_refresh_assets( $departments, $is_tv ? '' : $department, $is_tv );

		if ( $is_tv ) {
			self::render_tv_mode( $is_manager, $uid );
			return;
		}

		$cards       = self::stat_cards( $is_manager, $uid, $department );
		$enrol_chart = self::enrolments_vs_completions( $is_manager, $uid, $department );
		$month_chart = self::completions_per_month( $is_manager, $uid, $department );
		$quiz_chart  = self::quiz_pass_rates( $is_manager, $uid, $department );
		$alerts      = $is_manager ? SSLMS_KPI::evaluate_alerts( self::raw_kpi_map( $cards ) ) : array();

		echo '<div class="wrap sslms">';
		echo '<h1>' . esc_html__( 'SSSIHMS LMS — Dashboard', 'sssihms-lms' ) . '</h1>';
		if ( ! $is_manager ) {
			echo '<p>' . esc_html__( 'Showing statistics for courses you created.', 'sssihms-lms' ) . '</p>';
		}

		self::render_alert_banner( $alerts );
		self::render_department_filter( $departments, $department );

		self::render_cards( $cards );

		if ( $is_manager ) {
			self::render_threshold_form();
		}

		self::render_chart(
			'sslms-chart-enrol',
			$enrol_chart,
			__( 'Enrolments vs Completions per Course (Top 10)', 'sssihms-lms' )
		);
		self::render_chart(
			'sslms-chart-month',
			$month_chart,
			__( 'Completions per Month (Last 6 Months)', 'sssihms-lms' )
		);
		self::render_chart(
			'sslms-chart-quiz',
			$quiz_chart,
			__( 'Quiz Pass Rate (Top 10 by Attempts)', 'sssihms-lms' )
		);

		echo '</div>';
	}

	/* ---------------------------------------------------------------- */
	/* Department filter, alerts, threshold settings                     */
	/* ---------------------------------------------------------------- */

	private static function render_department_filter( array $departments, string $current ): void {
		if ( ! $departments ) {
			return;
		}
		echo '<form method="get" class="sslms-flex" style="margin:12px 0;">';
		echo '<input type="hidden" name="page" value="sslms" />';
		echo '<label for="sslms-dept-filter">' . esc_html__( 'Department', 'sssihms-lms' ) . '</label> ';
		echo '<select id="sslms-dept-filter" name="department" onchange="this.form.submit()">';
		echo '<option value="">' . esc_html__( 'All departments', 'sssihms-lms' ) . '</option>';
		foreach ( $departments as $dept ) {
			echo '<option value="' . esc_attr( $dept ) . '" ' . selected( $current, $dept, false ) . '>' . esc_html( $dept ) . '</option>';
		}
		echo '</select> ';
		echo '<noscript><button type="submit" class="button">' . esc_html__( 'Filter', 'sssihms-lms' ) . '</button></noscript>';
		echo '</form>';
	}

	/** $alerts: SSLMS_KPI::evaluate_alerts() output. Wrapper div is always present so the poller has a target to refresh. */
	private static function render_alert_banner( array $alerts ): void {
		echo '<div id="sslms-kpi-alerts">';
		self::render_alert_banner_inner( $alerts );
		echo '</div>';
	}

	private static function render_alert_banner_inner( array $alerts ): void {
		if ( ! $alerts ) {
			return;
		}
		echo '<div class="sslms-badge--bad" style="display:block;border-radius:8px;padding:12px 16px;margin:12px 0;font-weight:700;">';
		echo esc_html__( 'KPI threshold alerts:', 'sssihms-lms' ) . ' ';
		$parts = array();
		foreach ( $alerts as $a ) {
			$parts[] = sprintf(
				'%1$s: %2$s %3$s %4$s',
				$a['label'],
				rtrim( rtrim( number_format( (float) $a['value'], 1 ), '0' ), '.' ),
				'below' === $a['direction'] ? '<' : '>',
				rtrim( rtrim( number_format( (float) $a['threshold'], 1 ), '0' ), '.' )
			);
		}
		echo esc_html( implode( '   •   ', $parts ) );
		echo '</div>';
	}

	/** sslms_manage only — options-based thresholds, no new table. Reuses the shared JS form auto-wiring (data-sslms-endpoint). */
	private static function render_threshold_form(): void {
		$thresholds = SSLMS_KPI::get_thresholds();
		echo '<details class="sslms-admin-chart" style="max-width:640px;">';
		echo '<summary style="cursor:pointer;font-weight:600;">' . esc_html__( 'KPI threshold alerts (dashboard banner + daily digest email)', 'sssihms-lms' ) . '</summary>';
		echo '<form data-sslms-endpoint="dashboard/thresholds" data-sslms-method="PUT" data-sslms-noreload="1" style="margin-top:10px;">';
		foreach ( SSLMS_KPI::KPI_DEFS as $key => $def ) {
			$val = isset( $thresholds[ $key ] ) ? $thresholds[ $key ] : '';
			echo '<label style="display:block;margin-bottom:8px;">'
				. esc_html( $def['label'] ) . ' — '
				. ( 'below' === $def['direction']
					? esc_html__( 'alert if below', 'sssihms-lms' )
					: esc_html__( 'alert if above', 'sssihms-lms' ) )
				. ' <input type="number" step="0.1" min="0"' . ( ! empty( $def['is_percent'] ) ? ' max="100"' : '' )
				. ' name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $val ) . '" placeholder="'
				. esc_attr__( 'off', 'sssihms-lms' ) . '" style="width:100px;" /></label>';
		}
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save thresholds', 'sssihms-lms' ) . '</button>';
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Org-wide (all departments). Leave a field blank to turn its alert off.', 'sssihms-lms' ) . '</p>';
		echo '</details>';
	}

	/** Raw numeric values keyed by KPI slug, for SSLMS_KPI::evaluate_alerts(). */
	private static function raw_kpi_map( array $cards ): array {
		$map = array();
		foreach ( $cards as $c ) {
			if ( isset( $c['key'], $c['raw'] ) && array_key_exists( $c['key'], SSLMS_KPI::KPI_DEFS ) ) {
				$map[ $c['key'] ] = $c['raw'];
			}
		}
		return $map;
	}

	/* ---------------------------------------------------------------- */
	/* Stat cards                                                        */
	/* ---------------------------------------------------------------- */

	/** Public: also called by SSLMS_REST_Dashboard for the polling endpoint. */
	public static function stat_cards( bool $is_manager, int $uid, string $department = '' ): array {
		global $wpdb;
		$courses = SSLMS_DB::table( 'courses' );
		$enrol   = SSLMS_DB::table( 'enrollments' );
		$certs   = SSLMS_DB::table( 'certificates' );

		$scope_courses = $is_manager ? '' : $wpdb->prepare( ' AND created_by = %d', $uid );

		$published = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$courses} WHERE status = 'published'" . $scope_courses );

		$enrol_scope_join  = $is_manager ? '' : " JOIN {$courses} c ON c.id = e.course_id";
		$enrol_scope_where = $is_manager ? '' : $wpdb->prepare( ' AND c.created_by = %d', $uid );
		$enrol_dept_join   = SSLMS_KPI::department_join( 'e.user_id', $department );

		$active_learners = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT e.user_id) FROM {$enrol} e{$enrol_scope_join}{$enrol_dept_join} WHERE e.status = 'active'" . $enrol_scope_where
		);

		list( $month_start, $month_end ) = self::current_month_range();

		$completions_this_month = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$enrol} e{$enrol_scope_join}{$enrol_dept_join}
			 WHERE e.completed_at IS NOT NULL AND e.completed_at >= %s AND e.completed_at < %s" . $enrol_scope_where,
			$month_start,
			$month_end
		) );

		$cert_join = " JOIN {$enrol} e ON e.id = cf.enrollment_id";
		if ( ! $is_manager ) {
			$cert_join .= " JOIN {$courses} c ON c.id = e.course_id";
		}
		$cert_join .= SSLMS_KPI::department_join( 'e.user_id', $department );

		$certificates_this_month = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$certs} cf{$cert_join}
			 WHERE cf.issued_at >= %s AND cf.issued_at < %s" . $enrol_scope_where,
			$month_start,
			$month_end
		) );

		$cards = array(
			array( 'key' => 'published_courses', 'title' => __( 'Published Courses', 'sssihms-lms' ), 'value' => $published, 'raw' => $published ),
			array( 'key' => 'active_learners', 'title' => __( 'Active Learners', 'sssihms-lms' ), 'value' => $active_learners, 'raw' => $active_learners ),
			array( 'key' => 'completions_month', 'title' => __( 'Completions This Month', 'sssihms-lms' ), 'value' => $completions_this_month, 'raw' => $completions_this_month ),
			array( 'key' => 'certs_month', 'title' => __( 'Certificates Issued This Month', 'sssihms-lms' ), 'value' => $certificates_this_month, 'raw' => $certificates_this_month ),
		);

		if ( $is_manager ) {
			$docs       = SSLMS_DB::table( 'documents' );
			$checklists = SSLMS_DB::table( 'checklist_assignments' );
			$hour_logs  = SSLMS_DB::table( 'hour_logs' );

			$docs_dept_join = SSLMS_KPI::department_join( 'd.user_id', $department );
			$expiring       = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$docs} d{$docs_dept_join} WHERE d.expiry_date IS NOT NULL AND d.expiry_date >= CURDATE() AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
			);
			$expired = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$docs} d{$docs_dept_join} WHERE d.expiry_date IS NOT NULL AND d.expiry_date < CURDATE()"
			);

			$ca_dept_join     = SSLMS_KPI::department_join( 'ca.user_id', $department );
			$pending_signoffs = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$checklists} ca{$ca_dept_join} WHERE ca.locked = 0 AND ca.completed_at IS NULL"
			);

			$hl_dept_join  = SSLMS_KPI::department_join( 'hl.user_id', $department );
			$pending_hours = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$hour_logs} hl{$hl_dept_join} WHERE hl.status = 'pending'"
			);

			$cards[] = array( 'key' => 'expiring_docs', 'title' => __( 'Expiring Documents (≤30 days)', 'sssihms-lms' ), 'value' => $expiring, 'raw' => $expiring, 'badge' => $expiring > 0 ? 'warn' : 'ok' );
			$cards[] = array( 'key' => 'expired_docs', 'title' => __( 'Expired Documents', 'sssihms-lms' ), 'value' => $expired, 'raw' => $expired, 'badge' => $expired > 0 ? 'bad' : 'ok' );
			$cards[] = array( 'key' => 'pending_signoffs', 'title' => __( 'Pending Checklist Sign-offs', 'sssihms-lms' ), 'value' => $pending_signoffs, 'raw' => $pending_signoffs, 'badge' => $pending_signoffs > 0 ? 'warn' : 'ok' );
			$cards[] = array( 'key' => 'pending_hours', 'title' => __( 'Pending Hour Approvals', 'sssihms-lms' ), 'value' => $pending_hours, 'raw' => $pending_hours, 'badge' => $pending_hours > 0 ? 'warn' : 'ok' );

			$cards = array_merge( $cards, self::new_kpi_cards( $department ) );
		}

		return $cards;
	}

	/** Phase 3d new cards (SSLMS_KPI-backed): compliance %, overdue ageing x2, OSPE (guarded), expiry forecast. Manager-only, same as the other org-wide cards above. */
	private static function new_kpi_cards( string $department ): array {
		$thresholds = SSLMS_KPI::get_thresholds();
		$cards      = array();

		$compliance = SSLMS_KPI::compliance( $department );
		$comp_badge = null;
		if ( null !== $compliance['pct'] && isset( $thresholds['compliance_pct'] ) ) {
			$comp_badge = $compliance['pct'] < $thresholds['compliance_pct'] ? 'bad' : 'ok';
		}
		$cards[] = array(
			'key'   => 'compliance_pct',
			'title' => __( 'Compliance % (valid required credentials)', 'sssihms-lms' ),
			'value' => null === $compliance['pct'] ? '—' : $compliance['pct'] . '%',
			'raw'   => $compliance['pct'],
			'badge' => $comp_badge,
		);

		$signoffs = SSLMS_KPI::overdue_signoffs( $department );
		$so_badge = isset( $thresholds['overdue_signoffs_total'] )
			? ( $signoffs['total'] > $thresholds['overdue_signoffs_total'] ? 'bad' : 'ok' )
			: ( $signoffs['total'] > 0 ? 'warn' : 'ok' );
		$cards[] = array(
			'key'   => 'overdue_signoffs_total',
			'title' => __( 'Overdue Sign-offs — ageing 0-7 / 8-14 / 15-30 / 30+ days', 'sssihms-lms' ),
			'value' => sprintf( '%d (%d/%d/%d/%d)', $signoffs['total'], $signoffs['buckets']['0-7'], $signoffs['buckets']['8-14'], $signoffs['buckets']['15-30'], $signoffs['buckets']['30+'] ),
			'raw'   => $signoffs['total'],
			'badge' => $so_badge,
		);

		$hours    = SSLMS_KPI::overdue_hours( $department );
		$ho_badge = isset( $thresholds['overdue_hours_total'] )
			? ( $hours['total'] > $thresholds['overdue_hours_total'] ? 'bad' : 'ok' )
			: ( $hours['total'] > 0 ? 'warn' : 'ok' );
		$cards[] = array(
			'key'   => 'overdue_hours_total',
			'title' => __( 'Overdue Hour Approvals — ageing 0-7 / 8-14 / 15-30 / 30+ days', 'sssihms-lms' ),
			'value' => sprintf( '%d (%d/%d/%d/%d)', $hours['total'], $hours['buckets']['0-7'], $hours['buckets']['8-14'], $hours['buckets']['15-30'], $hours['buckets']['30+'] ),
			'raw'   => $hours['total'],
			'badge' => $ho_badge,
		);

		$ospe = SSLMS_KPI::ospe_pass_rate( $department );
		if ( null !== $ospe ) {
			$ospe_badge = isset( $thresholds['ospe_pass_pct'] ) ? ( $ospe['pct'] < $thresholds['ospe_pass_pct'] ? 'bad' : 'ok' ) : null;
			$cards[]    = array(
				'key'   => 'ospe_pass_pct',
				'title' => __( 'OSPE Pass Rate %', 'sssihms-lms' ),
				'value' => $ospe['pct'] . '%',
				'raw'   => $ospe['pct'],
				'badge' => $ospe_badge,
			);
		}

		$forecast = SSLMS_KPI::expiring_forecast( $department );
		$fc_badge = isset( $thresholds['expiring_30'] ) ? ( $forecast['d30'] > $thresholds['expiring_30'] ? 'bad' : 'ok' ) : null;
		$cards[]  = array(
			'key'   => 'expiring_30',
			'title' => __( 'Expiring Credentials Forecast — 30 / 60 / 90 days', 'sssihms-lms' ),
			'value' => sprintf( '%d / %d / %d', $forecast['d30'], $forecast['d60'], $forecast['d90'] ),
			'raw'   => $forecast['d30'],
			'badge' => $fc_badge,
		);

		return $cards;
	}

	private static function render_cards( array $cards ): void {
		echo '<div class="sslms-admin-cards" id="sslms-admin-cards">';
		foreach ( $cards as $card ) {
			$key   = isset( $card['key'] ) ? $card['key'] : '';
			$class = 'sslms-big';
			if ( ! empty( $card['badge'] ) ) {
				$class .= ' sslms-badge sslms-badge--' . sanitize_html_class( $card['badge'] );
			}
			echo '<div class="sslms-admin-card"' . ( $key ? ' data-kpi="' . esc_attr( $key ) . '"' : '' ) . '>';
			echo '<h2>' . esc_html( $card['title'] ) . '</h2>';
			echo '<div class="' . esc_attr( $class ) . '"' . ( $key ? ' data-kpi-value="' . esc_attr( $key ) . '"' : '' ) . '>' . esc_html( (string) $card['value'] ) . '</div>';
			echo '</div>';
		}
		echo '</div>';
	}

	/* ---------------------------------------------------------------- */
	/* Charts                                                            */
	/* ---------------------------------------------------------------- */

	/** Bar: enrolments vs completions per course, top 10 by enrolments. Public: reused by SSLMS_REST_Dashboard. */
	public static function enrolments_vs_completions( bool $is_manager, int $uid, string $department = '' ): array {
		global $wpdb;
		$courses = SSLMS_DB::table( 'courses' );
		$enrol   = SSLMS_DB::table( 'enrollments' );

		$where = $is_manager ? '' : $wpdb->prepare( ' WHERE c.created_by = %d', $uid );

		// Department filter is applied via a derived subquery aliased as `e` so
		// courses with zero matching-department enrolments still appear (LEFT
		// JOIN semantics preserved) instead of being narrowed away.
		$enrol_source = "{$enrol} e";
		if ( '' !== $department ) {
			$profiles     = SSLMS_DB::table( 'profiles' );
			$enrol_source = $wpdb->prepare(
				"(SELECT e.* FROM {$enrol} e INNER JOIN {$profiles} pr ON pr.user_id = e.user_id AND pr.discipline = %s) e",
				$department
			);
		}

		$rows = $wpdb->get_results(
			"SELECT c.title AS title,
			        COUNT(e.id) AS enrolments,
			        SUM(CASE WHEN e.status = 'completed' THEN 1 ELSE 0 END) AS completions
			 FROM {$courses} c
			 LEFT JOIN {$enrol_source} ON e.course_id = c.id
			 {$where}
			 GROUP BY c.id, c.title
			 ORDER BY enrolments DESC
			 LIMIT 10"
		);

		$labels      = array();
		$enrolments  = array();
		$completions = array();
		foreach ( (array) $rows as $row ) {
			$labels[]      = $row->title;
			$enrolments[]  = (int) $row->enrolments;
			$completions[] = (int) $row->completions;
		}

		return array(
			'type'     => 'bar',
			'labels'   => $labels,
			'datasets' => array(
				array( 'label' => __( 'Enrolments', 'sssihms-lms' ), 'data' => $enrolments ),
				array( 'label' => __( 'Completions', 'sssihms-lms' ), 'data' => $completions ),
			),
		);
	}

	/** Line: completions per month, last 6 months (zero-filled). Public: reused by SSLMS_REST_Dashboard. */
	public static function completions_per_month( bool $is_manager, int $uid, string $department = '' ): array {
		global $wpdb;
		$courses = SSLMS_DB::table( 'courses' );
		$enrol   = SSLMS_DB::table( 'enrollments' );

		list( $month_start ) = self::current_month_range();
		$window_start = gmdate( 'Y-m-01 00:00:00', strtotime( '-5 months', strtotime( $month_start ) ) );

		$join  = $is_manager ? '' : " JOIN {$courses} c ON c.id = e.course_id";
		$scope = $is_manager ? '' : $wpdb->prepare( ' AND c.created_by = %d', $uid );
		$dept  = SSLMS_KPI::department_join( 'e.user_id', $department );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(e.completed_at, '%%Y-%%m') AS ym, COUNT(*) AS cnt
			 FROM {$enrol} e{$join}{$dept}
			 WHERE e.completed_at IS NOT NULL AND e.completed_at >= %s{$scope}
			 GROUP BY ym",
			$window_start
		) );

		$by_month = array();
		foreach ( (array) $rows as $row ) {
			$by_month[ $row->ym ] = (int) $row->cnt;
		}

		$labels = array();
		$data   = array();
		for ( $i = 5; $i >= 0; $i-- ) {
			$ts       = strtotime( "-{$i} months", strtotime( $month_start ) );
			$ym       = gmdate( 'Y-m', $ts );
			$labels[] = gmdate( 'M-Y', $ts );
			$data[]   = isset( $by_month[ $ym ] ) ? $by_month[ $ym ] : 0;
		}

		return array(
			'type'     => 'line',
			'labels'   => $labels,
			'datasets' => array(
				array( 'label' => __( 'Completions', 'sssihms-lms' ), 'data' => $data ),
			),
		);
	}

	/** Bar: quiz pass rate per quiz, top 10 by attempt count. Public: reused by SSLMS_REST_Dashboard. */
	public static function quiz_pass_rates( bool $is_manager, int $uid, string $department = '' ): array {
		global $wpdb;
		$quizzes  = SSLMS_DB::table( 'quizzes' );
		$attempts = SSLMS_DB::table( 'quiz_attempts' );
		$courses  = SSLMS_DB::table( 'courses' );

		$join  = $is_manager ? '' : " JOIN {$courses} c ON c.id = q.course_id";
		$scope = $is_manager ? '' : $wpdb->prepare( ' AND c.created_by = %d', $uid );

		$attempts_source = "{$attempts} a";
		if ( '' !== $department ) {
			$profiles        = SSLMS_DB::table( 'profiles' );
			$attempts_source = $wpdb->prepare(
				"(SELECT a.* FROM {$attempts} a INNER JOIN {$profiles} pr ON pr.user_id = a.user_id AND pr.discipline = %s) a",
				$department
			);
		}

		$rows = $wpdb->get_results(
			"SELECT q.title AS title,
			        COUNT(a.id) AS attempts,
			        SUM(CASE WHEN a.passed = 1 THEN 1 ELSE 0 END) AS passed
			 FROM {$quizzes} q
			 LEFT JOIN {$attempts_source} ON a.quiz_id = q.id AND a.submitted_at IS NOT NULL
			 {$join}
			 WHERE 1=1{$scope}
			 GROUP BY q.id, q.title
			 HAVING attempts > 0
			 ORDER BY attempts DESC
			 LIMIT 10"
		);

		$labels    = array();
		$pass_rate = array();
		foreach ( (array) $rows as $row ) {
			$attempts_n  = (int) $row->attempts;
			$labels[]    = $row->title;
			$pass_rate[] = $attempts_n > 0 ? round( ( (int) $row->passed / $attempts_n ) * 100, 1 ) : 0;
		}

		return array(
			'type'     => 'bar',
			'labels'   => $labels,
			'datasets' => array(
				array( 'label' => __( 'Pass rate %', 'sssihms-lms' ), 'data' => $pass_rate ),
			),
		);
	}

	/* ---------------------------------------------------------------- */
	/* Board / TV mode (?sslms_tv=1) — still requires sslms_view_reports  */
	/* ---------------------------------------------------------------- */

	private static function render_tv_mode( bool $is_manager, int $uid ): void {
		echo '<style>
			#wpadminbar, #adminmenumain, #adminmenuback, #adminmenuwrap, #wpfooter, #screen-meta-links, .notice, .update-nag { display:none !important; }
			html.wp-toolbar { padding-top:0 !important; }
			#wpcontent, #wpbody, #wpbody-content { margin-left:0 !important; padding:0 !important; }
			.sslms-tv { background:#0b1220; color:#f5f7fa; min-height:100vh; padding:32px 40px; box-sizing:border-box; }
			.sslms-tv h1 { font-size:40px; margin:0 0 4px; }
			.sslms-tv .sslms-tv-sub { font-size:22px; font-weight:400; color:#9fb3c8; margin:0 0 24px; }
			.sslms-tv .sslms-admin-cards { grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap:20px; }
			.sslms-tv .sslms-admin-card { background:#141d2e; border-color:#2a3a52; padding:22px 24px; border-radius:12px; }
			.sslms-tv .sslms-admin-card h2 { color:#9fb3c8; font-size:17px; }
			.sslms-tv .sslms-big { font-size:46px; }
			#sslms-kpi-alerts:empty { display:none; }
		</style>';

		echo '<div class="wrap sslms sslms-tv">';
		echo '<h1>' . esc_html__( 'SSSIHMS LMS — KPI Board', 'sssihms-lms' ) . '</h1>';
		echo '<div class="sslms-tv-sub" id="sslms-tv-department">' . esc_html__( 'All departments', 'sssihms-lms' ) . '</div>';
		echo '<div id="sslms-kpi-alerts"></div>';

		$cards = self::stat_cards( $is_manager, $uid, '' );
		self::render_cards( $cards );

		echo '</div>';
	}

	/* ---------------------------------------------------------------- */
	/* Self-refresh (REST polling) assets                                */
	/* ---------------------------------------------------------------- */

	private static function enqueue_refresh_assets( array $departments, string $department, bool $tv ): void {
		wp_enqueue_script(
			'sslms-dashboard-refresh',
			SSLMS_URL . 'assets/js/sslms-dashboard-refresh.js',
			array( 'sslms' ),
			SSLMS_VERSION,
			true
		);
		wp_localize_script( 'sslms-dashboard-refresh', 'SSLMS_DASH_CFG', array(
			'department'  => $department,
			'departments' => $departments,
			'pollMs'      => 45000,
			'tv'          => $tv,
			'tvRotateMs'  => 15000,
			'allLabel'    => __( 'All departments', 'sssihms-lms' ),
		) );
	}

	/* ---------------------------------------------------------------- */
	/* Rendering helpers                                                  */
	/* ---------------------------------------------------------------- */

	private static function render_chart( string $id, array $chart, string $title ): void {
		$config = array(
			'type' => $chart['type'],
			'data' => array(
				'labels'   => $chart['labels'],
				'datasets' => $chart['datasets'],
			),
			'options' => array(
				'responsive' => true,
				'plugins'    => array(
					'title' => array( 'display' => true, 'text' => $title ),
				),
			),
		);

		echo '<div class="sslms-admin-chart">';
		echo '<h2>' . esc_html( $title ) . '</h2>';

		if ( empty( $chart['labels'] ) ) {
			echo '<p>' . esc_html__( 'No data yet.', 'sssihms-lms' ) . '</p></div>';
			return;
		}

		echo '<canvas id="' . esc_attr( $id ) . '" data-sslms-chart="' . esc_attr( wp_json_encode( $config ) ) . '" height="120"></canvas>';

		// No-JS fallback / always-on plain-table rendering (SPEC F11.2).
		echo '<table class="widefat striped" style="margin-top:12px;"><thead><tr><th>' . esc_html__( 'Label', 'sssihms-lms' ) . '</th>';
		foreach ( $chart['datasets'] as $ds ) {
			echo '<th>' . esc_html( $ds['label'] ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $chart['labels'] as $i => $label ) {
			echo '<tr><td>' . esc_html( $label ) . '</td>';
			foreach ( $chart['datasets'] as $ds ) {
				echo '<td>' . esc_html( (string) ( $ds['data'][ $i ] ?? 0 ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	private static function enqueue_chart_bootstrap(): void {
		$js = "window.SSLMS_CHARTS=window.SSLMS_CHARTS||{};"
			. "document.addEventListener('DOMContentLoaded',function(){"
			. "if(typeof Chart==='undefined'){return;}"
			. "document.querySelectorAll('canvas[data-sslms-chart]').forEach(function(cv){"
			. "try{var cfg=JSON.parse(cv.getAttribute('data-sslms-chart'));window.SSLMS_CHARTS[cv.id]=new Chart(cv.getContext('2d'),cfg);}catch(e){}"
			. "});});";
		wp_add_inline_script( 'sslms-chartjs', $js );
	}

	/** [month_start, month_end) in UTC (matches SSLMS_DB::now()'s GMT storage). */
	private static function current_month_range(): array {
		$now_gmt     = current_time( 'timestamp', true );
		$month_start = gmdate( 'Y-m-01 00:00:00', $now_gmt );
		$month_end   = gmdate( 'Y-m-01 00:00:00', strtotime( '+1 month', strtotime( $month_start ) ) );
		return array( $month_start, $month_end );
	}
}
SSLMS_Admin_Dashboard::init();
