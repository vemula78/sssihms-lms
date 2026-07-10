<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for credential document uploads (Module E, SPEC F9, ADR-4).
 * Files never get a public URL — GET .../file streams through
 * SSLMS_Documents::can_view()/stream().
 */
class SSLMS_REST_Documents extends SSLMS_REST_Base {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	private static function doc_type_validate(): callable {
		return static function ( $value ) {
			return in_array( $value, SSLMS_Documents::DOC_TYPES, true );
		};
	}

	private static function date_validate( bool $required ): callable {
		return static function ( $value ) use ( $required ) {
			if ( '' === $value || null === $value ) {
				return ! $required;
			}
			return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value );
		};
	}

	public static function register_routes(): void {

		register_rest_route( self::NS, '/documents', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_mine' ),
				'permission_callback' => self::can( 'sslms_learn' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload' ),
				'permission_callback' => self::can( 'sslms_learn' ),
				'args'                => array(
					'doc_type'    => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => self::doc_type_validate(),
					),
					'issue_date'  => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => self::date_validate( true ),
					),
					'expiry_date' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => self::date_validate( false ),
					),
				),
			),
		) );

		register_rest_route( self::NS, '/documents/compliance', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'compliance' ),
			// sslms_manage only: the matrix covers every learner's credential
			// status, which must not be visible to unscoped instructors (DPDP).
			'permission_callback' => self::can( 'sslms_manage' ),
		) );

		register_rest_route( self::NS, '/documents/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete' ),
			'permission_callback' => self::can( 'sslms_learn' ),
		) );

		register_rest_route( self::NS, '/documents/(?P<id>\d+)/file', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'stream_file' ),
			'permission_callback' => static function () {
				return is_user_logged_in()
					? true
					: new WP_Error( 'sslms_forbidden', 'Not permitted.', array( 'status' => 401 ) );
			},
		) );

		register_rest_route( self::NS, '/documents/(?P<id>\d+)/verify', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'verify' ),
			'permission_callback' => self::can( 'sslms_manage' ),
		) );
	}

	private static function serialize( object $d ): array {
		return array(
			'id'            => (int) $d->id,
			'doc_type'      => $d->doc_type,
			'original_name' => $d->original_name,
			'mime'          => $d->mime,
			'issue_date'    => $d->issue_date,
			'expiry_date'   => $d->expiry_date,
			'status'        => SSLMS_Documents::status( $d ),
			'verified_by'   => $d->verified_by ? (int) $d->verified_by : null,
			'verified_at'   => $d->verified_at,
			'uploaded_at'   => $d->uploaded_at,
			'view_url'      => SSLMS_Documents::file_url( (int) $d->id ),
		);
	}

	/** Own documents only — file_path is never included in the response. */
	public static function list_mine( WP_REST_Request $request ) {
		$docs = SSLMS_Documents::list_own( get_current_user_id() );
		return self::ok( array_map( array( __CLASS__, 'serialize' ), $docs ) );
	}

	public static function upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
			return self::fail( 'sslms_invalid', 'A file is required.', 400 );
		}
		$expiry = $request->get_param( 'expiry_date' );
		$id     = SSLMS_Documents::handle_upload(
			get_current_user_id(),
			$files['file'],
			(string) $request->get_param( 'doc_type' ),
			(string) $request->get_param( 'issue_date' ),
			( null === $expiry || '' === $expiry ) ? null : (string) $expiry
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::ok( array( 'id' => $id ), 201 );
	}

	public static function delete( WP_REST_Request $request ) {
		$result = SSLMS_Documents::delete_own_unverified( (int) $request['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( true );
	}

	/** Streams the file (owner, sslms_manage, or instructor) or a REST error. */
	public static function stream_file( WP_REST_Request $request ) {
		$doc = SSLMS_DB::get_row( 'documents', (int) $request['id'] );
		if ( ! $doc ) {
			return self::fail( 'sslms_not_found', 'Document not found.', 404 );
		}
		if ( ! SSLMS_Documents::can_view( $doc, get_current_user_id() ) ) {
			return self::fail( 'sslms_forbidden', 'Not permitted.', 403 );
		}
		SSLMS_Documents::stream( $doc, get_current_user_id() ); // exits
		exit;
	}

	public static function verify( WP_REST_Request $request ) {
		$result = SSLMS_Documents::verify( (int) $request['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( true );
	}

	/** Compliance matrix (sslms_manage or sslms_view_reports); &format=csv export. */
	public static function compliance( WP_REST_Request $request ) {
		$report = SSLMS_Documents::compliance_report();

		if ( 'csv' === $request->get_param( 'format' ) ) {
			$header = array_merge( array( 'User', 'User ID' ), SSLMS_Documents::DOC_TYPES );
			$rows   = array();
			foreach ( $report as $r ) {
				$row = array( $r['display_name'], $r['user_id'] );
				foreach ( SSLMS_Documents::DOC_TYPES as $type ) {
					$row[] = $r[ $type ];
				}
				$rows[] = $row;
			}
			self::send_csv( 'sslms-compliance.csv', $header, $rows ); // exits
		}

		return self::ok( $report );
	}
}
SSLMS_REST_Documents::init();
