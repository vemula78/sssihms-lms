<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module C — learner-facing certificates (SPEC F5) and the lesson
 * "mark complete" control on the course player (SPEC F4.1).
 */
class SSLMS_Portal_Certificates {

	public static function init(): void {
		add_shortcode( 'sslms_my_certificates', array( __CLASS__, 'my_certificates_shortcode' ) );
		add_shortcode( 'sslms_verify_certificate', array( __CLASS__, 'verify_shortcode' ) );
		add_action( 'sslms_lesson_view', array( __CLASS__, 'render_lesson_complete_button' ), 10, 2 );
		add_filter( 'sslms_dashboard_cards', array( __CLASS__, 'dashboard_card' ) );
	}

	/**
	 * [sslms_my_certificates] — card list of the learner's certificates; with
	 * &cert=CODE renders the printable certificate (owner or sslms_manage only).
	 */
	public static function my_certificates_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return SSLMS_Portal::wrap( 'My Certificates', '<p>Please log in.</p>' );
		}
		if ( ! current_user_can( 'sslms_learn' ) ) {
			return SSLMS_Portal::wrap( 'My Certificates', '<p>Not permitted.</p>' );
		}

		$code = isset( $_GET['cert'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['cert'] ) ) ) : '';
		if ( '' !== $code ) {
			return self::render_certificate_view( $code );
		}

		$certs = SSLMS_Certificates::by_user( get_current_user_id() );
		$html  = '<div class="sslms-cards">';
		foreach ( $certs as $c ) {
			$view_url = add_query_arg( 'cert', rawurlencode( $c->cert_code ), SSLMS_Portal::page_url( 'my_certificates' ) );
			$html    .= '<section class="sslms-card">'
				. '<h2>' . esc_html( $c->course_title ) . '</h2>'
				. '<p>' . esc_html( SSLMS_DB::fmt_date( $c->issued_at ) ) . ' &middot; Code: <strong>' . esc_html( $c->cert_code ) . '</strong></p>'
				. '<p><a class="sslms-btn" href="' . esc_url( $view_url ) . '">View certificate</a></p>'
				. '</section>';
		}
		$html .= '</div>';
		if ( ! $certs ) {
			$html = '<p>No certificates yet — complete a course to earn one.</p>';
		}

		return SSLMS_Portal::wrap( 'My Certificates', $html );
	}

	/** Printable A4 certificate view for a specific code. Owner or sslms_manage only. */
	private static function render_certificate_view( string $code ): string {
		$cert = SSLMS_Certificates::by_code( $code );
		if ( ! $cert ) {
			return SSLMS_Portal::wrap( 'Certificate', '<p>Certificate not found.</p>' );
		}
		if ( (int) $cert->user_id !== get_current_user_id() && ! current_user_can( 'sslms_manage' ) ) {
			return SSLMS_Portal::wrap( 'Certificate', '<p>Not permitted.</p>' );
		}

		$html = '<div class="sslms-certificate">'
			. '<p>Sri Sathya Sai Institute of Higher Medical Sciences, Whitefield</p>'
			. '<h1>Certificate of Completion</h1>'
			. '<p>This is to certify that</p>'
			. '<p class="sslms-cert-name">' . esc_html( $cert->display_name ) . '</p>'
			. '<p>has successfully completed the course</p>'
			. '<p class="sslms-cert-name">' . esc_html( $cert->course_title ) . '</p>'
			. '<p>on ' . esc_html( SSLMS_DB::fmt_date( $cert->completed_at ) ) . '</p>'
			. '<p>Verification code: <strong>' . esc_html( $cert->cert_code ) . '</strong></p>'
			. '</div>'
			. '<p><button type="button" class="sslms-btn" onclick="window.print()">Print</button></p>';

		return SSLMS_Portal::wrap( 'Certificate', $html );
	}

	/**
	 * [sslms_verify_certificate] — PUBLIC, no login required. Enter a code,
	 * results show on GET submit.
	 */
	public static function verify_shortcode(): string {
		wp_enqueue_style( 'sslms' );

		$submitted = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		$html = '<form method="get" class="sslms-verify-form">'
			. '<label for="sslms-verify-code">Certificate code</label>'
			. '<input type="text" id="sslms-verify-code" name="code" maxlength="12" value="' . esc_attr( $submitted ) . '" />'
			. '<button type="submit" class="sslms-btn">Verify</button>'
			. '</form>';

		if ( '' !== trim( $submitted ) ) {
			$html .= self::verify_result( $submitted );
		}

		return self::public_wrap( 'Verify Certificate', $html );
	}

	private static function verify_result( string $raw_code ): string {
		if ( ! self::rate_limit_ok() ) {
			return '<div class="sslms-alert sslms-alert--error">Too many attempts — please try again in a minute.</div>';
		}

		$code = strtoupper( trim( $raw_code ) );
		$cert = $code ? SSLMS_Certificates::by_code( $code ) : null;
		if ( ! $cert ) {
			return '<div class="sslms-alert sslms-alert--error">No certificate found for that code.</div>';
		}

		return '<div class="sslms-alert">'
			. '<p><strong>' . esc_html( $cert->display_name ) . '</strong> completed <strong>' . esc_html( $cert->course_title ) . '</strong> on '
			. esc_html( SSLMS_DB::fmt_date( $cert->completed_at ) ) . '.</p>'
			. '</div>';
	}

	/** Light rate limit: 10 lookups per IP per rolling minute (transient). */
	private static function rate_limit_ok(): bool {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$key   = 'sslms_verify_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= 10 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/** Minimal public wrapper — no nav/consent notice, usable when logged out. */
	private static function public_wrap( string $title, string $inner_html ): string {
		return '<div class="sslms">'
			. '<h1 class="sslms-title">' . esc_html( $title ) . '</h1>'
			. $inner_html
			. '<footer class="sslms-footer">Sri Sathya Sai Institute of Higher Medical Sciences, Whitefield</footer>'
			. '</div>';
	}

	/**
	 * Hooked to sslms_lesson_view (fired by the course player after lesson
	 * content). Renders a "mark complete" button, or a completed badge.
	 */
	public static function render_lesson_complete_button( $lesson, $course ): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'sslms_learn' ) || ! is_object( $lesson ) || empty( $lesson->id ) ) {
			return;
		}
		$user_id   = get_current_user_id();
		$lesson_id = (int) $lesson->id;

		global $wpdb;
		$t   = SSLMS_DB::table( 'lesson_progress' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT completed_at FROM {$t} WHERE lesson_id = %d AND user_id = %d",
			$lesson_id,
			$user_id
		) );

		if ( $row ) {
			echo '<p class="sslms-badge sslms-badge--ok">Completed ' . esc_html( SSLMS_DB::fmt_date( $row->completed_at ) ) . '</p>';
			return;
		}

		echo '<form data-sslms-endpoint="' . esc_attr( 'lessons/' . $lesson_id . '/complete' ) . '" data-sslms-method="POST">'
			. '<button type="submit" class="sslms-btn">Mark lesson complete</button>'
			. '</form>';
	}

	/** sslms_dashboard_cards filter — certificates count card, only when > 0. */
	public static function dashboard_card( array $cards ): array {
		if ( ! is_user_logged_in() ) {
			return $cards;
		}
		$count = count( SSLMS_Certificates::by_user( get_current_user_id() ) );
		if ( $count > 0 ) {
			$cards[] = array(
				'title' => 'Certificates',
				'html'  => '<p><a href="' . esc_url( SSLMS_Portal::page_url( 'my_certificates' ) ) . '">' . (int) $count . ' earned</a></p>',
			);
		}
		return $cards;
	}
}
SSLMS_Portal_Certificates::init();
