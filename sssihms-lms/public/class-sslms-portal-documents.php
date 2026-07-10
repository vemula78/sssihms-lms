<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [sslms_my_documents] — Module E learner portal page: own credential
 * documents table (status badges, verified tick, view link, delete-if-
 * unverified) plus a multipart upload form. Upload/delete go through the
 * gated REST routes in class-sslms-rest-documents.php.
 */
class SSLMS_Portal_Documents {

	const DOC_TYPE_LABELS = array(
		'license'      => 'License/Registration',
		'bls'          => 'BLS',
		'acls'         => 'ACLS',
		'immunization' => 'Immunization',
		'background'   => 'Background check',
		'other'        => 'Other',
	);

	const STATUS_LABELS = array(
		'valid'     => array( 'Valid', 'ok' ),
		'expiring'  => array( 'Expiring soon', 'warn' ),
		'expired'   => array( 'Expired', 'bad' ),
		'no-expiry' => array( 'No expiry', 'muted' ),
	);

	public static function init(): void {
		add_shortcode( 'sslms_my_documents', array( __CLASS__, 'shortcode' ) );
		add_filter( 'sslms_dashboard_cards', array( __CLASS__, 'dashboard_card' ) );
	}

	private static function badge( string $status ): string {
		$label = self::STATUS_LABELS[ $status ][0] ?? ucfirst( $status );
		$class = self::STATUS_LABELS[ $status ][1] ?? 'muted';
		return '<span class="sslms-badge sslms-badge--' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	public static function shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return SSLMS_Portal::wrap( 'My Documents', '' );
		}
		$user_id = get_current_user_id();
		$html    = self::render_upload_form();
		$html   .= self::render_own_documents( $user_id );
		return SSLMS_Portal::wrap( 'My Documents', $html );
	}

	private static function render_upload_form(): string {
		$out  = '<section class="sslms-doc-upload"><h2>Upload a document</h2>';
		$out .= '<form data-sslms-endpoint="documents" data-sslms-method="POST" enctype="multipart/form-data">';
		$out .= '<label for="sslms-doc-type">Document type</label>';
		$out .= '<select id="sslms-doc-type" name="doc_type" required>';
		foreach ( self::DOC_TYPE_LABELS as $key => $label ) {
			$out .= '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
		}
		$out .= '</select>';
		$out .= '<label for="sslms-doc-file">File (PDF, JPG or PNG, up to 10 MB)</label>';
		$out .= '<input type="file" id="sslms-doc-file" name="file" accept=".pdf,.jpg,.jpeg,.png" required />';
		$out .= '<label for="sslms-doc-issue">Issue date</label>';
		$out .= '<input type="date" id="sslms-doc-issue" name="issue_date" required />';
		$out .= '<label for="sslms-doc-expiry">Expiry date (leave blank if none)</label>';
		$out .= '<input type="date" id="sslms-doc-expiry" name="expiry_date" />';
		$out .= '<button type="submit" class="sslms-btn">Upload</button>';
		$out .= '</form></section>';
		return $out;
	}

	private static function render_own_documents( int $user_id ): string {
		$docs = SSLMS_Documents::list_own( $user_id );
		$out  = '<section class="sslms-doc-list"><h2>My documents</h2>';
		if ( ! $docs ) {
			$out .= '<p>No documents uploaded yet.</p>';
			$out .= '</section>';
			return $out;
		}
		$out .= '<div class="sslms-table-scroll"><table><thead><tr><th>Type</th><th>Issue</th><th>Expiry</th><th>Status</th><th>Verified</th><th></th></tr></thead><tbody>';
		foreach ( $docs as $doc ) {
			$status = SSLMS_Documents::status( $doc );
			$out   .= '<tr>';
			$out   .= '<td>' . esc_html( self::DOC_TYPE_LABELS[ $doc->doc_type ] ?? $doc->doc_type ) . '</td>';
			$out   .= '<td>' . esc_html( SSLMS_DB::fmt_date( $doc->issue_date ) ) . '</td>';
			$out   .= '<td>' . esc_html( SSLMS_DB::fmt_date( $doc->expiry_date ) ) . '</td>';
			$out   .= '<td>' . self::badge( $status ) . '</td>';
			$out   .= '<td>' . ( $doc->verified_by ? '&#10003; verified' : '—' ) . '</td>';
			$out   .= '<td><a href="' . esc_url( SSLMS_Documents::file_url( (int) $doc->id ) ) . '" target="_blank" rel="noopener">View</a>';
			if ( ! $doc->verified_by ) {
				$out .= ' <form style="display:inline" data-sslms-endpoint="documents/' . (int) $doc->id . '" data-sslms-method="DELETE" onsubmit="return confirm(\'Delete this document?\')"><button type="submit" class="sslms-btn sslms-btn--danger">Delete</button></form>';
			}
			$out .= '</td></tr>';
		}
		$out .= '</tbody></table></div></section>';
		return $out;
	}

	/** Dashboard card: alert banner for own expiring/expired documents. */
	public static function dashboard_card( array $cards ): array {
		if ( ! is_user_logged_in() ) {
			return $cards;
		}
		$docs     = SSLMS_Documents::list_own( get_current_user_id() );
		$expiring = 0;
		$expired  = 0;
		foreach ( $docs as $doc ) {
			$status = SSLMS_Documents::status( $doc );
			if ( 'expiring' === $status ) {
				++$expiring;
			} elseif ( 'expired' === $status ) {
				++$expired;
			}
		}
		if ( $expiring || $expired ) {
			$msg    = array();
			if ( $expired ) {
				$msg[] = $expired . ' expired';
			}
			if ( $expiring ) {
				$msg[] = $expiring . ' expiring within 30 days';
			}
			$cards[] = array(
				'title' => 'Documents',
				'html'  => '<div class="sslms-alert" role="alert">' . esc_html( implode( ', ', $msg ) ) . '. '
					. '<a href="' . esc_url( SSLMS_Portal::page_url( 'my_documents' ) ) . '">View documents</a></div>',
			);
		}
		return $cards;
	}
}
SSLMS_Portal_Documents::init();
