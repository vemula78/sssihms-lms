<?php
/**
 * Plugin Name: SSSIHMS LMS
 * Description: Learning Management System for SSSIHMS Whitefield — clinical, allied-health and chaplaincy education tracks.
 * Version: 1.0.0
 * Author: SSSIHMS
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: sssihms-lms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SSLMS_VERSION', '1.0.0' );
define( 'SSLMS_FILE', __FILE__ );
define( 'SSLMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSLMS_URL', plugin_dir_url( __FILE__ ) );

require_once SSLMS_DIR . 'includes/class-sslms-db.php';
require_once SSLMS_DIR . 'includes/class-sslms-roles.php';
require_once SSLMS_DIR . 'includes/class-sslms-install.php';
require_once SSLMS_DIR . 'includes/class-sslms-audit.php';
require_once SSLMS_DIR . 'includes/rest/class-sslms-rest-base.php';
require_once SSLMS_DIR . 'public/class-sslms-portal.php';

/*
 * Module, REST controller, admin-screen and portal files self-register their
 * hooks when included. Dropping a file into one of these directories is the
 * only registration step a module needs.
 */
foreach ( array( 'includes/interfaces', 'includes/modules', 'includes/rest', 'admin', 'public' ) as $sslms_dir ) {
	foreach ( glob( SSLMS_DIR . $sslms_dir . '/*.php' ) ?: array() as $sslms_file ) {
		if ( in_array( basename( $sslms_file ), array( 'class-sslms-rest-base.php', 'class-sslms-portal.php' ), true ) ) {
			continue;
		}
		require_once $sslms_file;
	}
}
unset( $sslms_dir, $sslms_file );

register_activation_hook( __FILE__, array( 'SSLMS_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SSLMS_Install', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'SSLMS_Install', 'maybe_upgrade' ) );
