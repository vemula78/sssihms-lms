<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module E — credential document uploads with expiry alerts (SPEC F9, ADR-4).
 *
 * Files are stored under wp-content/uploads/sslms-private/{user_id}/ (deny-all
 * .htaccess created by SSLMS_Install) and are served only via the streaming
 * REST route with a permission check — never a public URL.
 */
class SSLMS_Documents {

	const DOC_TYPES = array( 'license', 'bls', 'acls', 'immunization', 'background', 'other' );

	/** Real-MIME (finfo) => canonical extension used for stored filenames. */
	const ALLOWED_MIME_EXT = array(
		'application/pdf' => array( 'pdf' ),
		'image/jpeg'       => array( 'jpg', 'jpeg' ),
		'image/png'        => array( 'png' ),
	);

	const MAX_SIZE = 10 * 1024 * 1024; // 10 MB

	public static function init(): void {
		add_action( 'sslms_daily_expiry_check', array( __CLASS__, 'run_expiry_check' ) );
	}

	private static function private_base_dir(): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'sslms-private';
	}

	/**
	 * Handle a validated upload for $user_id. $file is a single-file entry in
	 * the shape of $_FILES['field'] (name/type/tmp_name/error/size).
	 * Boundary validation: doc_type enum, size <=10MB, real MIME (finfo)
	 * matching an allowed extension. Returns new document id or WP_Error.
	 */
	public static function handle_upload( int $user_id, array $file, string $doc_type, string $issue_date, ?string $expiry_date ) {
		$doc_type = strtolower( trim( $doc_type ) );
		if ( ! in_array( $doc_type, self::DOC_TYPES, true ) ) {
			return new WP_Error( 'sslms_invalid_doc_type', 'Invalid document type.' );
		}

		if ( empty( $file['tmp_name'] ) || ( isset( $file['error'] ) && UPLOAD_ERR_OK !== $file['error'] ) ) {
			return new WP_Error( 'sslms_upload_error', 'Upload failed.' );
		}

		if ( ! is_readable( $file['tmp_name'] ) ) {
			return new WP_Error( 'sslms_upload_error', 'Upload failed.' );
		}

		$size = isset( $file['size'] ) ? (int) $file['size'] : (int) filesize( $file['tmp_name'] );
		if ( $size <= 0 || $size > self::MAX_SIZE ) {
			return new WP_Error( 'sslms_too_large', 'File must be 10 MB or smaller.' );
		}

		$finfo     = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false;
		$real_mime = $finfo ? finfo_file( $finfo, $file['tmp_name'] ) : false;
		if ( $finfo ) {
			finfo_close( $finfo );
		}
		if ( ! $real_mime || ! isset( self::ALLOWED_MIME_EXT[ $real_mime ] ) ) {
			return new WP_Error( 'sslms_invalid_type', 'File must be a PDF, JPG or PNG.' );
		}

		$orig_name = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$given_ext = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $given_ext, self::ALLOWED_MIME_EXT[ $real_mime ], true ) ) {
			return new WP_Error( 'sslms_type_mismatch', 'File extension does not match its content.' );
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issue_date ) ) {
			return new WP_Error( 'sslms_invalid_date', 'Issue date is required (YYYY-MM-DD).' );
		}
		if ( null !== $expiry_date && '' !== $expiry_date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expiry_date ) ) {
			return new WP_Error( 'sslms_invalid_date', 'Invalid expiry date.' );
		}
		if ( '' === $expiry_date ) {
			$expiry_date = null;
		}

		$store_ext = self::ALLOWED_MIME_EXT[ $real_mime ][0];
		$dir       = self::private_base_dir() . '/' . $user_id;
		wp_mkdir_p( $dir );
		$filename = wp_generate_password( 20, false ) . '.' . $store_ext;
		$dest     = $dir . '/' . $filename;

		if ( is_uploaded_file( $file['tmp_name'] ) ) {
			$moved = move_uploaded_file( $file['tmp_name'], $dest );
		} else {
			// Non-HTTP upload path (e.g. programmatic/tests): fall back to copy.
			$moved = copy( $file['tmp_name'], $dest );
		}
		if ( ! $moved ) {
			return new WP_Error( 'sslms_move_failed', 'Could not store the uploaded file.' );
		}

		$rel_path = 'sslms-private/' . $user_id . '/' . $filename;
		$id       = SSLMS_DB::insert( 'documents', array(
			'user_id'       => $user_id,
			'doc_type'      => $doc_type,
			'file_path'     => $rel_path,
			'original_name' => $orig_name ?: ( 'document.' . $store_ext ),
			'mime'          => $real_mime,
			'issue_date'    => $issue_date,
			'expiry_date'   => $expiry_date,
			'uploaded_at'   => SSLMS_DB::now(),
		) );
		if ( ! $id ) {
			@unlink( $dest );
			return new WP_Error( 'sslms_db_error', 'Could not save document record.' );
		}

		SSLMS_Audit::log( 'document_uploaded', 'document', $id, 'Type: ' . $doc_type );
		return $id;
	}

	/** Owner may delete own unverified documents. */
	public static function delete_own_unverified( int $doc_id, int $user_id ) {
		$doc = SSLMS_DB::get_row( 'documents', $doc_id );
		if ( ! $doc ) {
			return new WP_Error( 'sslms_not_found', 'Document not found.' );
		}
		if ( (int) $doc->user_id !== $user_id ) {
			return new WP_Error( 'sslms_forbidden', 'Not permitted.' );
		}
		if ( ! empty( $doc->verified_by ) ) {
			return new WP_Error( 'sslms_locked', 'Verified documents cannot be deleted.' );
		}
		$path = trailingslashit( wp_upload_dir()['basedir'] ) . $doc->file_path;
		if ( file_exists( $path ) ) {
			@unlink( $path );
		}
		SSLMS_DB::delete( 'documents', array( 'id' => $doc_id ) );
		SSLMS_Audit::log( 'document_deleted', 'document', $doc_id, 'Type: ' . $doc->doc_type );
		return true;
	}

	/** Admin verification (cap sslms_manage). */
	public static function verify( int $doc_id, int $admin_id ) {
		if ( ! user_can( $admin_id, 'sslms_manage' ) ) {
			return new WP_Error( 'sslms_forbidden', 'Not permitted.' );
		}
		$doc = SSLMS_DB::get_row( 'documents', $doc_id );
		if ( ! $doc ) {
			return new WP_Error( 'sslms_not_found', 'Document not found.' );
		}
		$ok = SSLMS_DB::update( 'documents', array(
			'verified_by' => $admin_id,
			'verified_at' => SSLMS_DB::now(),
		), array( 'id' => $doc_id ) );
		if ( ! $ok ) {
			return new WP_Error( 'sslms_db_error', 'Could not verify document.' );
		}
		SSLMS_Audit::log( 'document_verified', 'document', $doc_id, 'Type: ' . $doc->doc_type );
		return true;
	}

	/** valid / expiring (<=30 days) / expired / no-expiry. */
	public static function status( object $doc ): string {
		if ( empty( $doc->expiry_date ) || '0000-00-00' === substr( (string) $doc->expiry_date, 0, 10 ) ) {
			return 'no-expiry';
		}
		$expiry = strtotime( $doc->expiry_date );
		$today  = strtotime( 'today' );
		if ( false === $expiry ) {
			return 'no-expiry';
		}
		if ( $expiry < $today ) {
			return 'expired';
		}
		if ( $expiry <= strtotime( '+30 days', $today ) ) {
			return 'expiring';
		}
		return 'valid';
	}

	/** Owner, sslms_manage, or an instructor (sslms_author_courses) may view/stream. */
	public static function can_view( object $doc, int $viewer_id ): bool {
		if ( (int) $doc->user_id === $viewer_id ) {
			return true;
		}
		if ( user_can( $viewer_id, 'sslms_manage' ) ) {
			return true;
		}
		if ( user_can( $viewer_id, 'sslms_author_courses' ) ) {
			return true;
		}
		return false;
	}

	/** Stream the file inline with the correct headers, or wp_die(). Exits. */
	public static function stream( object $doc, int $viewer_id ): void {
		if ( ! self::can_view( $doc, $viewer_id ) ) {
			wp_die( esc_html__( 'Not permitted.', 'sssihms-lms' ), 403 );
		}
		$path = trailingslashit( wp_upload_dir()['basedir'] ) . $doc->file_path;
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'File not found.', 'sssihms-lms' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: ' . $doc->mime );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $doc->original_name ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path );
		exit;
	}

	/** Own documents, newest first. */
	public static function list_own( int $user_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'documents' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE user_id = %d ORDER BY uploaded_at DESC",
			$user_id
		) );
	}

	/**
	 * Compliance matrix: every learner (sslms_learn) x every doc type, with
	 * the status of their most recently uploaded document of that type (or
	 * 'missing').
	 */
	public static function compliance_report(): array {
		$learners = get_users( array( 'capability' => 'sslms_learn', 'fields' => array( 'ID', 'display_name' ) ) );

		global $wpdb;
		$t    = SSLMS_DB::table( 'documents' );
		$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY uploaded_at DESC" );

		$latest = array();
		foreach ( $rows as $row ) {
			$key = $row->user_id . '|' . $row->doc_type;
			if ( ! isset( $latest[ $key ] ) ) { // first hit wins: most recent (DESC order)
				$latest[ $key ] = $row;
			}
		}

		$report = array();
		foreach ( $learners as $learner ) {
			$entry = array(
				'user_id'      => (int) $learner->ID,
				'display_name' => $learner->display_name,
			);
			foreach ( self::DOC_TYPES as $type ) {
				$key             = $learner->ID . '|' . $type;
				$entry[ $type ]  = isset( $latest[ $key ] ) ? self::status( $latest[ $key ] ) : 'missing';
			}
			$report[] = $entry;
		}
		return $report;
	}

	/** Authenticated URL to stream a document's file (nonce for cookie auth). */
	public static function file_url( int $doc_id ): string {
		$base = rest_url( SSLMS_REST_Base::NS . '/documents/' . $doc_id . '/file' );
		return add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), $base );
	}

	/**
	 * Daily cron (event pre-scheduled by core): compile expiring/expired docs
	 * and send one digest email to the site admin (never per-learner emails).
	 */
	public static function run_expiry_check(): void {
		global $wpdb;
		$t    = SSLMS_DB::table( 'documents' );
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE expiry_date IS NOT NULL ORDER BY expiry_date ASC" );

		$lines = array();
		foreach ( $rows as $row ) {
			$status = self::status( $row );
			if ( 'expiring' === $status || 'expired' === $status ) {
				$user   = get_userdata( $row->user_id );
				$name   = $user ? $user->display_name : ( 'User #' . $row->user_id );
				$lines[] = sprintf(
					'%s — %s (%s): %s, expiry %s',
					$name,
					$row->doc_type,
					$status,
					$row->original_name,
					SSLMS_DB::fmt_date( $row->expiry_date )
				);
			}
		}

		if ( ! $lines ) {
			return;
		}

		$subject = sprintf( 'SSSIHMS LMS: %d credential document(s) expiring or expired', count( $lines ) );
		$body    = "The following credential documents require attention:\n\n" . implode( "\n", $lines );
		wp_mail( get_option( 'admin_email' ), $subject, $body );

		SSLMS_Audit::log( 'expiry_digest_sent', 'document', 0, count( $lines ) . ' document(s) flagged' );
	}
}
SSLMS_Documents::init();
