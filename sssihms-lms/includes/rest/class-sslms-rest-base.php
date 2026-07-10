<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base for all sslms/v1 REST controllers. Cookie-authenticated requests must
 * carry the standard X-WP-Nonce header (enforced by WP core); permission
 * callbacks here layer capability checks on top.
 */
abstract class SSLMS_REST_Base {

	const NS = 'sslms/v1';

	/** Permission callback requiring one LMS capability. */
	public static function can( string $cap ): callable {
		return static function () use ( $cap ) {
			return current_user_can( $cap )
				? true
				: new WP_Error( 'sslms_forbidden', 'Not permitted.', array( 'status' => rest_authorization_required_code() ) );
		};
	}

	/** Permission callback requiring any of several capabilities. */
	public static function can_any( array $caps ): callable {
		return static function () use ( $caps ) {
			foreach ( $caps as $cap ) {
				if ( current_user_can( $cap ) ) {
					return true;
				}
			}
			return new WP_Error( 'sslms_forbidden', 'Not permitted.', array( 'status' => rest_authorization_required_code() ) );
		};
	}

	public static function ok( $data = null, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => true, 'data' => $data ), $status );
	}

	public static function fail( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/** Stream a CSV download and exit. $rows = array of assoc arrays. */
	public static function send_csv( string $filename, array $header, array $rows ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, $header );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_values( $row ) );
		}
		fclose( $out );
		exit;
	}
}
