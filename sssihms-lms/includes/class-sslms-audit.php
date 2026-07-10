<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only audit log (SPEC F10). Write path only — no update/delete API exists.
 */
class SSLMS_Audit {

	public static function log( string $action, string $object_type = '', int $object_id = 0, string $summary = '' ): void {
		$ip  = $_SERVER['REMOTE_ADDR'] ?? '';
		$bin = $ip ? @inet_pton( $ip ) : null;
		SSLMS_DB::insert( 'audit_log', array(
			'user_id'     => get_current_user_id(),
			'action'      => substr( $action, 0, 64 ),
			'object_type' => substr( $object_type, 0, 40 ),
			'object_id'   => $object_id,
			'summary'     => $summary,
			'ip'          => $bin ?: null,
			'created_at'  => SSLMS_DB::now(),
		) );
	}
}
