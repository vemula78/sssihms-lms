<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for branching scenarios (Phase 3e). Authoring/import:
 * sslms_author_courses. Playing: sslms_learn, own attempts only.
 */
class SSLMS_REST_Scenarios extends SSLMS_REST_Base {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {

		register_rest_route( self::NS, '/scenarios', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_scenarios' ),
				'permission_callback' => self::can_any( array( 'sslms_learn', 'sslms_author_courses' ) ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_scenario' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'title'       => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'discipline'  => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
					'description' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ),
					'pass_pct'    => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_pct' ), 'default' => 70 ),
				),
			),
		) );

		register_rest_route( self::NS, '/scenarios/import', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'import' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
			'args'                => array(
				'json' => array( 'required' => true, 'type' => 'string' ),
			),
		) );

		register_rest_route( self::NS, '/scenarios/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_scenario' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_scenario' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'id'          => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'title'       => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'discipline'  => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'description' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
					'pass_pct'    => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_pct' ) ),
					'status'      => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_status' ) ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_scenario' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
		) );

		register_rest_route( self::NS, '/scenarios/(?P<id>\d+)/nodes', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_node' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
			'args'                => array(
				'id'        => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'node_id'   => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ), 'default' => 0 ),
				'node_type' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_node_type' ), 'default' => 'decision' ),
				'title'     => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
				'body'      => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'wp_kses_post', 'default' => '' ),
				'debrief'   => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ),
				'is_start'  => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_bool_ish' ), 'default' => 0 ),
				'options'   => array( 'required' => false ),
			),
		) );

		register_rest_route( self::NS, '/scenarios/nodes/(?P<node_id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete_node' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
			'args'                => array( 'node_id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
		) );

		// Learner runtime.
		register_rest_route( self::NS, '/scenarios/(?P<id>\d+)/start', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'start' ),
			'permission_callback' => self::can( 'sslms_learn' ),
			'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
		) );

		register_rest_route( self::NS, '/scenarios/attempts/(?P<attempt_id>\d+)/choose', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'choose' ),
			'permission_callback' => self::can( 'sslms_learn' ),
			'args'                => array(
				'attempt_id'   => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'option_index' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ) ),
			),
		) );

		register_rest_route( self::NS, '/scenarios/attempts/mine', array(
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

	public static function is_pct( $value ): bool {
		return is_numeric( $value ) && (float) $value >= 0 && (float) $value <= 100;
	}

	public static function is_status( $value ): bool {
		return in_array( $value, array( 'draft', 'published' ), true );
	}

	public static function is_node_type( $value ): bool {
		return in_array( $value, SSLMS_Scenarios::NODE_TYPES, true );
	}

	/* Handlers */

	public static function list_scenarios(): WP_REST_Response {
		$published_only = ! current_user_can( 'sslms_author_courses' );
		return self::ok( SSLMS_Scenarios::list_scenarios( $published_only ) );
	}

	public static function get_scenario( WP_REST_Request $req ) {
		$scenario = SSLMS_Scenarios::get_scenario( (int) $req['id'] );
		if ( ! $scenario ) {
			return self::fail( 'sslms_not_found', 'Scenario not found.', 404 );
		}
		$scenario->nodes    = SSLMS_Scenarios::get_nodes( (int) $req['id'] );
		$scenario->problems = SSLMS_Scenarios::validate_graph( (int) $req['id'] );
		return self::ok( $scenario );
	}

	public static function create_scenario( WP_REST_Request $req ) {
		$result = SSLMS_Scenarios::create_scenario( array(
			'title'       => $req->get_param( 'title' ),
			'discipline'  => $req->get_param( 'discipline' ),
			'description' => $req->get_param( 'description' ),
			'pass_pct'    => $req->get_param( 'pass_pct' ),
		), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	public static function update_scenario( WP_REST_Request $req ) {
		$args = array();
		foreach ( array( 'title', 'discipline', 'description', 'pass_pct', 'status' ) as $f ) {
			if ( null !== $req->get_param( $f ) ) {
				$args[ $f ] = $req->get_param( $f );
			}
		}
		$result = SSLMS_Scenarios::update_scenario( (int) $req['id'], $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( SSLMS_Scenarios::get_scenario( (int) $req['id'] ) );
	}

	public static function delete_scenario( WP_REST_Request $req ) {
		$result = SSLMS_Scenarios::delete_scenario( (int) $req['id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	public static function import( WP_REST_Request $req ) {
		$result = SSLMS_Scenarios::import_json( (string) $req->get_param( 'json' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	public static function save_node( WP_REST_Request $req ) {
		$options = $req->get_param( 'options' );
		$args    = array(
			'node_type' => $req->get_param( 'node_type' ),
			'title'     => $req->get_param( 'title' ),
			'body'      => $req->get_param( 'body' ),
			'debrief'   => $req->get_param( 'debrief' ),
			'is_start'  => (bool) $req->get_param( 'is_start' ),
		);
		if ( is_array( $options ) ) {
			$args['options'] = $options;
		}
		$result = SSLMS_Scenarios::save_node( (int) $req['id'], $args, (int) $req->get_param( 'node_id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	public static function delete_node( WP_REST_Request $req ) {
		$result = SSLMS_Scenarios::delete_node( (int) $req['node_id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	public static function start( WP_REST_Request $req ) {
		$result = SSLMS_Scenarios::start_attempt( (int) $req['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'attempt_id' => $result ), 201 );
	}

	public static function choose( WP_REST_Request $req ) {
		$result = SSLMS_Scenarios::choose(
			(int) $req['attempt_id'],
			(int) $req->get_param( 'option_index' ),
			get_current_user_id()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( $result );
	}

	public static function mine(): WP_REST_Response {
		return self::ok( SSLMS_Scenarios::attempts_for_user( get_current_user_id() ) );
	}
}
SSLMS_REST_Scenarios::init();
