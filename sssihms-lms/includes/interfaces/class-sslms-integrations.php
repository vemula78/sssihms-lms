<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module F — Phase 2 stub interfaces (SPEC section 5, ADR-6). No working
 * implementation lives in Phase 1; these define the contracts a future
 * integration must satisfy, plus a lightweight registry so a Phase-2 add-on
 * can register itself without touching this file.
 */

/** SCORM/xAPI package import & statement forwarding. */
interface SSLMS_Scorm_Adapter_Interface {
	/** Import a SCORM/xAPI package from a local file path. Returns a new course/module id, or WP_Error. */
	public function import_package( string $path ); // : int|WP_Error

	/** Forward an xAPI statement to the LRU/adapter. */
	public function send_statement( array $statement ): bool;
}

/** Zoom/Teams live-session integration. */
interface SSLMS_Meeting_Provider_Interface {
	/** Create a live session/meeting. Returns provider meeting data, or WP_Error. */
	public function create_meeting( array $args ); // : array|WP_Error

	/** Get a per-user join URL for an existing meeting. */
	public function get_join_url( string $meeting_id, int $user_id ): string;
}

/** HR system sync — user provisioning/deactivation. */
interface SSLMS_HR_Sync_Interface {
	/** Pull the current user/staff roster from HR. */
	public function pull_users(): array;

	/** Deactivate a user in the LMS by HR staff id (NABH retention preserved). */
	public function deactivate_user( string $staff_id ): bool;
}

/** Payments — placeholder only; not used in Phase 1 (all education is free). */
interface SSLMS_Payment_Gateway_Interface {
	/** Create a payment order. Returns order data, or WP_Error. */
	public function create_order( array $args ); // : array|WP_Error

	/** Verify a payment by its reference. */
	public function verify_payment( string $ref ): bool;
}

/**
 * Registry for Phase-2 integration instances. Populated exclusively via the
 * 'sslms_register_integrations' filter — no implementation ships in Phase 1.
 */
class SSLMS_Integrations {

	/** @var array<string,object> */
	private static $items = array();

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'load' ), 20 );
	}

	public static function load(): void {
		$registered = apply_filters( 'sslms_register_integrations', array() );
		if ( ! is_array( $registered ) ) {
			return;
		}
		foreach ( $registered as $slug => $instance ) {
			if ( is_string( $slug ) && '' !== $slug && is_object( $instance ) ) {
				self::register( $slug, $instance );
			}
		}
	}

	public static function register( string $slug, $instance ): void {
		self::$items[ $slug ] = $instance;
	}

	public static function get( string $slug ) {
		return self::$items[ $slug ] ?? null;
	}

	/** @return array<string,object> */
	public static function all(): array {
		return self::$items;
	}
}
SSLMS_Integrations::init();
