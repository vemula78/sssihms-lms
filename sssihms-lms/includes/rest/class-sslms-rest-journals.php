<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for reflective journals (Module E). Every read path calls
 * SSLMS_Journals::user_can_read_entry() (directly, or via the scoped-SQL list
 * helpers) before any entry body reaches a response — no body is serialized
 * for a viewer who fails the gate.
 */
class SSLMS_REST_Journals extends SSLMS_REST_Base {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	private static function visibility_validate(): callable {
		return static function ( $value ) {
			return in_array( $value, SSLMS_Journals::VISIBILITIES, true );
		};
	}

	public static function register_routes(): void {

		register_rest_route( self::NS, '/journals', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_own' ),
				'permission_callback' => self::can( 'sslms_learn' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create' ),
				'permission_callback' => self::can( 'sslms_learn' ),
				'args'                => array(
					'title'      => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $v ) {
							return is_string( $v ) && '' !== trim( $v ) && mb_strlen( $v ) <= 200;
						},
					),
					'body'       => array(
						'required'          => true,
						'sanitize_callback' => 'wp_kses_post',
					),
					'visibility' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => self::visibility_validate(),
					),
				),
			),
		) );

		register_rest_route( self::NS, '/journals/shared-with-me', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'shared_with_me' ),
			'permission_callback' => self::can_any( array( 'sslms_mentor_journals', 'sslms_author_courses' ) ),
		) );

		register_rest_route( self::NS, '/journals/admin/counts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'admin_counts' ),
			'permission_callback' => self::can( 'sslms_manage' ),
		) );

		register_rest_route( self::NS, '/journals/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_entry' ),
				'permission_callback' => self::can( 'sslms_learn' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update' ),
				'permission_callback' => self::can( 'sslms_learn' ),
				'args'                => array(
					'title'      => array(
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $v ) {
							return is_string( $v ) && mb_strlen( $v ) <= 200;
						},
					),
					'body'       => array(
						'sanitize_callback' => 'wp_kses_post',
					),
					'visibility' => array(
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => self::visibility_validate(),
					),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete' ),
				'permission_callback' => self::can( 'sslms_learn' ),
			),
		) );

		register_rest_route( self::NS, '/journals/(?P<id>\d+)/comments', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_comments' ),
				'permission_callback' => self::can( 'sslms_learn' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'add_comment' ),
				'permission_callback' => self::can( 'sslms_learn' ),
				'args'                => array(
					'body' => array(
						'required'          => true,
						'sanitize_callback' => 'wp_kses_post',
					),
				),
			),
		) );
	}

	public static function list_own( WP_REST_Request $request ) {
		return self::ok( SSLMS_Journals::list_own( get_current_user_id() ) );
	}

	public static function create( WP_REST_Request $request ) {
		$id = SSLMS_Journals::create_entry(
			get_current_user_id(),
			(string) $request->get_param( 'title' ),
			(string) $request->get_param( 'body' ),
			(string) $request->get_param( 'visibility' )
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::ok( array( 'id' => $id ), 201 );
	}

	public static function shared_with_me( WP_REST_Request $request ) {
		return self::ok( SSLMS_Journals::list_shared_with( get_current_user_id() ) );
	}

	public static function admin_counts( WP_REST_Request $request ) {
		return self::ok( SSLMS_Journals::admin_counts() );
	}

	/** GET single entry — gated; never returns a body the viewer can't read. */
	public static function get_entry( WP_REST_Request $request ) {
		$entry = SSLMS_Journals::get_for_viewer( (int) $request['id'], get_current_user_id() );
		if ( null === $entry ) {
			return self::fail( 'sslms_not_found', 'Entry not found.', 404 );
		}
		if ( false === $entry ) {
			return self::fail( 'sslms_forbidden', 'Not permitted.', 403 );
		}
		return self::ok( $entry );
	}

	public static function update( WP_REST_Request $request ) {
		$data = array();
		foreach ( array( 'title', 'body', 'visibility' ) as $field ) {
			$v = $request->get_param( $field );
			if ( null !== $v ) {
				$data[ $field ] = $v;
			}
		}
		$result = SSLMS_Journals::update_entry( (int) $request['id'], get_current_user_id(), $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( true );
	}

	public static function delete( WP_REST_Request $request ) {
		$result = SSLMS_Journals::delete_entry( (int) $request['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( true );
	}

	/** List comments — gated identically to the entry (module enforces this). */
	public static function list_comments( WP_REST_Request $request ) {
		$comments = SSLMS_Journals::list_comments( (int) $request['id'], get_current_user_id() );
		if ( is_wp_error( $comments ) ) {
			return $comments;
		}
		return self::ok( $comments );
	}

	public static function add_comment( WP_REST_Request $request ) {
		$id = SSLMS_Journals::add_comment(
			(int) $request['id'],
			get_current_user_id(),
			(string) $request->get_param( 'body' )
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::ok( array( 'id' => $id ), 201 );
	}
}
SSLMS_REST_Journals::init();
