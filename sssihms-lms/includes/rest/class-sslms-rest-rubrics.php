<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for the rubrics engine + assessment records (Phase 3a).
 * Namespace sslms/v1. Object-level scoping lives in SSLMS_Rubrics —
 * capability checks here are the first layer only.
 */
class SSLMS_REST_Rubrics extends SSLMS_REST_Base {

	/** Any role that can be a source in multi-source assessment. */
	const ASSESSOR_CAPS = array( 'sslms_learn', 'sslms_signoff_checklists', 'sslms_mentor_journals', 'sslms_manage' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {

		// Rubric templates.
		register_rest_route( self::NS, '/rubrics', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_rubrics' ),
				'permission_callback' => self::can_any( self::ASSESSOR_CAPS ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_rubric' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'title'       => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'form_type'   => array( 'required' => false, 'type' => 'string', 'validate_callback' => array( __CLASS__, 'is_form_type' ), 'default' => 'custom' ),
					'discipline'  => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
					'description' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ),
					'pass_pct'    => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_pct' ), 'default' => 0 ),
				),
			),
		) );

		register_rest_route( self::NS, '/rubrics/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_rubric' ),
				'permission_callback' => self::can_any( self::ASSESSOR_CAPS ),
				'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_rubric' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'id'          => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'title'       => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'form_type'   => array( 'required' => false, 'type' => 'string', 'validate_callback' => array( __CLASS__, 'is_form_type' ) ),
					'discipline'  => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'description' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
					'pass_pct'    => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_pct' ) ),
					'is_active'   => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_bool_ish' ) ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_rubric' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
		) );

		// Criteria.
		register_rest_route( self::NS, '/rubrics/(?P<id>\d+)/criteria', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'add_criterion' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
			'args'                => array(
				'id'             => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'criterion_text' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'weight'         => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_numeric_pos' ), 'default' => 1 ),
				'is_critical'    => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_bool_ish' ), 'default' => 0 ),
			),
		) );

		register_rest_route( self::NS, '/rubrics/criteria/(?P<criterion_id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_criterion' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'criterion_id'   => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'criterion_text' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
					'weight'         => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_numeric_pos' ) ),
					'is_critical'    => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_bool_ish' ) ),
					'sort_order'     => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ) ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_criterion' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'criterion_id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
		) );

		// Levels.
		register_rest_route( self::NS, '/rubrics/criteria/(?P<criterion_id>\d+)/levels', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'add_level' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
			'args'                => array(
				'criterion_id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'label'        => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'descriptor'   => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ),
				'marks'        => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_numeric_nonneg' ) ),
			),
		) );

		register_rest_route( self::NS, '/rubrics/levels/(?P<level_id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_level' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'level_id'   => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'label'      => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'descriptor' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
					'marks'      => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_numeric_nonneg' ) ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_level' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'level_id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
		) );

		// Assessment records.
		register_rest_route( self::NS, '/assessments', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_records' ),
				'permission_callback' => self::can_any( self::ASSESSOR_CAPS ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'open_record' ),
				'permission_callback' => self::can_any( self::ASSESSOR_CAPS ),
				'args'                => array(
					'rubric_id'  => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'learner_id' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'context'    => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
				),
			),
		) );

		register_rest_route( self::NS, '/assessments/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_record' ),
				'permission_callback' => self::can_any( self::ASSESSOR_CAPS ),
				'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_record' ),
				'permission_callback' => self::can_any( self::ASSESSOR_CAPS ),
				'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
		) );

		register_rest_route( self::NS, '/assessments/(?P<id>\d+)/score', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'score' ),
			'permission_callback' => self::can_any( self::ASSESSOR_CAPS ),
			'args'                => array(
				'id'           => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'criterion_id' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'level_id'     => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'note'         => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ),
			),
		) );

		register_rest_route( self::NS, '/assessments/(?P<id>\d+)/sign', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'sign' ),
			'permission_callback' => self::can_any( self::ASSESSOR_CAPS ),
			'args'                => array(
				'id'       => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'feedback' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ),
			),
		) );

		register_rest_route( self::NS, '/assessments/(?P<id>\d+)/export', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'export_record' ),
			'permission_callback' => self::can_any( self::ASSESSOR_CAPS ),
			'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
		) );

		register_rest_route( self::NS, '/assessments/mine', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'mine' ),
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

	public static function is_form_type( $value ): bool {
		return in_array( $value, SSLMS_Rubrics::FORM_TYPES, true );
	}

	public static function is_pct( $value ): bool {
		return is_numeric( $value ) && (float) $value >= 0 && (float) $value <= 100;
	}

	public static function is_numeric_pos( $value ): bool {
		return is_numeric( $value ) && (float) $value > 0;
	}

	public static function is_numeric_nonneg( $value ): bool {
		return is_numeric( $value ) && (float) $value >= 0;
	}

	/* Rubric templates */

	public static function list_rubrics( WP_REST_Request $req ): WP_REST_Response {
		$active_only = ! current_user_can( 'sslms_author_courses' );
		return self::ok( SSLMS_Rubrics::list_rubrics( $active_only ) );
	}

	public static function get_rubric( WP_REST_Request $req ) {
		$rubric = SSLMS_Rubrics::get_full( (int) $req['id'] );
		if ( ! $rubric ) {
			return self::fail( 'sslms_not_found', 'Rubric not found.', 404 );
		}
		if ( ! $rubric->is_active && ! current_user_can( 'sslms_author_courses' ) ) {
			return self::fail( 'sslms_not_found', 'Rubric not found.', 404 );
		}
		return self::ok( $rubric );
	}

	public static function create_rubric( WP_REST_Request $req ) {
		$result = SSLMS_Rubrics::create_rubric( array(
			'title'       => $req->get_param( 'title' ),
			'form_type'   => $req->get_param( 'form_type' ),
			'discipline'  => $req->get_param( 'discipline' ),
			'description' => $req->get_param( 'description' ),
			'pass_pct'    => $req->get_param( 'pass_pct' ),
		), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	public static function update_rubric( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$args = array();
		foreach ( array( 'title', 'form_type', 'discipline', 'description', 'pass_pct', 'is_active' ) as $f ) {
			if ( null !== $req->get_param( $f ) ) {
				$args[ $f ] = $req->get_param( $f );
			}
		}
		SSLMS_Rubrics::update_rubric( $id, $args );
		return self::ok( SSLMS_Rubrics::get_full( $id ) );
	}

	public static function delete_rubric( WP_REST_Request $req ) {
		$result = SSLMS_Rubrics::delete_rubric( (int) $req['id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	/* Criteria + levels */

	public static function add_criterion( WP_REST_Request $req ) {
		if ( ! SSLMS_Rubrics::get_rubric( (int) $req['id'] ) ) {
			return self::fail( 'sslms_not_found', 'Rubric not found.', 404 );
		}
		$id = SSLMS_Rubrics::add_criterion(
			(int) $req['id'],
			$req->get_param( 'criterion_text' ),
			(float) $req->get_param( 'weight' ),
			(bool) $req->get_param( 'is_critical' )
		);
		return self::ok( array( 'id' => $id ), 201 );
	}

	public static function update_criterion( WP_REST_Request $req ) {
		$args = array();
		foreach ( array( 'criterion_text', 'weight', 'is_critical', 'sort_order' ) as $f ) {
			if ( null !== $req->get_param( $f ) ) {
				$args[ $f ] = $req->get_param( $f );
			}
		}
		SSLMS_Rubrics::update_criterion( (int) $req['criterion_id'], $args );
		return self::ok( array( 'updated' => true ) );
	}

	public static function delete_criterion( WP_REST_Request $req ) {
		$result = SSLMS_Rubrics::delete_criterion( (int) $req['criterion_id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	public static function add_level( WP_REST_Request $req ) {
		if ( ! SSLMS_DB::get_row( 'rubric_criteria', (int) $req['criterion_id'] ) ) {
			return self::fail( 'sslms_not_found', 'Criterion not found.', 404 );
		}
		$id = SSLMS_Rubrics::add_level(
			(int) $req['criterion_id'],
			$req->get_param( 'label' ),
			(string) $req->get_param( 'descriptor' ),
			(float) $req->get_param( 'marks' )
		);
		return self::ok( array( 'id' => $id ), 201 );
	}

	public static function update_level( WP_REST_Request $req ) {
		$args = array();
		foreach ( array( 'label', 'descriptor', 'marks' ) as $f ) {
			if ( null !== $req->get_param( $f ) ) {
				$args[ $f ] = $req->get_param( $f );
			}
		}
		SSLMS_Rubrics::update_level( (int) $req['level_id'], $args );
		return self::ok( array( 'updated' => true ) );
	}

	public static function delete_level( WP_REST_Request $req ) {
		$result = SSLMS_Rubrics::delete_level( (int) $req['level_id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	/* Assessment records */

	public static function list_records( WP_REST_Request $req ): WP_REST_Response {
		$uid        = get_current_user_id();
		$learner_id = (int) $req->get_param( 'learner_id' );
		if ( $learner_id && current_user_can( 'sslms_manage' ) ) {
			return self::ok( SSLMS_Rubrics::records_for_learner( $learner_id ) );
		}
		// Everyone else: records they authored.
		$status = (string) $req->get_param( 'status' );
		return self::ok( SSLMS_Rubrics::records_by_assessor( $uid, in_array( $status, array( 'draft', 'signed' ), true ) ? $status : '' ) );
	}

	public static function open_record( WP_REST_Request $req ) {
		$result = SSLMS_Rubrics::open_record(
			(int) $req->get_param( 'rubric_id' ),
			(int) $req->get_param( 'learner_id' ),
			get_current_user_id(),
			(string) $req->get_param( 'context' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	public static function get_record( WP_REST_Request $req ) {
		$record = SSLMS_Rubrics::get_record( (int) $req['id'] );
		if ( ! $record || ! SSLMS_Rubrics::user_can_view_record( $record, get_current_user_id() ) ) {
			return self::fail( 'sslms_not_found', 'Assessment not found.', 404 );
		}
		return self::ok( SSLMS_Rubrics::record_detail( (int) $req['id'] ) );
	}

	public static function delete_record( WP_REST_Request $req ) {
		$result = SSLMS_Rubrics::delete_record( (int) $req['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	public static function score( WP_REST_Request $req ) {
		$result = SSLMS_Rubrics::score_criterion(
			(int) $req['id'],
			(int) $req->get_param( 'criterion_id' ),
			(int) $req->get_param( 'level_id' ),
			(string) $req->get_param( 'note' ),
			get_current_user_id()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( $result );
	}

	public static function sign( WP_REST_Request $req ) {
		$result = SSLMS_Rubrics::sign_record(
			(int) $req['id'],
			(string) $req->get_param( 'feedback' ),
			get_current_user_id()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( $result );
	}

	public static function export_record( WP_REST_Request $req ) {
		$id     = (int) $req['id'];
		$record = SSLMS_Rubrics::get_record( $id );
		if ( ! $record || ! SSLMS_Rubrics::user_can_view_record( $record, get_current_user_id() ) ) {
			return self::fail( 'sslms_not_found', 'Assessment not found.', 404 );
		}
		self::send_csv(
			'assessment-' . $id . '.csv',
			array( 'Criterion', 'Level', 'Marks', 'Critical', 'Note' ),
			SSLMS_Rubrics::export_rows( $id )
		);
		return self::ok();
	}

	public static function mine(): WP_REST_Response {
		return self::ok( SSLMS_Rubrics::records_for_learner( get_current_user_id() ) );
	}
}
SSLMS_REST_Rubrics::init();
