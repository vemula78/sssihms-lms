<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for Module C: lesson completion + progress/completion reports
 * (SPEC F4.3).
 */
class SSLMS_REST_Progress extends SSLMS_REST_Base {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		$id_arg = array(
			'required'          => true,
			'validate_callback' => static function ( $value ) {
				return is_numeric( $value ) && (int) $value > 0;
			},
			'sanitize_callback' => 'absint',
		);
		$format_arg = array(
			'required'          => false,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static function ( $value ) {
				return '' === $value || 'csv' === $value;
			},
		);

		register_rest_route(
			self::NS,
			'/lessons/(?P<id>\d+)/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'complete_lesson' ),
				'permission_callback' => self::can( 'sslms_learn' ),
				'args'                => array( 'id' => $id_arg ),
			)
		);

		register_rest_route(
			self::NS,
			'/reports/course/(?P<id>\d+)/roster',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'course_roster' ),
				'permission_callback' => self::can( 'sslms_view_reports' ),
				'args'                => array(
					'id'     => $id_arg,
					'format' => $format_arg,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/reports/user/(?P<id>\d+)/transcript',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'user_transcript' ),
				'permission_callback' => array( __CLASS__, 'transcript_permission' ),
				'args'                => array(
					'id'     => $id_arg,
					'format' => $format_arg,
				),
			)
		);
	}

	public static function complete_lesson( WP_REST_Request $request ) {
		$lesson_id = (int) $request['id'];
		$result    = SSLMS_Progress::mark_lesson_complete( $lesson_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'lesson_id' => $lesson_id, 'complete' => true ) );
	}

	/** GET reports/course/{id}/roster — cap sslms_view_reports, scoped to own courses unless sslms_manage. */
	public static function course_roster( WP_REST_Request $request ) {
		$course_id = (int) $request['id'];

		if ( ! current_user_can( 'sslms_manage' ) ) {
			$owned = array_map( 'intval', SSLMS_Enrollments::instructor_course_ids( get_current_user_id() ) );
			if ( ! in_array( $course_id, $owned, true ) ) {
				return self::fail( 'sslms_forbidden', 'Not permitted to view this course roster.', 403 );
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

		$roster = array();
		foreach ( $rows as $row ) {
			$roster[] = array(
				'user_id'      => (int) $row->user_id,
				'name'         => (string) $row->name,
				'staff_id'     => (string) $row->staff_id,
				'status'       => (string) $row->status,
				'pct'          => SSLMS_Progress::course_pct( $course_id, (int) $row->user_id ),
				'enrolled_at'  => SSLMS_DB::fmt_date( $row->enrolled_at ),
				'completed_at' => SSLMS_DB::fmt_date( $row->completed_at ),
			);
		}

		if ( 'csv' === $request->get_param( 'format' ) ) {
			self::send_csv(
				'course-' . $course_id . '-roster.csv',
				array( 'Name', 'Staff ID', 'Status', 'Percent complete', 'Enrolled', 'Completed' ),
				array_map(
					static function ( $r ) {
						return array( $r['name'], $r['staff_id'], $r['status'], $r['pct'], $r['enrolled_at'], $r['completed_at'] );
					},
					$roster
				)
			);
		}

		return self::ok( $roster );
	}

	/** Own transcript always allowed; others require sslms_view_reports (scoped) or sslms_manage. */
	public static function transcript_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'sslms_forbidden', 'Not permitted.', array( 'status' => rest_authorization_required_code() ) );
		}
		if ( get_current_user_id() === (int) $request['id'] ) {
			return true;
		}
		if ( current_user_can( 'sslms_manage' ) || current_user_can( 'sslms_view_reports' ) ) {
			return true;
		}
		return new WP_Error( 'sslms_forbidden', 'Not permitted.', array( 'status' => rest_authorization_required_code() ) );
	}

	public static function user_transcript( WP_REST_Request $request ) {
		$target_id = (int) $request['id'];
		$viewer_id = get_current_user_id();
		$own       = ( $viewer_id === $target_id );
		$manage    = current_user_can( 'sslms_manage' );

		// Instructors (view_reports but not manage) requesting someone else's
		// transcript only see enrollments in their own courses.
		$scope_course_ids = null;
		if ( ! $own && ! $manage ) {
			$scope_course_ids = array_map( 'intval', SSLMS_Enrollments::instructor_course_ids( $viewer_id ) );
			if ( ! $scope_course_ids ) {
				return self::ok( array() );
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
				array_merge( array( $target_id ), $scope_course_ids )
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT e.id AS enrollment_id, e.course_id, e.status, e.enrolled_at, e.completed_at, co.title AS course_title
				 FROM {$enr_t} e
				 INNER JOIN {$course_t} co ON co.id = e.course_id
				 WHERE e.user_id = %d
				 ORDER BY e.enrolled_at DESC",
				$target_id
			) );
		}

		$transcript = array();
		foreach ( $rows as $row ) {
			$cert           = SSLMS_Certificates::by_enrollment( (int) $row->enrollment_id );
			$transcript[]   = array(
				'course_id'        => (int) $row->course_id,
				'course_title'     => (string) $row->course_title,
				'status'           => (string) $row->status,
				'pct'              => SSLMS_Progress::course_pct( (int) $row->course_id, $target_id ),
				'enrolled_at'      => SSLMS_DB::fmt_date( $row->enrolled_at ),
				'completed_at'     => SSLMS_DB::fmt_date( $row->completed_at ),
				'certificate_code' => $cert ? $cert->cert_code : '',
			);
		}

		if ( 'csv' === $request->get_param( 'format' ) ) {
			self::send_csv(
				'user-' . $target_id . '-transcript.csv',
				array( 'Course', 'Status', 'Percent complete', 'Enrolled', 'Completed', 'Certificate code' ),
				array_map(
					static function ( $r ) {
						return array( $r['course_title'], $r['status'], $r['pct'], $r['enrolled_at'], $r['completed_at'], $r['certificate_code'] );
					},
					$transcript
				)
			);
		}

		return self::ok( $transcript );
	}
}
SSLMS_REST_Progress::init();
