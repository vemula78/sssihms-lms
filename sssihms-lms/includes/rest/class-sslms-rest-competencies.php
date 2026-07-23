<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for the competency framework + passport (Phase 3b).
 * Dictionary/mapping mutations: sslms_author_courses. Attainment sign-off:
 * sslms_manage (checked again in the module). Passport: own always;
 * another learner's only with sslms_manage.
 */
class SSLMS_REST_Competencies extends SSLMS_REST_Base {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {

		register_rest_route( self::NS, '/competencies', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_competencies' ),
				'permission_callback' => self::can_any( array( 'sslms_learn', 'sslms_author_courses', 'sslms_manage' ) ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_competency' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'title'       => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'parent_id'   => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ), 'default' => 0 ),
					'discipline'  => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
					'code'        => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
					'description' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ),
				),
			),
		) );

		register_rest_route( self::NS, '/competencies/seed-starter', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'seed_starter' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
		) );

		register_rest_route( self::NS, '/competencies/(?P<id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_competency' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'id'          => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'title'       => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'discipline'  => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'code'        => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'description' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
					'sort_order'  => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ) ),
					'is_active'   => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_bool_ish' ) ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_competency' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
		) );

		// Evidence mapping.
		register_rest_route( self::NS, '/competencies/(?P<id>\d+)/map', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'add_mapping' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
			'args'                => array(
				'id'          => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'object_type' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_object_type' ) ),
				'object_id'   => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
			),
		) );

		register_rest_route( self::NS, '/competencies/map/(?P<mapping_id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'remove_mapping' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
			'args'                => array( 'mapping_id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
		) );

		// Attainment sign-off (module re-checks sslms_manage).
		register_rest_route( self::NS, '/competencies/(?P<id>\d+)/attain', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'sign_attainment' ),
			'permission_callback' => self::can( 'sslms_manage' ),
			'args'                => array(
				'id'      => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'user_id' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'level'   => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_level' ) ),
				'note'    => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ),
			),
		) );

		// Passport.
		register_rest_route( self::NS, '/competencies/passport', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'passport' ),
			'permission_callback' => self::can_any( array( 'sslms_learn', 'sslms_manage' ) ),
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

	public static function is_object_type( $value ): bool {
		return in_array( $value, SSLMS_Competencies::OBJECT_TYPES, true );
	}

	public static function is_level( $value ): bool {
		return in_array( $value, SSLMS_Competencies::LEVELS, true );
	}

	/* Handlers */

	public static function list_competencies( WP_REST_Request $req ): WP_REST_Response {
		$discipline  = (string) $req->get_param( 'discipline' );
		$active_only = ! current_user_can( 'sslms_author_courses' );
		$rows        = SSLMS_Competencies::list_competencies( sanitize_text_field( $discipline ), $active_only );
		if ( current_user_can( 'sslms_author_courses' ) ) {
			foreach ( $rows as $row ) {
				$row->mappings = SSLMS_Competencies::get_mappings( (int) $row->id );
			}
		}
		return self::ok( $rows );
	}

	public static function create_competency( WP_REST_Request $req ) {
		$result = SSLMS_Competencies::create_competency( array(
			'title'       => $req->get_param( 'title' ),
			'parent_id'   => (int) $req->get_param( 'parent_id' ),
			'discipline'  => $req->get_param( 'discipline' ),
			'code'        => $req->get_param( 'code' ),
			'description' => $req->get_param( 'description' ),
		) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	public static function update_competency( WP_REST_Request $req ) {
		$args = array();
		foreach ( array( 'title', 'discipline', 'code', 'description', 'sort_order', 'is_active' ) as $f ) {
			if ( null !== $req->get_param( $f ) ) {
				$args[ $f ] = $req->get_param( $f );
			}
		}
		SSLMS_Competencies::update_competency( (int) $req['id'], $args );
		return self::ok( SSLMS_Competencies::get_competency( (int) $req['id'] ) );
	}

	public static function delete_competency( WP_REST_Request $req ) {
		$result = SSLMS_Competencies::delete_competency( (int) $req['id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	public static function seed_starter( WP_REST_Request $req ): WP_REST_Response {
		$created = SSLMS_Competencies::seed_starter_set( get_current_user_id() );
		return self::ok( array( 'created' => $created ) );
	}

	public static function add_mapping( WP_REST_Request $req ) {
		$result = SSLMS_Competencies::add_mapping(
			(int) $req['id'],
			(string) $req->get_param( 'object_type' ),
			(int) $req->get_param( 'object_id' ),
			get_current_user_id()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	public static function remove_mapping( WP_REST_Request $req ) {
		$result = SSLMS_Competencies::remove_mapping( (int) $req['mapping_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	public static function sign_attainment( WP_REST_Request $req ) {
		$result = SSLMS_Competencies::sign_attainment(
			(int) $req['id'],
			(int) $req->get_param( 'user_id' ),
			(string) $req->get_param( 'level' ),
			(string) $req->get_param( 'note' ),
			get_current_user_id()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	/** Own passport for learners; any learner's for sslms_manage. */
	public static function passport( WP_REST_Request $req ) {
		$uid    = get_current_user_id();
		$target = (int) $req->get_param( 'user_id' );
		if ( $target && $target !== $uid && ! current_user_can( 'sslms_manage' ) ) {
			return self::fail( 'sslms_forbidden', 'Not permitted.', 403 );
		}
		$discipline = sanitize_text_field( (string) $req->get_param( 'discipline' ) );
		return self::ok( SSLMS_Competencies::passport( $target ?: $uid, $discipline ) );
	}
}
SSLMS_REST_Competencies::init();
