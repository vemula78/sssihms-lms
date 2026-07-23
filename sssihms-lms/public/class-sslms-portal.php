<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Learner-portal shell: shared chrome (nav, alerts), asset loading, page URLs,
 * and the [sslms_dashboard] shortcode. Module shortcodes wrap their markup in
 * SSLMS_Portal::wrap() and their pages appear in nav automatically.
 */
class SSLMS_Portal {

	public static function init(): void {
		add_shortcode( 'sslms_dashboard', array( __CLASS__, 'dashboard_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp', array( __CLASS__, 'record_consent' ) );
	}

	public static function register_assets(): void {
		wp_register_style( 'sslms', SSLMS_URL . 'assets/css/sslms.css', array(), SSLMS_VERSION );
		wp_register_script( 'sslms', SSLMS_URL . 'assets/js/sslms.js', array(), SSLMS_VERSION, true );
		wp_register_script( 'sslms-chartjs', SSLMS_URL . 'assets/js/chart.umd.js', array(), '4.4.0', true );
		wp_localize_script( 'sslms', 'SSLMS_CFG', array(
			'root'  => esc_url_raw( rest_url( 'sslms/v1' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		) );
	}

	public static function enqueue(): void {
		wp_enqueue_style( 'sslms' );
		wp_enqueue_script( 'sslms' );
	}

	/** URL of a portal page created on activation (see SSLMS_Install::pages()). */
	public static function page_url( string $key, array $query = array() ): string {
		$ids = get_option( 'sslms_pages', array() );
		$url = ! empty( $ids[ $key ] ) ? get_permalink( $ids[ $key ] ) : home_url( '/' );
		return $query ? add_query_arg( array_map( 'rawurlencode', $query ), $url ) : $url;
	}

	/** Wrap module markup in the shared portal chrome. */
	public static function wrap( string $title, string $inner_html ): string {
		self::enqueue();
		if ( ! is_user_logged_in() ) {
			return '<div class="sslms"><p>Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to use the LMS.</p></div>';
		}
		$nav = array(
			'dashboard'       => 'Home',
			'my_courses'      => 'Courses',
			'my_checklists'   => 'Checklists',
			'my_assessments'  => 'Assessments',
			'passport'        => 'Passport',
			'my_hours'        => 'Hours',
			'my_journal'      => 'Journal',
			'my_documents'    => 'Documents',
			'my_certificates' => 'Certificates',
		);
		$links = '';
		foreach ( $nav as $key => $label ) {
			$links .= '<a href="' . esc_url( self::page_url( $key ) ) . '">' . esc_html( $label ) . '</a>';
		}
		$consent = self::consent_notice();
		return '<div class="sslms">'
			. '<nav class="sslms-nav" aria-label="LMS">' . $links . '</nav>'
			. $consent
			. '<h1 class="sslms-title">' . esc_html( $title ) . '</h1>'
			. $inner_html
			. '<footer class="sslms-footer">Sri Sathya Sai Institute of Higher Medical Sciences, Whitefield</footer>'
			. '</div>';
	}

	/**
	 * DPDP consent notice (SPEC N2): shown until acknowledged; POST handled in
	 * record_consent().
	 */
	private static function consent_notice(): string {
		$row = SSLMS_DB::get_row_by( 'profiles', 'user_id', get_current_user_id() );
		if ( $row && $row->consent_at ) {
			return '';
		}
		return '<form method="post" class="sslms-consent" role="alert">'
			. wp_nonce_field( 'sslms_consent', '_sslms_consent_nonce', true, false )
			. '<p><strong>Data notice (DPDP Act 2023):</strong> the LMS stores your training records, '
			. 'competency sign-offs, credential documents and (if you write them) reflective journal entries, '
			. 'for hospital education and NABH compliance. Private journal entries are visible only to you.</p>'
			. '<button type="submit" name="sslms_consent_ack" value="1" class="sslms-btn">I understand</button>'
			. '</form>';
	}

	public static function record_consent(): void {
		if ( empty( $_POST['sslms_consent_ack'] ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['_sslms_consent_nonce'] ?? '', 'sslms_consent' ) ) {
			return;
		}
		$uid = get_current_user_id();
		global $wpdb;
		$t = SSLMS_DB::table( 'profiles' );
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$t} (user_id, consent_at) VALUES (%d, %s)
			 ON DUPLICATE KEY UPDATE consent_at = IF(consent_at IS NULL, VALUES(consent_at), consent_at)",
			$uid, SSLMS_DB::now()
		) );
		SSLMS_Audit::log( 'consent_recorded', 'user', $uid, 'DPDP consent acknowledged' );
	}

	/**
	 * [sslms_dashboard] — learner home. Modules contribute cards via the
	 * 'sslms_dashboard_cards' filter: fn(array $cards): array, each card =
	 * ['title' => ..., 'html' => ...].
	 */
	public static function dashboard_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return self::wrap( 'LMS', '' );
		}
		$cards = apply_filters( 'sslms_dashboard_cards', array() );
		$html  = '<div class="sslms-cards">';
		foreach ( $cards as $card ) {
			$html .= '<section class="sslms-card"><h2>' . esc_html( $card['title'] ) . '</h2>' . $card['html'] . '</section>';
		}
		$html .= '</div>';
		if ( ! $cards ) {
			$html = '<p>Welcome to the SSSIHMS Learning Management System.</p>';
		}
		return self::wrap( 'My Learning', $html );
	}
}
SSLMS_Portal::init();
