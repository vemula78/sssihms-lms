<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin helpers around $wpdb for the sslms_ tables.
 */
class SSLMS_DB {

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'sslms_' . $name;
	}

	/** Insert and return the new row id (0 on failure). */
	public static function insert( string $table, array $data ): int {
		global $wpdb;
		$ok = $wpdb->insert( self::table( $table ), $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update( string $table, array $data, array $where ): bool {
		global $wpdb;
		return false !== $wpdb->update( self::table( $table ), $data, $where );
	}

	public static function get_row( string $table, int $id ): ?object {
		global $wpdb;
		$t = self::table( $table );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ) ?: null;
	}

	/** Fetch one row by an arbitrary integer column. */
	public static function get_row_by( string $table, string $column, int $value ): ?object {
		global $wpdb;
		$t   = self::table( $table );
		$col = preg_replace( '/[^a-z_]/', '', $column );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$col} = %d", $value ) ) ?: null;
	}

	public static function delete( string $table, array $where ): bool {
		global $wpdb;
		return false !== $wpdb->delete( self::table( $table ), $where );
	}

	/** Current time in MySQL format (site timezone-agnostic UTC). */
	public static function now(): string {
		return current_time( 'mysql', true );
	}

	/** DD-MMM-YYYY display format used across the LMS. */
	public static function fmt_date( ?string $mysql_date ): string {
		if ( ! $mysql_date || '0000-00-00' === substr( $mysql_date, 0, 10 ) ) {
			return '—';
		}
		$ts = strtotime( $mysql_date );
		return $ts ? date_i18n( 'd-M-Y', $ts ) : '—';
	}
}
