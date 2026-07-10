<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module F — read-only "Integrations (Phase 2)" screen (ADR-6). Lists the
 * four Phase-2 stub interface slots (all "Planned — Phase 2"; nothing is
 * implemented) plus whatever a Phase-2 add-on has registered via the
 * 'sslms_register_integrations' filter (see includes/interfaces/class-sslms-integrations.php).
 * No settings, no actions — informational only.
 */
class SSLMS_Admin_Integrations {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'sslms',
			__( 'Integrations (Phase 2)', 'sssihms-lms' ),
			__( 'Integrations (Phase 2)', 'sssihms-lms' ),
			'sslms_manage',
			'sslms-integrations',
			array( __CLASS__, 'render' )
		);
	}

	private static function slots(): array {
		return array(
			array(
				'name'      => __( 'SCORM / xAPI Adapter', 'sssihms-lms' ),
				'interface' => 'SSLMS_Scorm_Adapter_Interface',
				'desc'      => __( 'Package import and statement forwarding for SCORM/xAPI content.', 'sssihms-lms' ),
			),
			array(
				'name'      => __( 'Meeting Provider (Zoom/Teams)', 'sssihms-lms' ),
				'interface' => 'SSLMS_Meeting_Provider_Interface',
				'desc'      => __( 'Live-session creation and per-user join links.', 'sssihms-lms' ),
			),
			array(
				'name'      => __( 'HR Sync', 'sssihms-lms' ),
				'interface' => 'SSLMS_HR_Sync_Interface',
				'desc'      => __( 'User roster pull and deactivation from the hospital HR system.', 'sssihms-lms' ),
			),
			array(
				'name'      => __( 'Payment Gateway', 'sssihms-lms' ),
				'interface' => 'SSLMS_Payment_Gateway_Interface',
				'desc'      => __( 'Placeholder only; not used in Phase 1 — all education is free.', 'sssihms-lms' ),
			),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'sslms_manage' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'sssihms-lms' ) );
		}

		echo '<div class="wrap sslms"><h1>' . esc_html__( 'Integrations (Phase 2)', 'sssihms-lms' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'These are Phase-2 stub interfaces only (SPEC section 5). Nothing here is implemented or configurable in Phase 1.', 'sssihms-lms' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Slot', 'sssihms-lms' ) . '</th>'
			. '<th>' . esc_html__( 'Interface', 'sssihms-lms' ) . '</th>'
			. '<th>' . esc_html__( 'Description', 'sssihms-lms' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'sssihms-lms' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( self::slots() as $slot ) {
			echo '<tr>'
				. '<td>' . esc_html( $slot['name'] ) . '</td>'
				. '<td><code>' . esc_html( $slot['interface'] ) . '</code></td>'
				. '<td>' . esc_html( $slot['desc'] ) . '</td>'
				. '<td><span class="sslms-badge sslms-badge--muted">' . esc_html__( 'Planned — Phase 2', 'sssihms-lms' ) . '</span></td>'
				. '</tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Registered integrations', 'sssihms-lms' ) . '</h2>';

		$registered = class_exists( 'SSLMS_Integrations' ) ? SSLMS_Integrations::all() : array();

		if ( ! $registered ) {
			echo '<p>' . esc_html__( 'None registered.', 'sssihms-lms' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>'
				. '<th>' . esc_html__( 'Slug', 'sssihms-lms' ) . '</th>'
				. '<th>' . esc_html__( 'Class', 'sssihms-lms' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $registered as $slug => $instance ) {
				echo '<tr><td>' . esc_html( (string) $slug ) . '</td><td><code>' . esc_html( get_class( $instance ) ) . '</code></td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}
}
SSLMS_Admin_Integrations::init();
