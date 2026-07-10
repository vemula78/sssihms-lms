<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module F — People screen (SPEC F1.1): LMS profile editor + relationships
 * manager (mentor/preceptor/evaluator assignments). Mutations happen via the
 * sslms/v1 REST routes in includes/rest/class-sslms-rest-people.php, posted
 * with the shared assets/js/sslms.js helper (data-sslms-endpoint forms).
 *
 * Role assignment itself (which WP role a user holds) is NOT done here — see
 * the note rendered at the bottom of the screen; it happens on the standard
 * WP Users screen, per SPEC F1.1.
 */
class SSLMS_Admin_People {

	const TRACKS = array( 'clinical', 'allied', 'chaplaincy' );

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'sslms',
			__( 'People', 'sssihms-lms' ),
			__( 'People', 'sssihms-lms' ),
			'sslms_manage',
			'sslms-people',
			array( __CLASS__, 'render' )
		);
	}

	/** Users holding capability $cap, deduplicated, sorted by display name. */
	public static function users_with_cap( string $cap ): array {
		$users = get_users( array(
			'capability' => $cap,
			'orderby'    => 'display_name',
			'order'      => 'ASC',
		) );
		return is_array( $users ) ? $users : array();
	}

	/** Users holding ANY of the given capabilities, deduplicated by ID. */
	public static function users_with_any_cap( array $caps ): array {
		$by_id = array();
		foreach ( $caps as $cap ) {
			foreach ( self::users_with_cap( $cap ) as $u ) {
				$by_id[ $u->ID ] = $u;
			}
		}
		uasort( $by_id, static function ( $a, $b ) {
			return strcasecmp( $a->display_name, $b->display_name );
		} );
		return array_values( $by_id );
	}

	private static function users_option( array $users, int $selected = 0 ): string {
		$html = '';
		foreach ( $users as $u ) {
			$html .= sprintf(
				'<option value="%d"%s>%s</option>',
				(int) $u->ID,
				selected( $selected, (int) $u->ID, false ),
				esc_html( $u->display_name . ' (' . $u->user_login . ')' )
			);
		}
		return $html;
	}

	public static function render(): void {
		if ( ! current_user_can( 'sslms_manage' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'sssihms-lms' ) );
		}

		echo '<div class="wrap sslms"><h1>' . esc_html__( 'People', 'sssihms-lms' ) . '</h1>';

		self::render_profile_editor();
		self::render_relationships();

		echo '<p class="description">' . sprintf(
			/* translators: %s: link to the WP Users screen. */
			esc_html__( 'Role assignment (Instructor, Student, Preceptor, Evaluator, Mentor) is done on the standard WordPress %s, not here.', 'sssihms-lms' ),
			'<a href="' . esc_url( admin_url( 'users.php' ) ) . '">' . esc_html__( 'Users screen', 'sssihms-lms' ) . '</a>'
		) . '</p>';

		echo '</div>';
	}

	/* ---------------------------------------------------------------- */
	/* (a) LMS profile editor                                            */
	/* ---------------------------------------------------------------- */

	private static function render_profile_editor(): void {
		$lms_users = self::users_with_any_cap( SSLMS_Roles::ALL_CAPS );
		$edit_id   = isset( $_GET['profile_user'] ) ? absint( $_GET['profile_user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<h2>' . esc_html__( 'LMS Profile', 'sssihms-lms' ) . '</h2>';

		echo '<form method="get" class="sslms-flex" style="margin-bottom:10px;">';
		echo '<input type="hidden" name="page" value="sslms-people" />';
		echo '<select name="profile_user">';
		echo '<option value="0">' . esc_html__( '— Select a user —', 'sssihms-lms' ) . '</option>';
		echo self::users_option( $lms_users, $edit_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</select>';
		echo '<button type="submit" class="button">' . esc_html__( 'Load', 'sssihms-lms' ) . '</button>';
		echo '</form>';

		if ( ! $edit_id || ! get_userdata( $edit_id ) ) {
			echo '<p class="description">' . esc_html__( 'Select a user above to edit their LMS profile.', 'sssihms-lms' ) . '</p>';
			return;
		}

		$profile = SSLMS_DB::get_row_by( 'profiles', 'user_id', $edit_id );
		$staff_id   = $profile->staff_id ?? '';
		$discipline = $profile->discipline ?? '';
		$tracks     = $profile && $profile->tracks ? array_filter( array_map( 'trim', explode( ',', $profile->tracks ) ) ) : array();
		$consent_at = $profile->consent_at ?? null;

		echo '<form data-sslms-endpoint="people/profile" data-sslms-method="POST" data-sslms-noreload class="sslms-flex" style="align-items:flex-start;flex-direction:column;max-width:520px;">';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $edit_id ) . '" />';

		printf( '<label style="width:100%%">%s<br /><input type="text" name="staff_id" maxlength="50" value="%s" style="width:100%%" /></label>', esc_html__( 'Staff/Student ID', 'sssihms-lms' ), esc_attr( $staff_id ) );
		printf( '<label style="width:100%%">%s<br /><input type="text" name="discipline" maxlength="100" value="%s" style="width:100%%" /></label>', esc_html__( 'Discipline', 'sssihms-lms' ), esc_attr( $discipline ) );

		echo '<fieldset><legend>' . esc_html__( 'Tracks', 'sssihms-lms' ) . '</legend>';
		foreach ( self::TRACKS as $t ) {
			printf(
				'<label style="margin-right:12px;"><input type="checkbox" name="tracks[]" value="%s"%s /> %s</label>',
				esc_attr( $t ),
				checked( in_array( $t, $tracks, true ), true, false ),
				esc_html( ucfirst( $t ) )
			);
		}
		echo '</fieldset>';

		echo '<p><strong>' . esc_html__( 'Consent recorded:', 'sssihms-lms' ) . '</strong> ' . esc_html( SSLMS_DB::fmt_date( $consent_at ) ) . '</p>';

		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save Profile', 'sssihms-lms' ) . '</button>';
		echo '</form>';
	}

	/* ---------------------------------------------------------------- */
	/* (b) Relationships manager                                         */
	/* ---------------------------------------------------------------- */

	private static function render_relationships(): void {
		global $wpdb;
		$table = SSLMS_DB::table( 'relationships' );

		echo '<h2>' . esc_html__( 'Relationships (Mentor / Preceptor / Evaluator)', 'sssihms-lms' ) . '</h2>';

		$rows = $wpdb->get_results(
			"SELECT r.*, ul.display_name AS learner_name, ur.display_name AS related_name
			 FROM {$table} r
			 LEFT JOIN {$wpdb->users} ul ON ul.ID = r.user_id
			 LEFT JOIN {$wpdb->users} ur ON ur.ID = r.related_user_id
			 ORDER BY r.created_at DESC"
		);

		echo '<table class="widefat striped" style="max-width:760px;"><thead><tr>'
			. '<th>' . esc_html__( 'Learner', 'sssihms-lms' ) . '</th>'
			. '<th>' . esc_html__( 'Related person', 'sssihms-lms' ) . '</th>'
			. '<th>' . esc_html__( 'Type', 'sssihms-lms' ) . '</th>'
			. '<th>' . esc_html__( 'Since', 'sssihms-lms' ) . '</th>'
			. '<th></th>'
			. '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No relationships defined yet.', 'sssihms-lms' ) . '</td></tr>';
		}

		foreach ( (array) $rows as $r ) {
			echo '<tr>'
				. '<td>' . esc_html( $r->learner_name ?: ( '#' . (int) $r->user_id ) ) . '</td>'
				. '<td>' . esc_html( $r->related_name ?: ( '#' . (int) $r->related_user_id ) ) . '</td>'
				. '<td>' . esc_html( ucfirst( $r->rel_type ) ) . '</td>'
				. '<td>' . esc_html( SSLMS_DB::fmt_date( $r->created_at ) ) . '</td>'
				. '<td><form data-sslms-endpoint="people/relationships/' . (int) $r->id . '" data-sslms-method="DELETE" style="margin:0;" onsubmit="if(!confirm(\'' . esc_js( __( 'Remove this relationship?', 'sssihms-lms' ) ) . '\')){event.preventDefault();event.stopPropagation();return false;}">'
				. '<button type="submit" class="button-link-delete">' . esc_html__( 'Remove', 'sssihms-lms' ) . '</button></form></td>'
				. '</tr>';
		}
		echo '</tbody></table>';

		$learners    = self::users_with_cap( 'sslms_learn' );
		$mentors     = self::users_with_cap( 'sslms_mentor_journals' );
		$preceptors  = self::users_with_any_cap( array( 'sslms_approve_hours' ) );
		$evaluators  = self::users_with_cap( 'sslms_signoff_checklists' );

		echo '<h3>' . esc_html__( 'Add relationship', 'sssihms-lms' ) . '</h3>';
		echo '<form data-sslms-endpoint="people/relationships" data-sslms-method="POST" class="sslms-flex">';

		echo '<select name="user_id" required>';
		echo '<option value="">' . esc_html__( '— Learner —', 'sssihms-lms' ) . '</option>';
		echo self::users_option( $learners ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</select>';

		echo '<select name="rel_type" id="sslms-rel-type" required>';
		echo '<option value="mentor">' . esc_html__( 'Mentor', 'sssihms-lms' ) . '</option>';
		echo '<option value="preceptor">' . esc_html__( 'Preceptor', 'sssihms-lms' ) . '</option>';
		echo '<option value="evaluator">' . esc_html__( 'Evaluator', 'sssihms-lms' ) . '</option>';
		echo '</select>';

		// All three related-person lists are rendered; the option groups let the
		// picker be filtered client-side by rel_type without another page load.
		echo '<select name="related_user_id" required>';
		echo '<optgroup label="' . esc_attr__( 'Mentors', 'sssihms-lms' ) . '" data-rel="mentor">' . self::users_option( $mentors ) . '</optgroup>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<optgroup label="' . esc_attr__( 'Preceptors', 'sssihms-lms' ) . '" data-rel="preceptor">' . self::users_option( $preceptors ) . '</optgroup>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<optgroup label="' . esc_attr__( 'Evaluators', 'sssihms-lms' ) . '" data-rel="evaluator">' . self::users_option( $evaluators ) . '</optgroup>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</select>';

		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Add', 'sssihms-lms' ) . '</button>';
		echo '</form>';

		echo '<script>document.addEventListener("DOMContentLoaded",function(){'
			. 'var t=document.getElementById("sslms-rel-type");if(!t)return;'
			. 'var sel=t.closest("form").querySelector("select[name=related_user_id]");'
			. 'function sync(){Array.prototype.forEach.call(sel.querySelectorAll("optgroup"),function(g){g.hidden=(g.getAttribute("data-rel")!==t.value);});'
			. 'var first=sel.querySelector("optgroup[data-rel=\'"+t.value+"\'] option");if(first){sel.value=first.value;}}'
			. 't.addEventListener("change",sync);sync();'
			. '});</script>';
	}
}
SSLMS_Admin_People::init();
