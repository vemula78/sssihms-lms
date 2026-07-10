<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module F — audit log viewer (SPEC F10.3). STRICTLY read-only: no delete or
 * update path exists anywhere in this file or the audit_log table (append-
 * only, see includes/class-sslms-audit.php). The filtered-query builder here
 * is also called by SSLMS_REST_People::audit_export() so the CSV export uses
 * exactly the same WHERE clause as the on-screen table.
 */
class SSLMS_Admin_Audit {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'sslms',
			__( 'Audit Log', 'sssihms-lms' ),
			__( 'Audit Log', 'sssihms-lms' ),
			'sslms_view_audit',
			'sslms-audit',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Read filters from a request-like array ($_GET or REST request params).
	 * Returns a normalised filter array; values are validated/typed here so
	 * every caller (admin screen, REST export) gets the same semantics.
	 */
	public static function filters_from_request( array $req ): array {
		$user_id   = isset( $req['user_id'] ) ? absint( $req['user_id'] ) : 0;
		$action    = isset( $req['action'] ) ? sanitize_text_field( (string) $req['action'] ) : '';
		$date_from = isset( $req['date_from'] ) ? sanitize_text_field( (string) $req['date_from'] ) : '';
		$date_to   = isset( $req['date_to'] ) ? sanitize_text_field( (string) $req['date_to'] ) : '';

		// Only accept well-formed dates; otherwise ignore the bound.
		if ( $date_from && ! strtotime( $date_from ) ) {
			$date_from = '';
		}
		if ( $date_to && ! strtotime( $date_to ) ) {
			$date_to = '';
		}

		return array(
			'user_id'   => $user_id,
			'action'    => $action,
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);
	}

	/** Builds the WHERE clause + prepared params shared by list/count/export. */
	private static function build_where( array $filters ): array {
		$conditions = array( '1=1' );
		$params     = array();

		if ( ! empty( $filters['user_id'] ) ) {
			$conditions[] = 'a.user_id = %d';
			$params[]     = $filters['user_id'];
		}
		if ( '' !== $filters['action'] ) {
			$conditions[] = 'a.action LIKE %s';
			$params[]     = '%' . $GLOBALS['wpdb']->esc_like( $filters['action'] ) . '%';
		}
		if ( '' !== $filters['date_from'] ) {
			$conditions[] = 'a.created_at >= %s';
			$params[]     = gmdate( 'Y-m-d 00:00:00', strtotime( $filters['date_from'] ) );
		}
		if ( '' !== $filters['date_to'] ) {
			$conditions[] = 'a.created_at <= %s';
			$params[]     = gmdate( 'Y-m-d 23:59:59', strtotime( $filters['date_to'] ) );
		}

		return array( implode( ' AND ', $conditions ), $params );
	}

	public static function get_count( array $filters ): int {
		global $wpdb;
		$table            = SSLMS_DB::table( 'audit_log' );
		list( $where, $params ) = self::build_where( $filters );
		$sql = "SELECT COUNT(*) FROM {$table} a WHERE {$where}";
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
		return (int) $wpdb->get_var( $sql );
	}

