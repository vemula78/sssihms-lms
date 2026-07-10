<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module F — REST routes backing the People admin screen, plus the audit-log
 * CSV export (reuses SSLMS_Admin_Audit's filtered query so screen and export
 * always agree).
 */
class SSLMS_REST_People extends SSLMS_REST_Base {

	const REL_TYPES = array( 'mentor', 'preceptor', 'evaluator' );
	const TRACKS    = array( 'clinical', 'allied', 'chaplaincy' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/people/profile', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_profile' ),
				'permission_callback' => self::can( 'sslms_manage' ),
				'args'                => array(
					'user_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_user_id' ),
					),
				),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update_profile' ),
				'permission_callback' => self::can( 'sslms_manage' ),
				'args'                => array(
					'user_id'    => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_user_id' ),
					),
					'staff_id'   => array(
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'discipline' => array(
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'tracks'     => array(
						'required'          => false,
						'default'           => array(),
						'sanitize_callback' => array( __CLASS__, 'sanitize_tracks' ),
					),
				),
			),
		) );

		register_rest_route( self::NS, '/people/relationships', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_relationships' ),
				'permission_callback' => self::can( 'sslms_manage' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_relationship' ),
				'permission_callback' => self::can( 'sslms_manage' ),
				'args'                => array(
					'user_id'         => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_user_id' ),
					),
					'related_user_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_user_id' ),
					),
					'rel_type'        => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static function ( $value ) {
							return in_array( $value, SSLMS_REST_People::REL_TYPES, true );
						},
					),
				),
			),
		) );

		register_rest_route( self::NS, '/people/relationships/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete_relationship' ),
			'permission_callback' => self::can( 'sslms_manage' ),
			'args'                => array(
				'id' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( self::NS, '/audit/export', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'audit_export' ),
			'permission_callback' => self::can( 'sslms_view_audit' ),
			'args'                => array(
				'user_id'   => array(
					'required'          => false,
					'sanitize_callback' => 'absint',
				),
				'action'    => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'date_from' => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'date_to'   => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
	}

	public static function validate_user_id( $value ): bool {
		return (bool) get_userdata( absint( $value ) );
	}

	public static function sanitize_tracks( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$clean = array();
		foreach ( $value as $t ) {
			$t = sanitize_key( (string) $t );
			if ( in_array( $t, self::TRACKS, true ) && ! in_array( $t, $clean, true ) ) {
				$clean[] = $t;
			}
		}
		return $clean;
	}

	/* ---------------------------------------------------------------- */
	/* Profile                                                           */
	/* ---------------------------------------------------------------- */

	public static function get_profile( WP_REST_Request $req ) {
		$user_id = absint( $req->get_param( 'user_id' ) );
		$row     = SSLMS_DB::get_row_by( 'profiles', 'user_id', $user_id );
		return self::ok( array(
			'user_id'    => $user_id,
			'staff_id'   => $row->staff_id ?? '',
			'discipline' => $row->discipline ?? '',
			'tracks'     => $row && $row->tracks ? array_filter( array_map( 'trim', explode( ',', $row->tracks ) ) ) : array(),
			'consent_at' => $row->consent_at ?? null,
		) );
	}

	public static function update_profile( WP_REST_Request $req ) {
		$user_id    = absint( $req->get_param( 'user_id' ) );
		$staff_id   = (string) $req->get_param( 'staff_id' );
		$discipline = (string) $req->get_param( 'discipline' );
		$tracks     = (array) $req->get_param( 'tracks' );

		$data = array(
			'staff_id'   => substr( $staff_id, 0, 50 ),
			'discipline' => substr( $discipline, 0, 100 ),
			'tracks'     => implode( ',', $tracks ),
		);

		$existing = SSLMS_DB::get_row_by( 'profiles', 'user_id', $user_id );
		if ( $existing ) {
			$ok = SSLMS_DB::update( 'profiles', $data, array( 'user_id' => $user_id ) );
		} else {
			// profiles.user_id is the primary key (no AUTO_INCREMENT column), so
			// SSLMS_DB::insert()'s insert_id-based return value is always 0 here
			// even on success; call $wpdb->insert() directly to get a real result.
			global $wpdb;
			$data['user_id'] = $user_id;
			$ok              = false !== $wpdb->insert( SSLMS_DB::table( 'profiles' ), $data );
		}

		if ( ! $ok ) {
			return self::fail( 'sslms_profile_save_failed', __( 'Could not save profile.', 'sssihms-lms' ), 500 );
		}

		SSLMS_Audit::log( 'profile_updated', 'user', $user_id, 'LMS profile updated' );

		return self::ok( array( 'user_id' => $user_id ) );
	}

	/* ---------------------------------------------------------------- */
	/* Relationships                                                      */
	/* ---------------------------------------------------------------- */

	public static function list_relationships(): WP_REST_Response {
		global $wpdb;
		$table = SSLMS_DB::table( 'relationships' );
		$rows  = $wpdb->get_results(
			"SELECT r.*, ul.display_name AS learner_name, ur.display_name AS related_name
			 FROM {$table} r
			 LEFT JOIN {$wpdb->users} ul ON ul.ID = r.user_id
			 LEFT JOIN {$wpdb->users} ur ON ur.ID = r.related_user_id
			 ORDER BY r.created_at DESC"
		);
		return self::ok( $rows );
	}

	public static function create_relationship( WP_REST_Request $req ) {
		$user_id         = absint( $req->get_param( 'user_id' ) );
		$related_user_id = absint( $req->get_param( 'related_user_id' ) );
		$rel_type        = (string) $req->get_param( 'rel_type' );

		if ( $user_id === $related_user_id ) {
			return self::fail( 'sslms_invalid_relationship', __( 'A person cannot be related to themselves.', 'sssihms-lms' ), 400 );
		}
		if ( ! user_can( $user_id, 'sslms_learn' ) ) {
			return self::fail( 'sslms_invalid_learner', __( 'Selected learner does not have LMS learner access.', 'sssihms-lms' ), 400 );
		}

		$cap_by_type = array(
			'mentor'    => array( 'sslms_mentor_journals' ),
			'preceptor' => array( 'sslms_approve_hours' ),
			'evaluator' => array( 'sslms_signoff_checklists' ),
		);
		$required = $cap_by_type[ $rel_type ] ?? array();
		$has_cap  = false;
		foreach ( $required as $cap ) {
			if ( user_can( $related_user_id, $cap ) ) {
				$has_cap = true;
				break;
			}
		}
		if ( ! $has_cap ) {
			return self::fail( 'sslms_invalid_related_user', __( 'Selected person does not hold the required capability for this relationship type.', 'sssihms-lms' ), 400 );
		}

		global $wpdb;
		$table  = SSLMS_DB::table( 'relationships' );
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE user_id = %d AND related_user_id = %d AND rel_type = %s",
			$user_id,
			$related_user_id,
			$rel_type
		) );
		if ( $exists ) {
			return self::fail( 'sslms_duplicate_relationship', __( 'This relationship already exists.', 'sssihms-lms' ), 409 );
		}

		$id = SSLMS_DB::insert( 'relationships', array(
			'user_id'         => $user_id,
			'related_user_id' => $related_user_id,
			'rel_type'        => $rel_type,
			'created_by'      => get_current_user_id(),
			'created_at'      => SSLMS_DB::now(),
		) );

		if ( ! $id ) {
			return self::fail( 'sslms_relationship_save_failed', __( 'Could not save relationship.', 'sssihms-lms' ), 500 );
		}

		SSLMS_Audit::log( 'relationship_added', 'relationship', $id, sprintf( '%s: user #%d related to user #%d', $rel_type, $user_id, $related_user_id ) );

		return self::ok( array( 'id' => $id ), 201 );
	}

	public static function delete_relationship( WP_REST_Request $req ) {
		$id  = absint( $req->get_param( 'id' ) );
		$row = SSLMS_DB::get_row( 'relationships', $id );
		if ( ! $row ) {
			return self::fail( 'sslms_not_found', __( 'Relationship not found.', 'sssihms-lms' ), 404 );
		}

		$ok = SSLMS_DB::delete( 'relationships', array( 'id' => $id ) );
		if ( ! $ok ) {
			return self::fail( 'sslms_relationship_delete_failed', __( 'Could not remove relationship.', 'sssihms-lms' ), 500 );
		}

		SSLMS_Audit::log( 'relationship_removed', 'relationship', $id, sprintf( '%s: user #%d no longer related to user #%d', $row->rel_type, $row->user_id, $row->related_user_id ) );

		return self::ok( array( 'id' => $id ) );
	}

	/* ---------------------------------------------------------------- */
	/* Audit CSV export (SPEC F10.3) — read-only, reuses the admin query. */
	/* ---------------------------------------------------------------- */

	public static function audit_export( WP_REST_Request $req ): void {
		$filters = SSLMS_Admin_Audit::filters_from_request( $req->get_params() );
		$rows    = SSLMS_Admin_Audit::get_all_rows( $filters );

		$csv_rows = array();
		foreach ( $rows as $row ) {
			$ts   = strtotime( $row->created_at );
			$ip   = $row->ip ? @inet_ntop( $row->ip ) : '';
			$csv_rows[] = array(
				'time'    => $ts ? date_i18n( 'd-M-Y H:i', $ts ) : '',
				'user'    => $row->display_name ? $row->display_name : ( '#' . (int) $row->user_id ),
				'action'  => $row->action,
				'object'  => $row->object_type ? $row->object_type . ( $row->object_id ? ' #' . (int) $row->object_id : '' ) : '',
				'summary' => $row->summary,
				'ip'      => $ip ? $ip : '',
			);
		}

		self::send_csv(
			'sslms-audit-log.csv',
			array( 'Time', 'User', 'Action', 'Object', 'Summary', 'IP' ),
			$csv_rows
		);
	}
}
SSLMS_REST_People::init();
