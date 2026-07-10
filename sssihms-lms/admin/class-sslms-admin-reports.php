<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Reports" admin screen (SPEC F4.3): a course picker showing a roster table
 * and a user picker showing a transcript, each with a CSV download link
 * hitting the sslms/v1 REST routes. Instructors see only their own courses.
 */
class SSLMS_Admin_Reports {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 20 );
	}

	public static function register(): void {
		add_submenu_page(
			'sslms',
			'Reports',
			'Reports',
			'sslms_view_reports',
			'sslms-reports',
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'sslms_view_reports' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'sssihms-lms' ) );
		}

		$course_id = isset( $_GET['sslms_course_id'] ) ? absint( wp_unslash( $_GET['sslms_course_id'] ) ) : 0;
		$user_id   = isset( $_GET['sslms_user_id'] ) ? absint( wp_unslash( $_GET['sslms_user_id'] ) ) : 0;

		echo '<div class="wrap"><h1>LMS Reports</h1>';
		self::render_roster_section( $course_id );
		echo '<hr />';
		self::render_transcript_section( $user_id );
		echo '</div>';
	}

	private static function is_manager(): bool {
		return current_user_can( 'sslms_manage' );
	}

	private static function courses_for_picker(): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'courses' );

		if ( self::is_manager() ) {
			return $wpdb->get_results( "SELECT id, title FROM {$t} ORDER BY title ASC" );
		}

		$ids = array_map( 'intval', SSLMS_Enrollments::instructor_course_ids( get_current_user_id() ) );
		if ( ! $ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title FROM {$t} WHERE id IN ({$placeholders}) ORDER BY title ASC",
			$ids
		) );
	}

	private static function users_for_picker(): array {
		return get_users( array(
			'role__in' => array_keys( SSLMS_Roles::roles() ),
			'orderby'  => 'display_name',
			'fields'   => array( 'ID', 'display_name' ),
		) );
	}

	private static function rest_csv_url( string $path ): string {
		return add_query_arg(
			array(
				'format'   => 'csv',
				'_wpnonce' => wp_create_nonce( 'wp_rest' ),
			),
			rest_url( SSLMS_REST_Base::NS . '/' . $path )
		);
	}

	private static function render_roster_section( int $course_id ): void {
		$courses = self::courses_for_picker();

		echo '<h2>Course roster</h2>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="sslms-reports" />';
		echo '<select name="sslms_course_id">';
		echo '<option value="0">— Select a course —</option>';
		foreach ( $courses as $c ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $c->id,
				selected( $course_id, (int) $c->id, false ),
				esc_html( $c->title )
			);
		}
		echo '</select> <button class="button button-primary" type="submit">View roster</button>';
		echo '</form>';

		if ( ! $course_id ) {
			return;
		}

		if ( ! self::is_manager() ) {
			$owned = array_map( 'intval', SSLMS_Enrollments::instructor_course_ids( get_current_user_id() ) );
			if ( ! in_array( $course_id, $owned, true ) ) {
				echo '<p>' . esc_html__( 'You do not have access to this course.', 'sssihms-lms' ) . '</p>';
				return;
			}
		}

		global $wpdb;
		$enr_t      = SSLMS_DB::table( 'enrollments' );
		$profiles_t = SSLMS_DB::table( 'profiles' );
		$rows       = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.user_id, e.status, e.enrolled_at, e.completed_at, u.display_name AS name, p.staff_id
			 FROM {$enr_t} e
			 INNER JOIN {$wpdb->users} u ON u.ID = e.user_id
			 LEFT JOIN {$profiles_t} p ON p.user_id = e.user_id
			 WHERE e.course_id = %d
			 ORDER BY u.display_name ASC",
			$course_id
		) );

		echo '<p><a class="button" href="' . esc_url( self::rest_csv_url( 'reports/course/' . $course_id . '/roster' ) ) . '">Download CSV</a></p>';

		echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Staff ID</th><th>Status</th><th>%</th><th>Enrolled</th><th>Completed</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$pct = SSLMS_Progress::course_pct( $course_id, (int) $r->user_id );
			echo '<tr>'
				. '<td>' . esc_html( $r->name ) . '</td>'
				. '<td>' . esc_html( $r->staff_id ) . '</td>'
				. '<td>' . esc_html( $r->status ) . '</td>'
				. '<td>' . esc_html( $pct ) . '%</td>'
				. '<td>' . esc_html( SSLMS_DB::fmt_date( $r->enrolled_at ) ) . '</td>'
				. '<td>' . esc_html( SSLMS_DB::fmt_date( $r->completed_at ) ) . '</td>'
				. '</tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">No enrollments yet.</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_transcript_section( int $user_id ): void {
		$users = self::users_for_picker();

		echo '<h2>User transcript</h2>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="sslms-reports" />';
		echo '<select name="sslms_user_id">';
		echo '<option value="0">— Select a learner —</option>';
		foreach ( $users as $u ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $u->ID,
				selected( $user_id, (int) $u->ID, false ),
				esc_html( $u->display_name )
			);
		}
		echo '</select> <button class="button button-primary" type="submit">View transcript</button>';
		echo '</form>';

		if ( ! $user_id ) {
			return;
		}

		$viewer_id        = get_current_user_id();
		$scope_course_ids = null;
		if ( $viewer_id !== $user_id && ! self::is_manager() ) {
			$scope_course_ids = array_map( 'intval', SSLMS_Enrollments::instructor_course_ids( $viewer_id ) );
			if ( ! $scope_course_ids ) {
				echo '<p>' . esc_html__( 'No visible enrollments for this learner.', 'sssihms-lms' ) . '</p>';
				return;
			}
		}

		global $wpdb;
		$enr_t    = SSLMS_DB::table( 'enrollments' );
		$course_t = SSLMS_DB::table( 'courses' );

		if ( null !== $scope_course_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $scope_course_ids ), '%d' ) );
			$rows         = $wpdb->get_results( $wpdb->prepare(
				"SELECT e.id AS enrollment_id, e.course_id, e.status, e.enrolled_at, e.completed_at, co.title AS course_title
				 FROM {$enr_t} e
				 INNER JOIN {$course_t} co ON co.id = e.course_id
				 WHERE e.user_id = %d AND e.course_id IN ({$placeholders})
				 ORDER BY e.enrolled_at DESC",
				array_merge( array( $user_id ), $scope_course_ids )
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT e.id AS enrollment_id, e.course_id, e.status, e.enrolled_at, e.completed_at, co.title AS course_title
				 FROM {$enr_t} e
				 INNER JOIN {$course_t} co ON co.id = e.course_id
				 WHERE e.user_id = %d
				 ORDER BY e.enrolled_at DESC",
				$user_id
			) );
		}

		echo '<p><a class="button" href="' . esc_url( self::rest_csv_url( 'reports/user/' . $user_id . '/transcript' ) ) . '">Download CSV</a></p>';

		echo '<table class="widefat striped"><thead><tr><th>Course</th><th>Status</th><th>%</th><th>Enrolled</th><th>Completed</th><th>Certificate</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$pct  = SSLMS_Progress::course_pct( (int) $r->course_id, $user_id );
			$cert = SSLMS_Certificates::by_enrollment( (int) $r->enrollment_id );
			echo '<tr>'
				. '<td>' . esc_html( $r->course_title ) . '</td>'
				. '<td>' . esc_html( $r->status ) . '</td>'
				. '<td>' . esc_html( $pct ) . '%</td>'
				. '<td>' . esc_html( SSLMS_DB::fmt_date( $r->enrolled_at ) ) . '</td>'
				. '<td>' . esc_html( SSLMS_DB::fmt_date( $r->completed_at ) ) . '</td>'
				. '<td>' . esc_html( $cert ? $cert->cert_code : '—' ) . '</td>'
				. '</tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">No enrollments.</td></tr>';
		}
		echo '</tbody></table>';
	}
}
SSLMS_Admin_Reports::init();