	/** Paginated rows for the on-screen table. */
	public static function get_rows( array $filters, int $per_page = 50, int $page = 1 ): array {
		global $wpdb;
		$table = SSLMS_DB::table( 'audit_log' );
		list( $where, $params ) = self::build_where( $filters );
		$offset   = max( 0, ( max( 1, $page ) - 1 ) * $per_page );
		$params[] = $per_page;
		$params[] = $offset;

		$sql = "SELECT a.*, u.display_name
		        FROM {$table} a
		        LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
		        WHERE {$where}
		        ORDER BY a.created_at DESC, a.id DESC
		        LIMIT %d OFFSET %d";
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/** All matching rows (no pagination) for CSV export, capped for safety. */
	public static function get_all_rows( array $filters, int $cap = 20000 ): array {
		global $wpdb;
		$table = SSLMS_DB::table( 'audit_log' );
		list( $where, $params ) = self::build_where( $filters );
		$params[] = $cap;

		$sql = "SELECT a.*, u.display_name
		        FROM {$table} a
		        LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
		        WHERE {$where}
		        ORDER BY a.created_at DESC, a.id DESC
		        LIMIT %d";
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/** Distinct users appearing in the log, for the filter dropdown. */
	private static function users_in_log(): array {
		global $wpdb;
		$table = SSLMS_DB::table( 'audit_log' );
		return (array) $wpdb->get_results(
			"SELECT DISTINCT a.user_id, u.display_name
			 FROM {$table} a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
			 ORDER BY u.display_name ASC"
		);
	}

	private static function ip_to_string( $binary ): string {
		if ( ! $binary ) {
			return '';
		}
		$ip = @inet_ntop( $binary );
		return $ip ? $ip : '';
	}

	public static function render(): void {
		if ( ! current_user_can( 'sslms_view_audit' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'sssihms-lms' ) );
		}

		$filters  = self::filters_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 50;
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$total = self::get_count( $filters );
		$rows  = self::get_rows( $filters, $per_page, $page );
		$pages = max( 1, (int) ceil( $total / $per_page ) );

		$users = self::users_in_log();

		$export_args = array_filter( array(
			'user_id'   => $filters['user_id'] ?: null,
			'action'    => $filters['action'] ?: null,
			'date_from' => $filters['date_from'] ?: null,
			'date_to'   => $filters['date_to'] ?: null,
			'_wpnonce'  => wp_create_nonce( 'wp_rest' ),
		), static function ( $v ) {
			return null !== $v && '' !== $v;
		} );
		$export_url = add_query_arg( $export_args, rest_url( 'sslms/v1/audit/export' ) );

		echo '<div class="wrap sslms"><h1>' . esc_html__( 'Audit Log', 'sssihms-lms' ) . '</h1>';

		echo '<form method="get" class="sslms-flex" style="margin:12px 0;">';
		echo '<input type="hidden" name="page" value="sslms-audit" />';

		echo '<select name="user_id"><option value="0">' . esc_html__( 'All users', 'sssihms-lms' ) . '</option>';
		foreach ( $users as $u ) {
			$label = $u->display_name ? $u->display_name : ( '#' . (int) $u->user_id );
			printf( '<option value="%d"%s>%s</option>', (int) $u->user_id, selected( $filters['user_id'], (int) $u->user_id, false ), esc_html( $label ) );
		}
		echo '</select>';

		printf( '<input type="text" name="action" placeholder="%s" value="%s" />', esc_attr__( 'Action contains…', 'sssihms-lms' ), esc_attr( $filters['action'] ) );
		printf( '<label>%s <input type="date" name="date_from" value="%s" /></label>', esc_html__( 'From', 'sssihms-lms' ), esc_attr( $filters['date_from'] ) );
		printf( '<label>%s <input type="date" name="date_to" value="%s" /></label>', esc_html__( 'To', 'sssihms-lms' ), esc_attr( $filters['date_to'] ) );
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'sssihms-lms' ) . '</button>';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=sslms-audit' ) ) . '">' . esc_html__( 'Reset', 'sssihms-lms' ) . '</a>';
		echo '<a class="button button-primary" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'sssihms-lms' ) . '</a>';
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Time', 'sssihms-lms' ) . '</th>'
			. '<th>' . esc_html__( 'User', 'sssihms-lms' ) . '</th>'
			. '<th>' . esc_html__( 'Action', 'sssihms-lms' ) . '</th>'
			. '<th>' . esc_html__( 'Object', 'sssihms-lms' ) . '</th>'
			. '<th>' . esc_html__( 'Summary', 'sssihms-lms' ) . '</th>'
			. '<th>' . esc_html__( 'IP', 'sssihms-lms' ) . '</th>'
			. '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No matching audit entries.', 'sssihms-lms' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			$ts    = strtotime( $row->created_at );
			$time  = $ts ? date_i18n( 'd-M-Y H:i', $ts ) : '—';
			$user  = $row->display_name ? $row->display_name : ( '#' . (int) $row->user_id );
			$object = $row->object_type ? $row->object_type . ( $row->object_id ? ' #' . (int) $row->object_id : '' ) : '—';
			echo '<tr>'
				. '<td>' . esc_html( $time ) . '</td>'
				. '<td>' . esc_html( $user ) . '</td>'
				. '<td>' . esc_html( $row->action ) . '</td>'
				. '<td>' . esc_html( $object ) . '</td>'
				. '<td>' . esc_html( $row->summary ) . '</td>'
				. '<td>' . esc_html( self::ip_to_string( $row->ip ) ) . '</td>'
				. '</tr>';
		}
		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			for ( $p = 1; $p <= $pages; $p++ ) {
				$url = add_query_arg( array_merge( $_GET, array( 'paged' => $p ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$class = $p === $page ? ' class="button button-primary"' : ' class="button"';
				echo '<a' . $class . ' style="margin:2px;" href="' . esc_url( $url ) . '">' . esc_html( (string) $p ) . '</a> ';
			}
			echo '</div></div>';
		}

		echo '</div>';
	}
}
SSLMS_Admin_Audit::init();
