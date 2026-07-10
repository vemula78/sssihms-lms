<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Top-level LMS admin menu. Module screens attach submenus with parent slug
 * 'sslms' on admin_menu priority >= 20.
 */
class SSLMS_Admin_Menu {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 9 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function register(): void {
		add_menu_page(
			'SSSIHMS LMS',
			'SSSIHMS LMS',
			'sslms_view_reports',
			'sslms',
			array( __CLASS__, 'render_home' ),
			'dashicons-welcome-learn-more',
			31
		);
	}

	public static function assets( string $hook ): void {
		if ( strpos( $hook, 'sslms' ) === false ) {
			return;
		}
		wp_enqueue_style( 'sslms-admin', SSLMS_URL . 'assets/css/sslms-admin.css', array(), SSLMS_VERSION );
		wp_enqueue_script( 'sslms', SSLMS_URL . 'assets/js/sslms.js', array(), SSLMS_VERSION, true );
		wp_localize_script( 'sslms', 'SSLMS_CFG', array(
			'root'  => esc_url_raw( rest_url( 'sslms/v1' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		) );
		wp_enqueue_script( 'sslms-chartjs', SSLMS_URL . 'assets/js/chart.umd.js', array(), '4.4.0', true );
	}

	/** Landing screen — replaced as default by the analytics dashboard module. */
	public static function render_home(): void {
		do_action( 'sslms_admin_home' );
	}
}
SSLMS_Admin_Menu::init();
