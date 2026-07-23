<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen — Branching Scenarios (Phase 3e): JSON importer (the
 * AI-assisted authoring entry point — paste a Claude-drafted scenario for
 * clinical review), node editor, graph validation, publish toggle.
 */
class SSLMS_Admin_Scenarios {

	const CAP = 'sslms_author_courses';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 20 );
	}

	public static function register(): void {
		add_submenu_page(
			'sslms',
			'Branching Scenarios',
			'Scenarios',
			self::CAP,
			'sslms-scenarios',
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not permitted.' );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>Branching Scenarios</h1><div class="sslms">';
		if ( $id ) {
			self::render_editor( $id );
		} else {
			self::render_list();
		}
		echo '</div></div>';
		self::inline_script();
	}

	private static function render_list(): void {
		$scenarios = SSLMS_Scenarios::list_scenarios();
		echo '<h2>Scenarios</h2>';
		if ( $scenarios ) {
			echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>Title</th><th>Discipline</th><th>Nodes</th><th>Pass %</th><th>Status</th><th></th></tr></thead><tbody>';
			foreach ( $scenarios as $s ) {
				$edit_url = esc_url( add_query_arg( array( 'page' => 'sslms-scenarios', 'id' => $s->id ), admin_url( 'admin.php' ) ) );
				echo '<tr>';
				echo '<td><a href="' . $edit_url . '">' . esc_html( $s->title ) . '</a></td>';
				echo '<td>' . esc_html( $s->discipline ) . '</td>';
				echo '<td>' . count( SSLMS_Scenarios::get_nodes( (int) $s->id ) ) . '</td>';
				echo '<td>' . esc_html( round( (float) $s->pass_pct, 1 ) ) . '</td>';
				echo '<td>' . ( 'published' === $s->status ? '<span class="sslms-badge sslms-badge--ok">published</span>' : '<span class="sslms-badge sslms-badge--muted">draft</span>' ) . '</td>';
				echo '<td><a href="' . $edit_url . '" class="sslms-btn sslms-btn--ghost">Open</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No scenarios yet.</p>';
		}

		echo '<h2>Import a drafted scenario (AI-assisted authoring)</h2>';
		echo '<p>Ask Claude for a branching scenario in the LMS JSON format (title, nodes with keys, options with marks/feedback/next), paste it here, review every branch in the editor, then publish. Fictional patients only.</p>';
		echo '<form data-sslms-endpoint="scenarios/import" data-sslms-method="POST">';
		echo '<label>Scenario JSON<textarea name="json" rows="10" required placeholder=\'{"title":"...","nodes":[{"key":"start","type":"decision","start":true,...}]}\'></textarea></label>';
		echo '<button type="submit" class="sslms-btn">Import as draft</button>';
		echo '</form>';

		echo '<h2>Or create empty</h2>';
		echo '<form data-sslms-endpoint="scenarios" data-sslms-method="POST">';
		echo '<label>Title<input type="text" name="title" required></label>';
		echo '<label>Discipline<input type="text" name="discipline"></label>';
		echo '<label>Pass %<input type="number" name="pass_pct" min="0" max="100" step="0.5" value="70"></label>';
		echo '<label>Description<textarea name="description" rows="2"></textarea></label>';
		echo '<button type="submit" class="sslms-btn">Create draft</button>';
		echo '</form>';
	}

	private static function render_editor( int $id ): void {
		$scenario = SSLMS_Scenarios::get_scenario( $id );
		if ( ! $scenario ) {
			echo '<p>Scenario not found.</p>';
			return;
		}
		$nodes    = SSLMS_Scenarios::get_nodes( $id );
		$problems = SSLMS_Scenarios::validate_graph( $id );
		$back     = esc_url( add_query_arg( array( 'page' => 'sslms-scenarios' ), admin_url( 'admin.php' ) ) );
		echo '<p><a href="' . $back . '">&larr; All scenarios</a></p>';
		echo '<h2>' . esc_html( $scenario->title ) . ' ' . ( 'published' === $scenario->status ? '<span class="sslms-badge sslms-badge--ok">published</span>' : '<span class="sslms-badge sslms-badge--muted">draft</span>' ) . '</h2>';

		if ( $problems ) {
			echo '<div class="sslms-card"><strong>Cannot publish yet:</strong><ul>';
			foreach ( $problems as $p ) {
				echo '<li>' . esc_html( $p ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '<fieldset><legend>Details</legend>';
		echo '<form data-sslms-endpoint="scenarios/' . (int) $id . '" data-sslms-method="PUT">';
		echo '<label>Title<input type="text" name="title" value="' . esc_attr( $scenario->title ) . '" required></label>';
		echo '<label>Discipline<input type="text" name="discipline" value="' . esc_attr( $scenario->discipline ) . '"></label>';
		echo '<label>Pass %<input type="number" name="pass_pct" min="0" max="100" step="0.5" value="' . esc_attr( (float) $scenario->pass_pct ) . '"></label>';
		echo '<label>Description<textarea name="description" rows="2">' . esc_textarea( $scenario->description ) . '</textarea></label>';
		echo '<label>Status<select name="status"><option value="draft" ' . selected( $scenario->status, 'draft', false ) . '>draft</option><option value="published" ' . selected( $scenario->status, 'published', false ) . '>published</option></select></label>';
		echo '<button type="submit" class="sslms-btn">Save</button>';
		echo '</form></fieldset>';

		echo '<fieldset><legend>Nodes</legend>';
		foreach ( $nodes as $node ) {
			echo '<div class="sslms-card">';
			echo '<h3>#' . (int) $node->id . ' ' . esc_html( $node->title ?: '(untitled)' )
				. ' <span class="sslms-badge sslms-badge--' . ( 'end' === $node->node_type ? 'ok' : 'muted' ) . '">' . esc_html( $node->node_type ) . '</span>'
				. ( $node->is_start ? ' <span class="sslms-badge sslms-badge--warn">start</span>' : '' ) . '</h3>';
			if ( $node->body ) {
				echo '<p>' . wp_kses_post( wpautop( $node->body ) ) . '</p>';
			}
			if ( $node->options ) {
				echo '<ul>';
				foreach ( $node->options as $option ) {
					echo '<li>&rarr; <strong>' . esc_html( $option['label'] ) . '</strong> (marks ' . esc_html( (float) $option['marks'] ) . ', next #' . (int) $option['next'] . ')'
						. ( $option['feedback'] ? '<br><em>' . esc_html( $option['feedback'] ) . '</em>' : '' ) . '</li>';
				}
				echo '</ul>';
			}
			if ( 'end' === $node->node_type && $node->debrief ) {
				echo '<p><strong>Debrief:</strong> ' . esc_html( $node->debrief ) . '</p>';
			}
			if ( 'published' !== $scenario->status ) {
				echo '<button type="button" class="sslms-btn sslms-btn--danger" data-sslms-del="scenarios/nodes/' . (int) $node->id . '">Delete node</button>';
			}
			echo '</div>';
		}
		echo '<p>Structural edits (new nodes, rewiring options) are done via the JSON importer for a new draft, or the REST API — the on-screen list is the review surface. Delete + re-import is the simplest correction path while a scenario is a draft.</p>';
		echo '</fieldset>';
	}

	private static function inline_script(): void {
		?>
		<script>
		(function () {
			document.addEventListener('click', function (ev) {
				var del = ev.target.closest('[data-sslms-del]');
				if (!del) { return; }
				if (!confirm('Delete this?')) { return; }
				SSLMS.api(del.getAttribute('data-sslms-del'), { method: 'DELETE' })
					.then(function () { window.location.reload(); })
					.catch(function (e) { alert(e.message); });
			});
		})();
		</script>
		<?php
	}
}
SSLMS_Admin_Scenarios::init();
