<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module F — analytics dashboard (SPEC F11). Renders as the content of the
 * top-level 'sslms' admin page via the 'sslms_admin_home' action fired by
 * SSLMS_Admin_Menu::render_home(). The page itself already requires the
 * 'sslms_view_reports' capability; we re-check here for defense in depth and
 * additionally scope everything to the current instructor when they lack
 * 'sslms_manage'.
 *
 * All queries are read-only prepared SQL against sslms_ tables — no writes,
 * no reliance on other modules' classes (some may not exist yet).
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

		$is_manager = current_user_can( 'sslms_manage' );
		$uid        = get_current_user_id();

		$cards       = self::stat_cards( $is_manager, $uid );
		$enrol_chart = self::enrolments_vs_completions( $is_manager, $uid );
		$month_chart = self::completions_per_month( $is_manager, $uid );
		$quiz_chart  = self::quiz_pass_rates( $is_manager, $uid );

		self::enqueue_chart_bootstrap();

		echo '<div class="wrap sslms">';
		echo '<h1>' . esc_html__( 'SSSIHMS LMS — Dashboard', 'sssihms-lms' ) . '</h1>';
		if ( ! $is_manager ) {
			echo '<p>' . esc_html__( 'Showing statistics for courses you created.', 'sssihms-lms' ) . '</p>';
		}

		self::render_cards( $cards );

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
	/* Stat cards                                                        */
	/* ---------------------------------------------------------------- */

	private static function stat_cards( bool $is_manager, int $uid ): array {
		global $wpdb;
		$courses = SSLMS_DB::table( 'courses' );
		$enrol   = SSLMS_DB::table( 'enrollments' );
		$certs   = SSLMS_DB::table( 'certificates' );

		$scope_courses = $is_manager ? '' : $wpdb->prepare( ' AND created_by = %d', $uid );

		$published = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$courses} WHERE status = 'published'" . $scope_courses );

		$enrol_scope_join  = $is_manager ? '' : " JOIN {$courses} c ON c.id = e.course_id";
		$enrol_scope_where = $is_manager ? '' : $wpdb->prepare( ' AND c.created_by = %d', $uid );

		$active_learners = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT e.user_id) FROM {$enrol} e{$enrol_scope_join} WHERE e.status = 'active'" . $enrol_scope_where
		);

		list( $month_start, $month_end ) = self::current_month_range();

		$completions_this_month = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$enrol} e{$enrol_scope_join}
			 WHERE e.completed_at IS NOT NULL AND e.completed_at >= %s AND e.completed_at < %s" . $enrol_scope_where,
			$month_start,
			$month_end
		) );

		$cert_scope_join = $is_manager ? '' : " JOIN {$enrol} e ON e.id = cf.enrollment_id JOIN {$courses} c ON c.id = e.course_id";
		$certificates_this_month = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$certs} cf{$cert_scope_join}
			 WHERE cf.issued_at >= %s AND cf.issued_at < %s" . $enrol_scope_where,
			$month_start,
			$month_end
		) );

		$cards = array(
			array( 'title' => __( 'Published Courses', 'sssihms-lms' ), 'value' => $published ),
			array( 'title' => __( 'Active Learners', 'sssihms-lms' ), 'value' => $active_learners ),
			array( 'title' => __( 'Completions This Month', 'sssihms-lms' ), 'value' => $completions_this_month ),
			array( 'title' => __( 'Certificates Issued This Month', 'sssihms-lms' ), 'value' => $certificates_this_month ),
		);

		if ( $is_manager ) {
			$docs        = SSLMS_DB::table( 'documents' );
			$checklists  = SSLMS_DB::table( 'checklist_assignments' );
			$hour_logs   = SSLMS_DB::table( 'hour_logs' );

			$expiring = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$docs} WHERE expiry_date IS NOT NULL AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
			);
			$expired = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$docs} WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE()"
			);
			$pending_signoffs = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$checklists} WHERE locked = 0 AND completed_at IS NULL"
			);
			$pending_hours = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$hour_logs} WHERE status = 'pending'"
			);

			$cards[] = array( 'title' => __( 'Expiring Documents (≤30 days)', 'sssihms-lms' ), 'value' => $expiring, 'badge' => $expiring > 0 ? 'warn' : 'ok' );
			$cards[] = array( 'title' => __( 'Expired Documents', 'sssihms-lms' ), 'value' => $expired, 'badge' => $expired > 0 ? 'bad' : 'ok' );
			$cards[] = array( 'title' => __( 'Pending Checklist Sign-offs', 'sssihms-lms' ), 'value' => $pending_signoffs, 'badge' => $pending_signoffs > 0 ? 'warn' : 'ok' );
			$cards[] = array( 'title' => __( 'Pending Hour Approvals', 'sssihms-lms' ), 'value' => $pending_hours, 'badge' => $pending_hours > 0 ? 'warn' : 'ok' );
		}

		return $cards;
	}

	private static function render_cards( array $cards ): void {
		echo '<div class="sslms-admin-cards">';
		foreach ( $cards as $card ) {
			$badge_html = '';
			if ( ! empty( $card['badge'] ) ) {
				$badge_html = ' <span class="sslms-badge sslms-badge--' . esc_attr( $card['badge'] ) . '">' . esc_html( (string) $card['value'] ) . '</span>';
			}
			echo '<div class="sslms-admin-card"><h2>' . esc_html( $card['title'] ) . '</h2><div class="sslms-big">' . esc_html( (string) $card['value'] ) . '</div></div>';
		}
		echo '</div>';
	}

	/* ---------------------------------------------------------------- */
	/* Charts                                                            */
	/* ---------------------------------------------------------------- */

	/** Bar: enrolments vs completions per course, top 10 by enrolments. */
	private static function enrolments_vs_completions( bool $is_manager, int $uid ): array {
		global $wpdb;
		$courses = SSLMS_DB::table( 'courses' );
		$enrol   = SSLMS_DB::table( 'enrollments' );

		$where  = $is_manager ? '' : $wpdb->prepare( ' WHERE c.created_by = %d', $uid );
		$rows = $wpdb->get_results(
			"SELECT c.title AS title,
			        COUNT(e.id) AS enrolments,
			        SUM(CASE WHEN e.status = 'completed' THEN 1 ELSE 0 END) AS completions
			 FROM {$courses} c
			 LEFT JOIN {$enrol} e ON e.course_id = c.id
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

	/** Line: completions per month, last 6 months (zero-filled). */
	private static function completions_per_month( bool $is_manager, int $uid ): array {
		global $wpdb;
		$courses = SSLMS_DB::table( 'courses' );
		$enrol   = SSLMS_DB::table( 'enrollments' );

		list( $month_start ) = self::current_month_range();
		$window_start = gmdate( 'Y-m-01 00:00:00', strtotime( '-5 months', strtotime( $month_start ) ) );

		$join  = $is_manager ? '' : " JOIN {$courses} c ON c.id = e.course_id";
		$scope = $is_manager ? '' : $wpdb->prepare( ' AND c.created_by = %d', $uid );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(e.completed_at, '%%Y-%%m') AS ym, COUNT(*) AS cnt
			 FROM {$enrol} e{$join}
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

	/** Bar: quiz pass rate per quiz, top 10 by attempt count. */
	private static function quiz_pass_rates( bool $is_manager, int $uid ): array {
		global $wpdb;
		$quizzes  = SSLMS_DB::table( 'quizzes' );
		$attempts = SSLMS_DB::table( 'quiz_attempts' );
		$courses  = SSLMS_DB::table( 'courses' );

		$join  = $is_manager ? '' : " JOIN {$courses} c ON c.id = q.course_id";
		$scope = $is_manager ? '' : $wpdb->prepare( ' AND c.created_by = %d', $uid );

		$rows = $wpdb->get_results(
			"SELECT q.title AS title,
			        COUNT(a.id) AS attempts,
			        SUM(CASE WHEN a.passed = 1 THEN 1 ELSE 0 END) AS passed
			 FROM {$quizzes} q
			 LEFT JOIN {$attempts} a ON a.quiz_id = q.id AND a.submitted_at IS NOT NULL
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
			$attempts_n = (int) $row->attempts;
			$labels[]   = $row->title;
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
		$js = "document.addEventListener('DOMContentLoaded',function(){"
			. "if(typeof Chart==='undefined'){return;}"
			. "document.querySelectorAll('canvas[data-sslms-chart]').forEach(function(cv){"
			. "try{var cfg=JSON.parse(cv.getAttribute('data-sslms-chart'));new Chart(cv.getContext('2d'),cfg);}catch(e){}"
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
