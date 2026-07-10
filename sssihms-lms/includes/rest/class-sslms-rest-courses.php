<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module A — REST routes: authoring CRUD for courses/modules/lessons
 * (cap sslms_author_courses; instructors may only edit courses they
 * created, unless sslms_manage), enrolment (sslms_enroll_learners) and
 * self-enrol (sslms_learn) for open courses, and module/lesson reordering.
 */
class SSLMS_REST_Courses extends SSLMS_REST_Base {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	/* -----------------------------------------------------------------
	 * Validators
	 * ------------------------------------------------------------- */

	public static function is_posint( $value ): bool {
		return is_numeric( $value ) && (int) $value > 0;
	}

	public static function is_posint_or_zero( $value ): bool {
		return is_numeric( $value ) && (int) $value >= 0;
	}

	public static function is_valid_track( $value ): bool {
		return in_array( $value, SSLMS_Courses::TRACKS, true );
	}

	public static function is_valid_status( $value ): bool {
		return in_array( $value, SSLMS_Courses::STATUSES, true );
	}

	public static function is_bool_ish( $value ): bool {
		return is_scalar( $value );
	}

	public static function is_id_array( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $v ) {
			if ( ! is_numeric( $v ) ) {
				return false;
			}
		}
		return true;
	}

	/* -----------------------------------------------------------------
	 * Route registration
	 * ------------------------------------------------------------- */

	public static function register(): void {
		$id_arg = array(
			'required'          => true,
			'validate_callback' => array( __CLASS__, 'is_posint' ),
		);

		/* Courses --------------------------------------------------- */

		register_rest_route( self::NS, '/courses', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_courses' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_course' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'title'           => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'description'     => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
					'track'           => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_valid_track' ) ),
					'status'          => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_valid_status' ) ),
					'open_enrollment' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_bool_ish' ) ),
				),
			),
		) );

		register_rest_route( self::NS, '/courses/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_course' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'id' => $id_arg ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_course' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'id'              => $id_arg,
					'title'           => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'description'     => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
					'track'           => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_valid_track' ) ),
					'status'          => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_valid_status' ) ),
					'open_enrollment' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_bool_ish' ) ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_course' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'id' => $id_arg ),
			),
		) );

		/* Modules ----------------------------------------------------- */

		register_rest_route( self::NS, '/courses/(?P<id>\d+)/modules', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_module' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
			'args'                => array(
				'id'         => $id_arg,
				'title'      => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'sort_order' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ) ),
			),
		) );

		register_rest_route( self::NS, '/courses/(?P<id>\d+)/modules/reorder', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'reorder_modules' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
			'args'                => array(
				'id'    => $id_arg,
				'order' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_id_array' ) ),
			),
		) );

		register_rest_route( self::NS, '/modules/(?P<id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_module' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'id'         => $id_arg,
					'title'      => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'sort_order' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ) ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_module' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'id' => $id_arg ),
			),
		) );

		/* Lessons ------------------------------------------------------ */

		register_rest_route( self::NS, '/modules/(?P<id>\d+)/lessons', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_lesson' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
			'args'                => self::lesson_args( $id_arg, true ),
		) );

		register_rest_route( self::NS, '/modules/(?P<id>\d+)/lessons/reorder', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'reorder_lessons' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
			'args'                => array(
				'id'    => $id_arg,
				'order' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_id_array' ) ),
			),
		) );

		register_rest_route( self::NS, '/lessons/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_lesson' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'id' => $id_arg ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_lesson' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => self::lesson_args( $id_arg, false ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_lesson' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'id' => $id_arg ),
			),
		) );

		/* Enrolment ----------------------------------------------------- */

		register_rest_route( self::NS, '/courses/(?P<id>\d+)/enrollments', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'roster' ),
				'permission_callback' => self::can( 'sslms_enroll_learners' ),
				'args'                => array( 'id' => $id_arg ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'enroll' ),
				'permission_callback' => self::can( 'sslms_enroll_learners' ),
				'args'                => array(
					'id'      => $id_arg,
					'user_id' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				),
			),
		) );

		register_rest_route( self::NS, '/courses/(?P<id>\d+)/enrollments/(?P<user_id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'withdraw' ),
			'permission_callback' => self::can( 'sslms_enroll_learners' ),
			'args'                => array(
				'id'      => $id_arg,
				'user_id' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
			),
		) );

		register_rest_route( self::NS, '/courses/(?P<id>\d+)/self-enroll', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'self_enroll' ),
			'permission_callback' => self::can( 'sslms_learn' ),
			'args'                => array( 'id' => $id_arg ),
		) );
	}

	private static function lesson_args( array $id_arg, bool $title_required ): array {
		return array(
			'id'          => $id_arg,
			'title'       => array( 'required' => $title_required, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'content'     => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'wp_kses_post' ),
			'video_url'   => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
			'attachments' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_id_array' ) ),
			'est_minutes' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ) ),
			'sort_order'  => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ) ),
		);
	}

	/** True when the current user may manage $course_id (owner or sslms_manage). */
	private static function forbidden_unless_owner( int $course_id ) {
		if ( ! SSLMS_Courses::can_edit( $course_id, get_current_user_id() ) ) {
			return self::fail( 'sslms_forbidden', 'You may only manage courses you created.', 403 );
		}
		return null;
	}

	/* -----------------------------------------------------------------
	 * Courses
	 * ------------------------------------------------------------- */

	public static function list_courses(): WP_REST_Response {
		$uid  = get_current_user_id();
		$args = current_user_can( 'sslms_manage' ) ? array() : array( 'created_by' => $uid );
		return self::ok( SSLMS_Courses::list_courses( $args ) );
	}

	public static function create_course( WP_REST_Request $req ) {
		$id = SSLMS_Courses::create(
			array(
				'title'           => $req->get_param( 'title' ),
				'description'     => $req->get_param( 'description' ),
				'track'           => $req->get_param( 'track' ),
				'status'          => $req->get_param( 'status' ),
				'open_enrollment' => $req->get_param( 'open_enrollment' ),
			),
			get_current_user_id()
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::ok( array( 'id' => $id ), 201 );
	}

	public static function get_course( WP_REST_Request $req ) {
		$course_id = (int) $req['id'];
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		$course = SSLMS_Courses::get( $course_id );
		if ( ! $course ) {
			return self::fail( 'sslms_not_found', 'Course not found.', 404 );
		}
		$course->enrolled_count = SSLMS_Courses::enrolled_count( $course_id );
		return self::ok( $course );
	}

	public static function update_course( WP_REST_Request $req ) {
		$course_id = (int) $req['id'];
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		$data = array();
		foreach ( array( 'title', 'description', 'track', 'status', 'open_enrollment' ) as $f ) {
			if ( null !== $req->get_param( $f ) ) {
				$data[ $f ] = $req->get_param( $f );
			}
		}
		$result = SSLMS_Courses::update( $course_id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( SSLMS_Courses::get( $course_id ) );
	}

	public static function delete_course( WP_REST_Request $req ) {
		$course_id = (int) $req['id'];
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		$result = SSLMS_Courses::delete( $course_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	/* -----------------------------------------------------------------
	 * Modules
	 * ------------------------------------------------------------- */

	public static function create_module( WP_REST_Request $req ) {
		$course_id = (int) $req['id'];
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		$sort = $req->get_param( 'sort_order' );
		$id   = SSLMS_Courses::create_module( $course_id, $req->get_param( 'title' ), null === $sort ? null : (int) $sort );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::ok( array( 'id' => $id ), 201 );
	}

	public static function update_module( WP_REST_Request $req ) {
		$module_id = (int) $req['id'];
		$course_id = SSLMS_Courses::course_id_for_module( $module_id );
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		$data = array();
		foreach ( array( 'title', 'sort_order' ) as $f ) {
			if ( null !== $req->get_param( $f ) ) {
				$data[ $f ] = $req->get_param( $f );
			}
		}
		$result = SSLMS_Courses::update_module( $module_id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'updated' => true ) );
	}

	public static function delete_module( WP_REST_Request $req ) {
		$module_id = (int) $req['id'];
		$course_id = SSLMS_Courses::course_id_for_module( $module_id );
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		$result = SSLMS_Courses::delete_module( $module_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	public static function reorder_modules( WP_REST_Request $req ) {
		$course_id = (int) $req['id'];
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		SSLMS_Courses::reorder_modules( $course_id, array_map( 'absint', (array) $req->get_param( 'order' ) ) );
		return self::ok( SSLMS_Courses::modules( $course_id ) );
	}

	/* -----------------------------------------------------------------
	 * Lessons
	 * ------------------------------------------------------------- */

	public static function create_lesson( WP_REST_Request $req ) {
		$module_id = (int) $req['id'];
		$course_id = SSLMS_Courses::course_id_for_module( $module_id );
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		$id = SSLMS_Courses::create_lesson( $module_id, self::lesson_payload( $req ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::ok( array( 'id' => $id ), 201 );
	}

	public static function get_lesson( WP_REST_Request $req ) {
		$lesson_id = (int) $req['id'];
		$course_id = SSLMS_Courses::course_id_for_lesson( $lesson_id );
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		$lesson = SSLMS_Courses::get_lesson( $lesson_id );
		if ( ! $lesson ) {
			return self::fail( 'sslms_not_found', 'Lesson not found.', 404 );
		}
		return self::ok( $lesson );
	}

	public static function update_lesson( WP_REST_Request $req ) {
		$lesson_id = (int) $req['id'];
		$course_id = SSLMS_Courses::course_id_for_lesson( $lesson_id );
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		$result = SSLMS_Courses::update_lesson( $lesson_id, self::lesson_payload( $req ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( SSLMS_Courses::get_lesson( $lesson_id ) );
	}

	public static function delete_lesson( WP_REST_Request $req ) {
		$lesson_id = (int) $req['id'];
		$course_id = SSLMS_Courses::course_id_for_lesson( $lesson_id );
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		$result = SSLMS_Courses::delete_lesson( $lesson_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	public static function reorder_lessons( WP_REST_Request $req ) {
		$module_id = (int) $req['id'];
		$course_id = SSLMS_Courses::course_id_for_module( $module_id );
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		SSLMS_Courses::reorder_lessons( $module_id, array_map( 'absint', (array) $req->get_param( 'order' ) ) );
		return self::ok( SSLMS_Courses::lessons( $module_id ) );
	}

	/** Only includes params actually present on the request (partial update on PUT). */
	private static function lesson_payload( WP_REST_Request $req ): array {
		$data = array();
		foreach ( array( 'title', 'content', 'video_url', 'attachments', 'est_minutes', 'sort_order' ) as $f ) {
			if ( null !== $req->get_param( $f ) ) {
				$data[ $f ] = $req->get_param( $f );
			}
		}
		return $data;
	}

	/* -----------------------------------------------------------------
	 * Enrolment
	 * ------------------------------------------------------------- */

	public static function roster( WP_REST_Request $req ) {
		$course_id = (int) $req['id'];
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		return self::ok( SSLMS_Enrollments::for_course( $course_id ) );
	}

	public static function enroll( WP_REST_Request $req ) {
		$course_id = (int) $req['id'];
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		$result = SSLMS_Enrollments::enroll( $course_id, (int) $req->get_param( 'user_id' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	public static function withdraw( WP_REST_Request $req ) {
		$course_id = (int) $req['id'];
		$err       = self::forbidden_unless_owner( $course_id );
		if ( $err ) {
			return $err;
		}
		$result = SSLMS_Enrollments::withdraw( $course_id, (int) $req['user_id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'withdrawn' => true ) );
	}

	public static function self_enroll( WP_REST_Request $req ) {
		$result = SSLMS_Enrollments::self_enrol( (int) $req['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}
}
SSLMS_REST_Courses::init();
