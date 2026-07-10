<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for skills checklists (F6). Namespace sslms/v1.
 */
class SSLMS_REST_Checklists extends SSLMS_REST_Base {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {

		// Templates.
		register_rest_route( self::NS, '/checklists', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_checklists' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_checklist' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'title'       => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'discipline'  => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
					'description' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ),
				),
			),
		) );

		register_rest_route( self::NS, '/checklists/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_checklist' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_checklist' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'id'          => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'title'       => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'discipline'  => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'description' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
					'is_active'   => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_bool_ish' ) ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_checklist' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
		) );

		// Items.
		register_rest_route( self::NS, '/checklists/(?P<id>\d+)/items', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'add_item' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
			'args'                => array(
				'id'         => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'item_text'  => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'sort_order' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ) ),
			),
		) );

		register_rest_route( self::NS, '/checklists/(?P<id>\d+)/items/reorder', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'reorder_items' ),
			'permission_callback' => self::can( 'sslms_author_courses' ),
			'args'                => array(
				'id'    => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'order' => array( 'required' => true ),
			),
		) );

		register_rest_route( self::NS, '/checklists/items/(?P<item_id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_item' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'item_id'    => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'item_text'  => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'sort_order' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint_or_zero' ) ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_item' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array( 'item_id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
		) );

		// Assignments.
		register_rest_route( self::NS, '/checklists/assignments', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_assignments' ),
				'permission_callback' => self::can( 'sslms_enroll_learners' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_assignment' ),
				'permission_callback' => self::can( 'sslms_enroll_learners' ),
				'args'                => array(
					'checklist_id' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'user_id'      => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				),
			),
		) );

		register_rest_route( self::NS, '/checklists/assignments/(?P<id>\d+)/sign', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'sign_item' ),
			'permission_callback' => self::can( 'sslms_signoff_checklists' ),
			'args'                => array(
				'id'      => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'item_id' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'rating'  => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_valid_rating' ) ),
				'note'    => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ),
			),
		) );

		register_rest_route( self::NS, '/checklists/assignments/(?P<id>\d+)/unlock', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'unlock' ),
			'permission_callback' => self::can( 'sslms_manage' ),
			'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
		) );

		register_rest_route( self::NS, '/checklists/assignments/(?P<id>\d+)/export', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'export_assignment' ),
			'permission_callback' => self::can_any( array( 'sslms_learn', 'sslms_signoff_checklists', 'sslms_manage' ) ),
			'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
		) );

		// Learner + preceptor worklists.
		register_rest_route( self::NS, '/checklists/mine', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'mine' ),
			'permission_callback' => self::can( 'sslms_learn' ),
		) );

		register_rest_route( self::NS, '/checklists/to-sign', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'to_sign' ),
			'permission_callback' => self::can( 'sslms_signoff_checklists' ),
		) );
	}

	/* ---------------------------------------------------------------
	 * Validators
	 * ------------------------------------------------------------- */

	public static function is_posint( $value ): bool {
		return is_numeric( $value ) && (int) $value > 0;
	}

	public static function is_posint_or_zero( $value ): bool {
		return is_numeric( $value ) && (int) $value >= 0;
	}

	public static function is_bool_ish( $value ): bool {
		return is_scalar( $value );
	}

	public static function is_valid_rating( $value ): bool {
		return in_array( $value, SSLMS_Checklists::RATINGS, true );
	}

	/* ---------------------------------------------------------------
	 * Templates
	 * ------------------------------------------------------------- */

	public static function list_checklists(): WP_REST_Response {
		$out = array();
		foreach ( SSLMS_Checklists::list_checklists() as $c ) {
			$c->items = SSLMS_Checklists::get_items( (int) $c->id );
			$out[] = $c;
		}
		return self::ok( $out );
	}

	public static function get_checklist( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$c  = SSLMS_Checklists::get_checklist( $id );
		if ( ! $c ) {
			return self::fail( 'sslms_not_found', 'Checklist not found.', 404 );
		}
		$c->items = SSLMS_Checklists::get_items( $id );
		return self::ok( $c );
	}

	public static function create_checklist( WP_REST_Request $req ): WP_REST_Response {
		$id = SSLMS_Checklists::create_checklist(
			$req->get_param( 'title' ),
			$req->get_param( 'discipline' ),
			$req->get_param( 'description' )
		);
		return self::ok( array( 'id' => $id ), 201 );
	}

	public static function update_checklist( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$args = array();
		foreach ( array( 'title', 'discipline', 'description', 'is_active' ) as $f ) {
			if ( null !== $req->get_param( $f ) ) {
				$args[ $f ] = $req->get_param( $f );
			}
		}
		SSLMS_Checklists::update_checklist( $id, $args );
		return self::ok( SSLMS_Checklists::get_checklist( $id ) );
	}

	public static function delete_checklist( WP_REST_Request $req ) {
		$result = SSLMS_Checklists::delete_checklist( (int) $req['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	/* ---------------------------------------------------------------
	 * Items
	 * ------------------------------------------------------------- */

	public static function add_item( WP_REST_Request $req ): WP_REST_Response {
		$sort = $req->get_param( 'sort_order' );
		$id   = SSLMS_Checklists::add_item(
			(int) $req['id'],
			$req->get_param( 'item_text' ),
			null === $sort ? null : (int) $sort
		);
		return self::ok( array( 'id' => $id ), 201 );
	}

	public static function update_item( WP_REST_Request $req ) {
		$args = array();
		foreach ( array( 'item_text', 'sort_order' ) as $f ) {
			if ( null !== $req->get_param( $f ) ) {
				$args[ $f ] = $req->get_param( $f );
			}
		}
		SSLMS_Checklists::update_item( (int) $req['item_id'], $args );
		return self::ok( array( 'updated' => true ) );
	}

	public static function delete_item( WP_REST_Request $req ) {
		$result = SSLMS_Checklists::delete_item( (int) $req['item_id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	public static function reorder_items( WP_REST_Request $req ) {
		$order = $req->get_param( 'order' );
		if ( ! is_array( $order ) ) {
			return self::fail( 'sslms_invalid_order', 'order must be an array of item ids.' );
		}
		SSLMS_Checklists::reorder_items( (int) $req['id'], array_map( 'absint', $order ) );
		return self::ok( SSLMS_Checklists::get_items( (int) $req['id'] ) );
	}

	/* ---------------------------------------------------------------
	 * Assignments
	 * ------------------------------------------------------------- */

	public static function list_assignments( WP_REST_Request $req ): WP_REST_Response {
		$checklist_id = (int) $req->get_param( 'checklist_id' );
		$user_id      = (int) $req->get_param( 'user_id' );
		if ( $checklist_id ) {
			$rows = SSLMS_Checklists::assignments_for_checklist( $checklist_id );
		} elseif ( $user_id ) {
			$rows = SSLMS_Checklists::assignments_for_user( $user_id );
		} else {
			$rows = array();
			foreach ( SSLMS_Checklists::list_checklists() as $c ) {
				$rows = array_merge( $rows, SSLMS_Checklists::assignments_for_checklist( (int) $c->id ) );
			}
		}
		foreach ( $rows as $row ) {
			$row->summary = SSLMS_Checklists::assignment_summary( (int) $row->id );
		}
		return self::ok( $rows );
	}

	public static function create_assignment( WP_REST_Request $req ) {
		$result = SSLMS_Checklists::assign(
			(int) $req->get_param( 'checklist_id' ),
			(int) $req->get_param( 'user_id' ),
			get_current_user_id()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	public static function sign_item( WP_REST_Request $req ) {
		$result = SSLMS_Checklists::sign_item(
			(int) $req['id'],
			(int) $req->get_param( 'item_id' ),
			$req->get_param( 'rating' ),
			(string) $req->get_param( 'note' ),
			get_current_user_id()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( $result );
	}

	public static function unlock( WP_REST_Request $req ) {
		$result = SSLMS_Checklists::unlock( (int) $req['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'unlocked' => true ) );
	}

	public static function export_assignment( WP_REST_Request $req ) {
		$id         = (int) $req['id'];
		$assignment = SSLMS_Checklists::get_assignment( $id );
		if ( ! $assignment ) {
			return self::fail( 'sslms_not_found', 'Assignment not found.', 404 );
		}
		$uid = get_current_user_id();
		$allowed = current_user_can( 'sslms_manage' )
			|| (int) $assignment->user_id === $uid
			|| SSLMS_Checklists::signer_in_scope( (int) $assignment->user_id, $uid );
		if ( ! $allowed ) {
			return self::fail( 'sslms_forbidden', 'Not permitted.', 403 );
		}
		$checklist = SSLMS_Checklists::get_checklist( (int) $assignment->checklist_id );
		$rows = SSLMS_Checklists::export_rows( $id );
		self::send_csv(
			'checklist-' . $id . '.csv',
			array( 'Item', 'Rating', 'Note', 'Signed by', 'Signed at' ),
			$rows
		);
		// send_csv() exits; nothing reached below, but WP_REST_Response type keeps IDEs happy.
		return self::ok( array( 'checklist' => $checklist ? $checklist->title : '' ) );
	}

	public static function mine(): WP_REST_Response {
		$uid  = get_current_user_id();
		$rows = SSLMS_Checklists::assignments_for_user( $uid );
		foreach ( $rows as $row ) {
			$row->items   = SSLMS_Checklists::items_status( (int) $row->id );
			$row->summary = SSLMS_Checklists::assignment_summary( (int) $row->id );
		}
		return self::ok( $rows );
	}

	public static function to_sign(): WP_REST_Response {
		$uid  = get_current_user_id();
		$rows = SSLMS_Checklists::learners_to_sign( $uid );
		foreach ( $rows as $row ) {
			$user           = get_userdata( (int) $row->user_id );
			$row->user_name = $user ? $user->display_name : ( 'User ' . $row->user_id );
			$row->items     = SSLMS_Checklists::items_status( (int) $row->id );
			$row->summary   = SSLMS_Checklists::assignment_summary( (int) $row->id );
		}
		return self::ok( $rows );
	}
}
SSLMS_REST_Checklists::init();
