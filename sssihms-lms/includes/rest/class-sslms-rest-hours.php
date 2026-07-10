<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for clinical hours / rotation tracking (F7). Namespace sslms/v1.
 */
class SSLMS_REST_Hours extends SSLMS_REST_Base {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {

		// Rotations.
		register_rest_route( self::NS, '/rotations', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_rotations' ),
				'permission_callback' => self::can( 'sslms_enroll_learners' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_rotation' ),
				'permission_callback' => self::can( 'sslms_enroll_learners' ),
				'args'                => array(
					'user_id'        => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'department'     => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'start_date'     => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_date' ) ),
					'end_date'       => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_date' ) ),
					'preceptor_id'   => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'required_hours' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_nonneg_number' ) ),
				),
			),
		) );

		register_rest_route( self::NS, '/rotations/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_rotation' ),
				'permission_callback' => self::can( 'sslms_enroll_learners' ),
				'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_rotation' ),
				'permission_callback' => self::can( 'sslms_enroll_learners' ),
				'args'                => array(
					'id'             => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'department'     => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'start_date'     => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_date' ) ),
					'end_date'       => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_date' ) ),
					'preceptor_id'   => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'required_hours' => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_nonneg_number' ) ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_rotation' ),
				'permission_callback' => self::can( 'sslms_enroll_learners' ),
				'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
		) );

		// Learner: own rotations + logs.
		register_rest_route( self::NS, '/hours/mine', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'mine' ),
			'permission_callback' => self::can( 'sslms_learn' ),
		) );

		register_rest_route( self::NS, '/hours/logs', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_log' ),
			'permission_callback' => self::can( 'sslms_learn' ),
			'args'                => array(
				'rotation_id' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'date'        => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_date' ) ),
				'hours'       => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_nonneg_number' ) ),
				'activity'    => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
			),
		) );

		register_rest_route( self::NS, '/hours/logs/(?P<id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_log' ),
				'permission_callback' => self::can( 'sslms_learn' ),
				'args'                => array(
					'id'       => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
					'date'     => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_date' ) ),
					'hours'    => array( 'required' => false, 'validate_callback' => array( __CLASS__, 'is_nonneg_number' ) ),
					'activity' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_log' ),
				'permission_callback' => self::can( 'sslms_learn' ),
				'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
			),
		) );

		register_rest_route( self::NS, '/hours/logs/(?P<id>\d+)/review', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'review_log' ),
			'permission_callback' => self::can( 'sslms_approve_hours' ),
			'args'                => array(
				'id'     => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ),
				'status' => array( 'required' => true, 'validate_callback' => array( __CLASS__, 'is_review_status' ) ),
				'note'   => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ),
			),
		) );

		register_rest_route( self::NS, '/hours/pending-approvals', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'pending_approvals' ),
			'permission_callback' => self::can( 'sslms_approve_hours' ),
		) );

		register_rest_route( self::NS, '/hours/export/learner/(?P<user_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'export_learner' ),
			'permission_callback' => self::can_any( array( 'sslms_learn', 'sslms_approve_hours', 'sslms_view_reports', 'sslms_manage' ) ),
			'args'                => array( 'user_id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
		) );

		register_rest_route( self::NS, '/hours/export/rotation/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'export_rotation' ),
			'permission_callback' => self::can_any( array( 'sslms_learn', 'sslms_approve_hours', 'sslms_view_reports', 'sslms_manage' ) ),
			'args'                => array( 'id' => array( 'validate_callback' => array( __CLASS__, 'is_posint' ) ) ),
		) );
	}

	/* ---------------------------------------------------------------
	 * Validators
	 * ------------------------------------------------------------- */

	public static function is_posint( $value ): bool {
		return is_numeric( $value ) && (int) $value > 0;
	}

	public static function is_nonneg_number( $value ): bool {
		return is_numeric( $value ) && (float) $value >= 0;
	}

	public static function is_date( $value ): bool {
		return is_string( $value ) && false !== strtotime( $value );
	}

	public static function is_review_status( $value ): bool {
		return in_array( $value, array( 'approve', 'reject' ), true );
	}

	/* ---------------------------------------------------------------
	 * Rotations
	 * ------------------------------------------------------------- */

	public static function list_rotations( WP_REST_Request $req ): WP_REST_Response {
		$args = array();
		if ( $req->get_param( 'user_id' ) ) {
			$args['user_id'] = (int) $req->get_param( 'user_id' );
		}
		if ( $req->get_param( 'preceptor_id' ) ) {
			$args['preceptor_id'] = (int) $req->get_param( 'preceptor_id' );
		}
		$rows = SSLMS_Hours::list_rotations( $args );
		foreach ( $rows as $r ) {
			$r->totals = SSLMS_Hours::totals( (int) $r->id );
		}
		return self::ok( $rows );
	}

	public static function get_rotation( WP_REST_Request $req ) {
		$r = SSLMS_Hours::get_rotation( (int) $req['id'] );
		if ( ! $r ) {
			return self::fail( 'sslms_not_found', 'Rotation not found.', 404 );
		}
		$r->totals = SSLMS_Hours::totals( (int) $r->id );
		$r->logs   = SSLMS_Hours::logs_for_rotation( (int) $r->id );
		return self::ok( $r );
	}

	public static function create_rotation( WP_REST_Request $req ) {
		$result = SSLMS_Hours::create_rotation(
			(int) $req->get_param( 'user_id' ),
			$req->get_param( 'department' ),
			$req->get_param( 'start_date' ),
			$req->get_param( 'end_date' ),
			(int) $req->get_param( 'preceptor_id' ),
			(float) $req->get_param( 'required_hours' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	public static function update_rotation( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$args = array();
		foreach ( array( 'department', 'start_date', 'end_date', 'preceptor_id', 'required_hours' ) as $f ) {
			if ( null !== $req->get_param( $f ) ) {
				$args[ $f ] = $req->get_param( $f );
			}
		}
		SSLMS_Hours::update_rotation( $id, $args );
		return self::ok( SSLMS_Hours::get_rotation( $id ) );
	}

	public static function delete_rotation( WP_REST_Request $req ) {
		SSLMS_Hours::delete_rotation( (int) $req['id'] );
		return self::ok( array( 'deleted' => true ) );
	}

	/* ---------------------------------------------------------------
	 * Learner
	 * ------------------------------------------------------------- */

	public static function mine(): WP_REST_Response {
		$uid = get_current_user_id();
		$rotations = SSLMS_Hours::rotations_for_user( $uid );
		foreach ( $rotations as $r ) {
			$r->logs = SSLMS_Hours::logs_for_rotation( (int) $r->id );
		}
		return self::ok( $rotations );
	}

	public static function create_log( WP_REST_Request $req ) {
		$result = SSLMS_Hours::log_hours(
			(int) $req->get_param( 'rotation_id' ),
			get_current_user_id(),
			$req->get_param( 'date' ),
			(float) $req->get_param( 'hours' ),
			(string) $req->get_param( 'activity' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'id' => $result ), 201 );
	}

	public static function update_log( WP_REST_Request $req ) {
		$args = array();
		foreach ( array( 'date', 'hours', 'activity' ) as $f ) {
			if ( null !== $req->get_param( $f ) ) {
				$args[ $f ] = $req->get_param( $f );
			}
		}
		$result = SSLMS_Hours::edit_log( (int) $req['id'], get_current_user_id(), $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( SSLMS_Hours::get_log( (int) $req['id'] ) );
	}

	public static function delete_log( WP_REST_Request $req ) {
		$result = SSLMS_Hours::delete_log( (int) $req['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( array( 'deleted' => true ) );
	}

	/* ---------------------------------------------------------------
	 * Preceptor review
	 * ------------------------------------------------------------- */

	public static function review_log( WP_REST_Request $req ) {
		$result = SSLMS_Hours::review(
			(int) $req['id'],
			get_current_user_id(),
			$req->get_param( 'status' ),
			(string) $req->get_param( 'note' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( SSLMS_Hours::get_log( (int) $req['id'] ) );
	}

	public static function pending_approvals(): WP_REST_Response {
		return self::ok( SSLMS_Hours::pending_approvals( get_current_user_id() ) );
	}

	/* ---------------------------------------------------------------
	 * CSV export
	 * ------------------------------------------------------------- */

	public static function export_learner( WP_REST_Request $req ) {
		$target = (int) $req['user_id'];
		$uid    = get_current_user_id();
		$allowed = current_user_can( 'sslms_manage' ) || current_user_can( 'sslms_view_reports' ) || $target === $uid;
		if ( ! $allowed ) {
			foreach ( SSLMS_Hours::list_rotations( array( 'user_id' => $target ) ) as $rotation ) {
				if ( SSLMS_Hours::reviewer_in_scope( $rotation, $uid ) ) {
					$allowed = true;
					break;
				}
			}
		}
		if ( ! $allowed ) {
			return self::fail( 'sslms_forbidden', 'Not permitted.', 403 );
		}
		self::send_csv(
			'hours-user-' . $target . '.csv',
			array( 'Department', 'Date', 'Hours', 'Activity', 'Status', 'Reviewed', 'Note' ),
			SSLMS_Hours::export_rows_for_user( $target )
		);
		return self::ok();
	}

	public static function export_rotation( WP_REST_Request $req ) {
		$id       = (int) $req['id'];
		$rotation = SSLMS_Hours::get_rotation( $id );
		if ( ! $rotation ) {
			return self::fail( 'sslms_not_found', 'Rotation not found.', 404 );
		}
		$uid     = get_current_user_id();
		$allowed = current_user_can( 'sslms_manage' )
			|| current_user_can( 'sslms_view_reports' )
			|| (int) $rotation->user_id === $uid
			|| SSLMS_Hours::reviewer_in_scope( $rotation, $uid );
		if ( ! $allowed ) {
			return self::fail( 'sslms_forbidden', 'Not permitted.', 403 );
		}
		self::send_csv(
			'hours-rotation-' . $id . '.csv',
			array( 'Learner', 'Date', 'Hours', 'Activity', 'Status', 'Reviewed', 'Note' ),
			SSLMS_Hours::export_rows_for_rotation( $id )
		);
		return self::ok();
	}
}
SSLMS_REST_Hours::init();
