<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen — Skills Checklists (F6): template editor + assignment panel.
 * All mutations go through the sslms/v1 REST API via the shared JS
 * (assets/js/sslms.js), wired by wrapping page markup in a `.sslms` element
 * (admin CSS itself is unaffected — sslms.css is not enqueued in wp-admin).
 */
class SSLMS_Admin_Checklists {

	const CAP = 'sslms_author_courses';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 20 );
	}

	public static function register(): void {
		add_submenu_page(
			'sslms',
			'Skills Checklists',
			'Skills Checklists',
			self::CAP,
			'sslms-checklists',
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not permitted.' );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>Skills Checklists</h1><div class="sslms">';
		if ( $id ) {
			self::render_editor( $id );
		} else {
			self::render_list();
		}
		echo '</div></div>';
		self::inline_script();
	}

	private static function render_list(): void {
		$checklists = SSLMS_Checklists::list_checklists();
		echo '<h2>Templates</h2>';
		if ( $checklists ) {
			echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>Title</th><th>Discipline</th><th>Items</th><th>Active</th><th></th></tr></thead><tbody>';
			foreach ( $checklists as $c ) {
				$item_count = count( SSLMS_Checklists::get_items( (int) $c->id ) );
				$edit_url   = esc_url( add_query_arg( array( 'page' => 'sslms-checklists', 'id' => $c->id ), admin_url( 'admin.php' ) ) );
				echo '<tr>';
				echo '<td><a href="' . $edit_url . '">' . esc_html( $c->title ) . '</a></td>';
				echo '<td>' . esc_html( $c->discipline ) . '</td>';
				echo '<td>' . (int) $item_count . '</td>';
				echo '<td>' . ( $c->is_active ? '<span class="sslms-badge sslms-badge--ok">active</span>' : '<span class="sslms-badge sslms-badge--muted">inactive</span>' ) . '</td>';
				echo '<td><a href="' . $edit_url . '" class="sslms-btn sslms-btn--ghost">Open</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No checklist templates yet.</p>';
		}

		echo '<h2>Add new template</h2>';
		echo '<form data-sslms-endpoint="checklists" data-sslms-method="POST">';
		echo '<label>Title<input type="text" name="title" required></label>';
		echo '<label>Discipline<input type="text" name="discipline" placeholder="e.g. Nursing, Cardiology"></label>';
		echo '<label>Description<textarea name="description" rows="3"></textarea></label>';
		echo '<button type="submit" class="sslms-btn">Create</button>';
		echo '</form>';
	}

	private static function render_editor( int $id ): void {
		$checklist = SSLMS_Checklists::get_checklist( $id );
		if ( ! $checklist ) {
			echo '<p>Checklist not found.</p>';
			return;
		}
		$back = esc_url( add_query_arg( array( 'page' => 'sslms-checklists' ), admin_url( 'admin.php' ) ) );
		echo '<p><a href="' . $back . '">&larr; All templates</a></p>';
		echo '<h2>' . esc_html( $checklist->title ) . '</h2>';

		echo '<fieldset><legend>Template details</legend>';
		echo '<form data-sslms-endpoint="checklists/' . (int) $id . '" data-sslms-method="PUT">';
		echo '<label>Title<input type="text" name="title" value="' . esc_attr( $checklist->title ) . '" required></label>';
		echo '<label>Discipline<input type="text" name="discipline" value="' . esc_attr( $checklist->discipline ) . '"></label>';
		echo '<label>Description<textarea name="description" rows="3">' . esc_textarea( $checklist->description ) . '</textarea></label>';
		echo '<label><input type="checkbox" name="is_active" value="1" style="width:auto" ' . checked( $checklist->is_active, 1, false ) . '> Active</label>';
		echo '<button type="submit" class="sslms-btn">Save</button>';
		echo '</form></fieldset>';

		echo '<fieldset><legend>Checklist items</legend>';
		$items = SSLMS_Checklists::get_items( $id );
		if ( $items ) {
			echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>#</th><th>Item</th><th></th></tr></thead><tbody>';
			$n = count( $items );
			foreach ( $items as $i => $item ) {
				echo '<tr>';
				echo '<td>' . ( $i + 1 ) . '</td>';
				echo '<td><form data-sslms-endpoint="checklists/items/' . (int) $item->id . '" data-sslms-method="PUT" data-sslms-noreload style="margin:0">'
					. '<input type="text" name="item_text" value="' . esc_attr( $item->item_text ) . '" style="display:inline-block;width:70%">'
					. '<button type="submit" class="sslms-btn sslms-btn--ghost">Save</button></form></td>';
				echo '<td class="sslms-flex">';
				echo '<button type="button" class="sslms-btn sslms-btn--ghost" data-sslms-move="up" data-item="' . (int) $item->id . '" ' . ( 0 === $i ? 'disabled' : '' ) . '>&uarr;</button>';
				echo '<button type="button" class="sslms-btn sslms-btn--ghost" data-sslms-move="down" data-item="' . (int) $item->id . '" ' . ( $i === $n - 1 ? 'disabled' : '' ) . '>&darr;</button>';
				echo '<button type="button" class="sslms-btn sslms-btn--danger" data-sslms-delete-item="' . (int) $item->id . '">Delete</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No items yet.</p>';
		}
		echo '<form data-sslms-endpoint="checklists/' . (int) $id . '/items" data-sslms-method="POST">';
		echo '<label>New item<input type="text" name="item_text" required></label>';
		echo '<button type="submit" class="sslms-btn">Add item</button>';
		echo '</form></fieldset>';

		self::render_assignment_panel( $id );
	}

	private static function render_assignment_panel( int $checklist_id ): void {
		echo '<fieldset><legend>Assignments</legend>';
		$assignments = SSLMS_Checklists::assignments_for_checklist( $checklist_id );
		if ( $assignments ) {
			echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>Learner</th><th>Progress</th><th>Status</th><th></th></tr></thead><tbody>';
			foreach ( $assignments as $a ) {
				$user    = get_userdata( (int) $a->user_id );
				$summary = SSLMS_Checklists::assignment_summary( (int) $a->id );
				echo '<tr>';
				echo '<td>' . esc_html( $user ? $user->display_name : ( 'User ' . $a->user_id ) ) . '</td>';
				echo '<td>' . (int) $summary['competent'] . ' / ' . (int) $summary['total'] . ' competent</td>';
				if ( $a->locked ) {
					echo '<td><span class="sslms-badge sslms-badge--ok">completed &amp; locked</span></td>';
				} else {
					echo '<td><span class="sslms-badge sslms-badge--warn">in progress</span></td>';
				}
				echo '<td>';
				if ( $a->locked && current_user_can( 'sslms_manage' ) ) {
					echo '<button type="button" class="sslms-btn sslms-btn--ghost" data-sslms-unlock="' . (int) $a->id . '">Unlock</button>';
				}
				echo ' <a class="sslms-btn sslms-btn--ghost" href="' . esc_url( rest_url( 'sslms/v1/checklists/assignments/' . $a->id . '/export' ) ) . '">CSV</a>';
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No learners assigned yet.</p>';
		}

		echo '<h3>Assign to a learner</h3>';
		$learners = get_users( array( 'capability' => array( 'sslms_learn' ), 'orderby' => 'display_name' ) );
		echo '<form data-sslms-endpoint="checklists/assignments" data-sslms-method="POST">';
		echo '<input type="hidden" name="checklist_id" value="' . (int) $checklist_id . '">';
		echo '<label>Learner<select name="user_id" required><option value="">— choose —</option>';
		foreach ( $learners as $u ) {
			echo '<option value="' . (int) $u->ID . '">' . esc_html( $u->display_name ) . '</option>';
		}
		echo '</select></label>';
		echo '<button type="submit" class="sslms-btn">Assign</button>';
		echo '</form></fieldset>';
	}

	/** Small glue script for actions the generic form-wiring doesn't cover. */
	private static function inline_script(): void {
		?>
		<script>
		(function () {
			document.addEventListener('click', function (ev) {
				var del = ev.target.closest('[data-sslms-delete-item]');
				if (del) {
					if (!confirm('Delete this item?')) { return; }
					SSLMS.api('checklists/items/' + del.getAttribute('data-sslms-delete-item'), { method: 'DELETE' })
						.then(function () { window.location.reload(); })
						.catch(function (e) { alert(e.message); });
					return;
				}
				var unlock = ev.target.closest('[data-sslms-unlock]');
				if (unlock) {
					if (!confirm('Unlock this checklist for further edits?')) { return; }
					SSLMS.api('checklists/assignments/' + unlock.getAttribute('data-sslms-unlock') + '/unlock', { method: 'POST' })
						.then(function () { window.location.reload(); })
						.catch(function (e) { alert(e.message); });
					return;
				}
				var move = ev.target.closest('[data-sslms-move]');
				if (move) {
					var row = move.closest('tr');
					var dir = move.getAttribute('data-sslms-move');
					var sib = dir === 'up' ? row.previousElementSibling : row.nextElementSibling;
					if (!sib) { return; }
					dir === 'up' ? row.parentNode.insertBefore(row, sib) : row.parentNode.insertBefore(sib, row);
					var ids = Array.prototype.map.call(row.parentNode.querySelectorAll('[data-sslms-move="up"]'), function (btn) {
						return btn.getAttribute('data-item');
					});
					SSLMS.api('checklists/<?php echo (int) ( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>/items/reorder', {
						method: 'POST',
						body: { order: ids }
					}).then(function () { window.location.reload(); }).catch(function (e) { alert(e.message); });
				}
			});
		})();
		</script>
		<?php
	}
}
SSLMS_Admin_Checklists::init();
