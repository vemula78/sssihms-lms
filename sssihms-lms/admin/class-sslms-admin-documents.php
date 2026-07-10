<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin "Credentials" screen (Module E, SPEC F9): pending-verification queue,
 * all-documents table with filters, compliance matrix + CSV, and an
 * expiring-within-30-days section. Verification uses the REST route via the
 * shared JS (data-sslms-endpoint); nothing here writes directly to the DB.
 */
class SSLMS_Admin_Documents {

	const DOC_TYPE_LABELS = array(
		'license'      => 'License/Registration',
		'bls'          => 'BLS',
		'acls'         => 'ACLS',
		'immunization' => 'Immunization',
		'background'   => 'Background check',
		'other'        => 'Other',
	);

	const STATUS_LABELS = array(
		'valid'      => array( 'Valid', 'ok' ),
		'expiring'   => array( 'Expiring soon', 'warn' ),
		'expired'    => array( 'Expired', 'bad' ),
		'no-expiry'  => array( 'No expiry', 'muted' ),
		'missing'    => array( 'Missing', 'bad' ),
	);

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 20 );
	}

	public static function register(): void {
		add_submenu_page(
			'sslms',
			'Credentials',
			'Credentials',
			'sslms_manage',
			'sslms-credentials',
			array( __CLASS__, 'render' )
		);
	}

	private static function badge( string $status ): string {
		$label = self::STATUS_LABELS[ $status ][0] ?? ucfirst( $status );
		$class = self::STATUS_LABELS[ $status ][1] ?? 'muted';
		return '<span class="sslms-badge sslms-badge--' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function user_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : ( 'User #' . $user_id );
	}

	public static function render(): void {
		if ( ! current_user_can( 'sslms_manage' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'sssihms-lms' ) );
		}

		global $wpdb;
		$t = SSLMS_DB::table( 'documents' );

		// Wrapped in .sslms purely so the shared assets/js/sslms.js form
		// delegation (".sslms form[data-sslms-endpoint]") picks up the
		// Verify button below; sslms.css (front-end look) is not enqueued
		// in wp-admin, so this adds no visual styling here.
		echo '<div class="wrap"><div class="sslms"><h1>Credentials</h1>';

		// --- Pending verification queue ---------------------------------
		$pending = $wpdb->get_results( "SELECT * FROM {$t} WHERE verified_by IS NULL ORDER BY uploaded_at ASC" );
		echo '<h2>Pending verification</h2>';
		if ( $pending ) {
			echo '<table class="widefat striped"><thead><tr><th>Learner</th><th>Type</th><th>Uploaded</th><th>Expiry</th><th>File</th><th>Action</th></tr></thead><tbody>';
			foreach ( $pending as $doc ) {
				echo '<tr>';
				echo '<td>' . esc_html( self::user_name( (int) $doc->user_id ) ) . '</td>';
				echo '<td>' . esc_html( self::DOC_TYPE_LABELS[ $doc->doc_type ] ?? $doc->doc_type ) . '</td>';
				echo '<td>' . esc_html( SSLMS_DB::fmt_date( $doc->uploaded_at ) ) . '</td>';
				echo '<td>' . esc_html( SSLMS_DB::fmt_date( $doc->expiry_date ) ) . '</td>';
				echo '<td><a href="' . esc_url( SSLMS_Documents::file_url( (int) $doc->id ) ) . '" target="_blank" rel="noopener">View file</a></td>';
				echo '<td><form data-sslms-endpoint="documents/' . (int) $doc->id . '/verify" data-sslms-method="POST"><button type="submit" class="button button-primary">Verify</button></form></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Nothing awaiting verification.</p>';
		}

		// --- All documents (with filters) -------------------------------
		$filter_type   = isset( $_GET['doc_type'] ) ? sanitize_text_field( wp_unslash( $_GET['doc_type'] ) ) : '';
		$filter_status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

		$where  = array( '1=1' );
		$params = array();
		if ( $filter_type && in_array( $filter_type, SSLMS_Documents::DOC_TYPES, true ) ) {
			$where[]  = 'doc_type = %s';
			$params[] = $filter_type;
		}
		$sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY uploaded_at DESC';
		$all = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

		if ( $filter_status ) {
			$all = array_values( array_filter( $all, static function ( $d ) use ( $filter_status ) {
				return SSLMS_Documents::status( $d ) === $filter_status;
			} ) );
		}

		echo '<h2>All documents</h2>';
		echo '<form method="get" class="sslms-flex">';
		echo '<input type="hidden" name="page" value="sslms-credentials" />';
		echo '<select name="doc_type"><option value="">All types</option>';
		foreach ( self::DOC_TYPE_LABELS as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $filter_type, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<select name="status"><option value="">All statuses</option>';
		foreach ( array( 'valid', 'expiring', 'expired', 'no-expiry' ) as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( $filter_status, $s, false ) . '>' . esc_html( self::STATUS_LABELS[ $s ][0] ) . '</option>';
		}
		echo '</select>';
		echo '<button type="submit" class="button">Filter</button>';
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr><th>Learner</th><th>Type</th><th>Issue</th><th>Expiry</th><th>Status</th><th>Verified</th><th>File</th></tr></thead><tbody>';
		foreach ( $all as $doc ) {
			echo '<tr>';
			echo '<td>' . esc_html( self::user_name( (int) $doc->user_id ) ) . '</td>';
			echo '<td>' . esc_html( self::DOC_TYPE_LABELS[ $doc->doc_type ] ?? $doc->doc_type ) . '</td>';
			echo '<td>' . esc_html( SSLMS_DB::fmt_date( $doc->issue_date ) ) . '</td>';
			echo '<td>' . esc_html( SSLMS_DB::fmt_date( $doc->expiry_date ) ) . '</td>';
			echo '<td>' . self::badge( SSLMS_Documents::status( $doc ) ) . '</td>';
			echo '<td>' . ( $doc->verified_by ? '&#10003; ' . esc_html( SSLMS_DB::fmt_date( $doc->verified_at ) ) : '—' ) . '</td>';
			echo '<td><a href="' . esc_url( SSLMS_Documents::file_url( (int) $doc->id ) ) . '" target="_blank" rel="noopener">View</a></td>';
			echo '</tr>';
		}
		if ( ! $all ) {
			echo '<tr><td colspan="7">No documents match this filter.</td></tr>';
		}
		echo '</tbody></table>';

		// --- Expiring within 30 days -------------------------------------
		echo '<h2>Expiring within 30 days</h2>';
		$expiring = array_values( array_filter( $wpdb->get_results( "SELECT * FROM {$t} WHERE expiry_date IS NOT NULL" ), static function ( $d ) {
			return 'expiring' === SSLMS_Documents::status( $d );
		} ) );
		if ( $expiring ) {
			echo '<table class="widefat striped"><thead><tr><th>Learner</th><th>Type</th><th>Expiry</th></tr></thead><tbody>';
			foreach ( $expiring as $doc ) {
				echo '<tr><td>' . esc_html( self::user_name( (int) $doc->user_id ) ) . '</td><td>' . esc_html( self::DOC_TYPE_LABELS[ $doc->doc_type ] ?? $doc->doc_type ) . '</td><td>' . esc_html( SSLMS_DB::fmt_date( $doc->expiry_date ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Nothing expiring in the next 30 days.</p>';
		}

		// --- Compliance matrix + CSV --------------------------------------
		echo '<h2>Compliance matrix</h2>';
		$csv_url = add_query_arg( array(
			'format'   => 'csv',
			'_wpnonce' => wp_create_nonce( 'wp_rest' ),
		), rest_url( SSLMS_REST_Base::NS . '/documents/compliance' ) );
		echo '<p><a class="button" href="' . esc_url( $csv_url ) . '">Download CSV</a></p>';

		$report = SSLMS_Documents::compliance_report();
		echo '<div class="sslms-table-scroll"><table class="widefat striped"><thead><tr><th>Learner</th>';
		foreach ( self::DOC_TYPE_LABELS as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $report as $row ) {
			echo '<tr><td>' . esc_html( $row['display_name'] ) . '</td>';
			foreach ( SSLMS_Documents::DOC_TYPES as $type ) {
				echo '<td>' . self::badge( $row[ $type ] ) . '</td>';
			}
			echo '</tr>';
		}
		if ( ! $report ) {
			echo '<tr><td colspan="' . ( 1 + count( SSLMS_Documents::DOC_TYPES ) ) . '">No learners found.</td></tr>';
		}
		echo '</tbody></table></div>';

		echo '</div></div>';
	}
}
SSLMS_Admin_Documents::init();
