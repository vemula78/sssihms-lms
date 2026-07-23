<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for OSPE exams (Phase 3c). Exam/station authoring:
 * sslms_author_courses. Candidates: sslms_enroll_learners. Scoring:
 * sslms_signoff_checklists capability + assigned-examiner object check in
 * the module. Moderation/absent/publish: sslms_manage.
 */
class SSLMS_REST_OSPE extends SSLMS_REST_Base {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {

		register_rest_route( self::NS, '/ospe', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_exams' ),
				'permission_callback' => self::can_any( array( 'sslms_author_courses', 'sslms_signoff_checklists', 'sslms_manage' ) ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_exam' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'title'           => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'discipline'      => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
					'exam_date'       => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_date' ) ),
					'pass_pct'        => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_pct' ), 'default' => 50 ),
					'min_station_pct' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_pct' ), 'default' => 0 ),
				),
			),
		) );

		register_rest_route( self::NS, '/ospe/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_exam' ),
				'permission_callback' => self::can_any( array( 'sslms_author_courses', 'sslms_signoff_checklists', 'sslms_manage' ) ),
				'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_exam' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'id'              => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'title'           => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'discipline'      => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'exam_date'       => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_date' ) ),
					'pass_pct'        => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_pct' ) ),
					'min_station_pct' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_pct' ) ),
					'status'          => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_editable_status' ) ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_exam' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
		) );

		register_rest_route( self::NS, '/ospe/(?P<id>\d+)/stations', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'add_station' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
			'args'                => array(
				'id'           => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'title'        => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'station_type' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_station_type' ), 'default' => 'procedure' ),
				'duration_min' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint' ), 'default' => 5 ),
				'max_marks'    => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_nonneg' ), 'default' => 10 ),
				'rubric_id'    => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ), 'default' => 0 ),
				'examiner_id'  => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ), 'default' => 0 ),
			),
		) );

		register_rest_route( self::NS, '/ospe/stations/(?P<station_id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_station' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'station_id'   => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'title'        => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'station_type' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_station_type' ) ),
					'duration_min' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'max_marks'    => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_nonneg' ) ),
					'rubric_id'    => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ) ),
					'examiner_id'  => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ) ),
					'station_no'   => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_station' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'station_id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
		) );

		register_rest_route( self::NS, '/ospe/(?P<id>\d+)/candidates', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'add_candidate' ),
			'permission_callback' => self::can( 'sslms_enroll_learners' ),
			'args'                => array(
				'id'      => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'user_id' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
			),
		) );

		register_rest_route( self::NS, '/ospe/candidates/(?P<candidate_id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'remove_candidate' ),
			'permission_callback' => self::can( 'sslms_enroll_learners' ),
			'args'                => array( 'candidate_id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
		) );

		register_rest_route( self::NS, '/ospe/candidates/(?P<candidate_id>\d+)/absent', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'mark_absent' ),
			'permission_callback' => self::can( 'sslms_manage' ),
			'args'                => array( 'candidate_id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
		) );

		register_rest_route( self::NS, '/ospe/stations/(?P<station_id>\d+)/score', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'score' ),
			'permission_callback' => self::can_any( array( 'sslms_signoff_checklists', 'sslms_manage' ) ),
			'args'                => array(
				'station_id'   => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'candidate_id' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'marks'        => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_nonneg' ) ),
				'note'         => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ),
				'moderation'   => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_bool_ish' ), 'default' => 0 ),
			),
		) );

		register_rest_route( self::NS, '/ospe/(?P<id>\d+)/publish', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'publish' ),
			'permission_callback' => self::can( 'sslms_manage' ),
			'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
		) );

		register_rest_route( self::NS, '/ospe/(?P<id>\d+)/export', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'export_exam' ),
			'permission_callback' => self::can_any( array( 'sslms_author_courses', 'sslms_manage' ) ),
			'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
		) );

		register_rest_route( self::NS, '/ospe/my-results', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'my_results' ),
			'permission_callback' => self::can( 'sslms_learn' ),
		) );
	}

	/* Validators */

	public static function is_posint( $value ): bool {
		return is_numeric( $value ) && (int) $value > 0;
	}

	public static function is_posint_or_zero( $value ): bool {
		return is_numeric( $value ) && (int) $value >= 0;
	}

	public static function is_bool_ish( $value ): bool {
		return is_scalar( $value );
	}

	public static function is_pct( $value ): bool {
		return is_numeric( $value ) && (float) $value >= 0 && (float) $value <= 100;
	}

	public static function is_nonneg( $value ): bool {
		return is_numeric( $value ) && (float) $value >= 0;
	}

	public static function is_date( $value ): bool {
		return is_string( $value ) && ( '' === $value || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) );
	}

	public static function is_station_type( $value ): bool {
		return in_array( $value, SSLMS_OSPE::STATION_TYPES, true );
	}

	public static function is_editable_status( $value ): bool {
		return in_array( $value, array( 'draft', 'scheduled' ), true );
	}

	/* Handlers */

	public static function list_exams(): WP_REST_Response {
		return self::ok( SSLMS_OSPE::list_exams() );
	}

	public static function get_exam( WP_REST_Request $req ) {
		$exam = SSLMS_OSPE::get_exam( (int) $req['id'] );
		if ( ! $exam ) {
			return self::fail( 'sslms_not_found', 'Exam not found.', 404 );
		}
		$exam->stations   = SSLMS_OSPE::get_stations( (int) $req['id'] );
		$exam->candidates = SSLMS_OSPE::get_candidates( (int) $req['id'] );
		return self::ok( $exam );
	}

	public static function create_exam( WP_REST_Request $req ) {
		$result = SSLMS_OSPE::create_exam( array(
			'title'           => $req->get_param( 'title' ),
			'discipline'      => $req->get_param( 'discipline' ),
			'exam_date'       => $req->get_param( 'exam_date' ),
			'pass_pct'        => $req->get_param( 'pass_pct' ),
			'min_station_pct' => $req->get_param( 'min_station_pct' ),
		), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	public static function update_exam( WP_REST_Request $req ) {
		$args = array();
		foreach ( array( 'title', 'discipline', 'exam_date', 'pass_pct', 'min_station_pct', 'status' ) as $f ) {
			if ( null !== $req->get_param( $f ) ) {
				$args[ $f ] = $req->get_param( $f );
			}
		}
		$result = SSLMS_OSPE::update_exam( (int) $req['id'], $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( SSLMS_OSPE::get_exam( (int) $req['id'] ) );
	}

	public static function delete_exam( WP_REST_Request $req ) {
		$result = SSLMS_OSPE::delete_exam( (int) $req['id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	public static function add_station( WP_REST_Request $req ) {
		$result = SSLMS_OSPE::add_station( (int) $req['id'], array(
			'title'        => $req->get_param( 'title' ),
			'station_type' => $req->get_param( 'station_type' ),
			'duration_min' => $req->get_param( 'duration_min' ),
			'max_marks'    => $req->get_param( 'max_marks' ),
			'rubric_id'    => $req->get_param( 'rubric_id' ),
			'examiner_id'  => $req->get_param( 'examiner_id' ),
		) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	public static function update_station( WP_REST_Request $req ) {
		$args = array();
		foreach ( array( 'title', 'station_type', 'duration_min', 'max_marks', 'rubric_id', 'examiner_id', 'station_no' ) as $f ) {
			if ( null !== $req->get_param( $f ) ) {
				$args[ $f ] = $req->get_param( $f );
			}
		}
		$result = SSLMS_OSPE::update_station( (int) $req['station_id'], $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'updated' => true ) );
	}

	public static function delete_station( WP_REST_Request $req ) {
		$result = SSLMS_OSPE::delete_station( (int) $req['station_id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	public static function add_candidate( WP_REST_Request $req ) {
		$result = SSLMS_OSPE::add_candidate( (int) $req['id'], (int) $req->get_param( 'user_id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	public static function remove_candidate( WP_REST_Request $req ) {
		$result = SSLMS_OSPE::remove_candidate( (int) $req['candidate_id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	public static function mark_absent( WP_REST_Request $req ) {
		$result = SSLMS_OSPE::mark_absent( (int) $req['candidate_id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'updated' => true ) );
	}

	public static function score( WP_REST_Request $req ) {
		$result = SSLMS_OSPE::score(
			(int) $req['station_id'],
			(int) $req->get_param( 'candidate_id' ),
			(float) $req->get_param( 'marks' ),
			(string) $req->get_param( 'note' ),
			get_current_user_id(),
			(bool) $req->get_param( 'moderation' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'saved' => true ) );
	}

	public static function publish( WP_REST_Request $req ) {
		$result = SSLMS_OSPE::publish( (int) $req['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'published' => true ) );
	}

	public static function export_exam( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$exam = SSLMS_OSPE::get_exam( $id );
		if ( ! $exam ) {
			return self::fail( 'sslms_not_found', 'Exam not found.', 404 );
		}
		$rows = SSLMS_OSPE::export_rows( $id );
		self::send_csv( 'ospe-' . $id . '.csv', $rows ? array_keys( $rows[0] ) : array( 'Candidate #' ), $rows );
		return self::ok();
	}

	public static function my_results(): WP_REST_Response {
		return self::ok( SSLMS_OSPE::results_for_user( get_current_user_id() ) );
	}
}
SSLMS_REST_OSPE::init();
