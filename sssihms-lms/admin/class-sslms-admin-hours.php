<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen — Rotations & Hours (F7): rotation editor + per-rotation log
 * table with totals and CSV export link.
 */
class SSLMS_Admin_Hours {

	const CAP = 'sslms_enroll_learners';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 20 );
	}

	public static function register(): void {
		add_submenu_page(
			'sslms',
			'Rotations & Hours',
			'Rotations & Hours',
			self::CAP,
			'sslms-hours',
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not permitted.' );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>Rotations &amp; Hours</h1><div class="sslms">';
		if ( $id ) {
			self::render_rotation( $id );
		} else {
			self::render_list();
		}
		echo '</div></div>';
		self::inline_script();
	}

	private static function learners(): array {
		return get_users( array( 'capability' => array( 'sslms_learn' ), 'orderby' => 'display_name' ) );
	}

	private static function preceptors(): array {
		return get_users( array( 'capability' => array( 'sslms_approve_hours' ), 'orderby' => 'display_name' ) );
	}

	private static function render_list(): void {
		$rotations = SSLMS_Hours::list_rotations();
		echo '<h2>Rotations</h2>';
		if ( $rotations ) {
			echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>Learner</th><th>Department</th><th>Dates</th><th>Preceptor</th><th>Hours (appr/req)</th><th></th></tr></thead><tbody>';
			foreach ( $rotations as $r ) {
				$learner   = get_userdata( (int) $r->user_id );
				$preceptor = get_userdata( (int) $r->preceptor_id );
				$totals    = SSLMS_Hours::totals( (int) $r->id );
				$edit_url  = esc_url( add_query_arg( array( 'page' => 'sslms-hours', 'id' => $r->id ), admin_url( 'admin.php' ) ) );
				echo '<tr>';
				echo '<td><a href="' . $edit_url . '">' . esc_html( $learner ? $learner->display_name : ( 'User ' . $r->user_id ) ) . '</a></td>';
				echo '<td>' . esc_html( $r->department ) . '</td>';
				echo '<td>' . esc_html( SSLMS_DB::fmt_date( $r->start_date ) . ' – ' . SSLMS_DB::fmt_date( $r->end_date ) ) . '</td>';
				echo '<td>' . esc_html( $preceptor ? $preceptor->display_name : '—' ) . '</td>';
				echo '<td>' . esc_html( number_format( (float) $totals['approved'], 2 ) . ' / ' . number_format( (float) $totals['required'], 2 ) ) . '</td>';
				echo '<td><a href="' . $edit_url . '" class="sslms-btn sslms-btn--ghost">Open</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No rotations yet.</p>';
		}

		echo '<h2>Add new rotation</h2>';
		echo '<form data-sslms-endpoint="rotations" data-sslms-method="POST">';
		echo '<label>Learner<select name="user_id" required><option value="">— choose —</option>';
		foreach ( self::learners() as $u ) {
			echo '<option value="' . (int) $u->ID . '">' . esc_html( $u->display_name ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Department<input type="text" name="department" required></label>';
		echo '<label>Start date<input type="date" name="start_date" required></label>';
		echo '<label>End date<input type="date" name="end_date" required></label>';
		echo '<label>Preceptor<select name="preceptor_id" required><option value="">— choose —</option>';
		foreach ( self::preceptors() as $u ) {
			echo '<option value="' . (int) $u->ID . '">' . esc_html( $u->display_name ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Required hours<input type="number" step="0.25" min="0" name="required_hours" required></label>';
		echo '<button type="submit" class="sslms-btn">Create</button>';
		echo '</form>';
	}

	private static function render_rotation( int $id ): void {
		$rotation = SSLMS_Hours::get_rotation( $id );
		if ( ! $rotation ) {
			echo '<p>Rotation not found.</p>';
			return;
		}
		$back    = esc_url( add_query_arg( array( 'page' => 'sslms-hours' ), admin_url( 'admin.php' ) ) );
		$learner = get_userdata( (int) $rotation->user_id );
		echo '<p><a href="' . $back . '">&larr; All rotations</a></p>';
		echo '<h2>' . esc_html( $learner ? $learner->display_name : ( 'User ' . $rotation->user_id ) ) . ' — ' . esc_html( $rotation->department ) . '</h2>';

		echo '<fieldset><legend>Rotation details</legend>';
		echo '<form data-sslms-endpoint="rotations/' . (int) $id . '" data-sslms-method="PUT">';
		echo '<label>Department<input type="text" name="department" value="' . esc_attr( $rotation->department ) . '"></label>';
		echo '<label>Start date<input type="date" name="start_date" value="' . esc_attr( $rotation->start_date ) . '"></label>';
		echo '<label>End date<input type="date" name="end_date" value="' . esc_attr( $rotation->end_date ) . '"></label>';
		echo '<label>Preceptor<select name="preceptor_id">';
		foreach ( self::preceptors() as $u ) {
			echo '<option value="' . (int) $u->ID . '" ' . selected( (int) $rotation->preceptor_id, $u->ID, false ) . '>' . esc_html( $u->display_name ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Required hours<input type="number" step="0.25" min="0" name="required_hours" value="' . esc_attr( $rotation->required_hours ) . '"></label>';
		echo '<button type="submit" class="sslms-btn">Save</button>';
		echo '</form></fieldset>';

		$totals = SSLMS_Hours::totals( $id );
		echo '<p><strong>Totals:</strong> ' . esc_html( number_format( (float) $totals['approved'], 2 ) ) . ' approved / '
			. esc_html( number_format( (float) $totals['pending'], 2 ) ) . ' pending / required '
			. esc_html( number_format( (float) $totals['required'], 2 ) ) . ' hours</p>';

		echo '<fieldset><legend>Logged hours <a class="sslms-btn sslms-btn--ghost" href="' . esc_url( rest_url( 'sslms/v1/hours/export/rotation/' . $id ) ) . '">Export CSV</a></legend>';
		$logs = SSLMS_Hours::logs_for_rotation( $id );
		if ( $logs ) {
			echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>Date</th><th>Hours</th><th>Activity</th><th>Status</th><th>Note</th></tr></thead><tbody>';
			foreach ( $logs as $log ) {
				$badge = 'muted';
				if ( 'approved' === $log->status ) {
					$badge = 'ok';
				} elseif ( 'rejected' === $log->status ) {
					$badge = 'bad';
				} elseif ( 'pending' === $log->status ) {
					$badge = 'warn';
				}
				echo '<tr>';
				echo '<td>' . esc_html( SSLMS_DB::fmt_date( $log->log_date ) ) . '</td>';
				echo '<td>' . esc_html( $log->hours ) . '</td>';
				echo '<td>' . esc_html( $log->activity ) . '</td>';
				echo '<td><span class="sslms-badge sslms-badge--' . esc_attr( $badge ) . '">' . esc_html( $log->status ) . '</span></td>';
				echo '<td>' . esc_html( $log->review_note ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No hours logged yet.</p>';
		}
		echo '</fieldset>';
	}

	private static function inline_script(): void {
		?>
		<script>
		(function () {
			// Reserved for future admin-only hours actions; approvals happen in the portal.
		})();
		</script>
		<?php
	}
}
SSLMS_Admin_Hours::init();
