<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen — Competency Framework (Phase 3b): dictionary editor
 * (domain → competency tree per discipline), evidence mapping, cohort
 * matrix with attainment sign-off.
 */
class SSLMS_Admin_Competencies {

	const CAP = 'sslms_author_courses';

	const STATE_LABELS = array(
		'not_yet'           => 'Not yet',
		'in_progress'       => 'In progress',
		'evidence_complete' => 'Evidence complete',
	);

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 20 );
	}

	public static function register(): void {
		add_submenu_page(
			'sslms',
			'Competency Framework',
			'Competencies',
			self::CAP,
			'sslms-competencies',
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not permitted.' );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$competency_id = isset( $_GET['competency'] ) ? absint( $_GET['competency'] ) : 0;
		$matrix_disc   = isset( $_GET['matrix'] ) ? sanitize_text_field( wp_unslash( $_GET['matrix'] ) ) : '';
		// phpcs:enable
		echo '<div class="wrap"><h1>Competency Framework</h1><div class="sslms">';
		if ( $competency_id ) {
			self::render_mapping_editor( $competency_id );
		} elseif ( $matrix_disc ) {
			self::render_matrix( $matrix_disc );
		} else {
			self::render_dictionary();
		}
		echo '</div></div>';
		self::inline_script();
	}

	private static function render_dictionary(): void {
		$all = SSLMS_Competencies::list_competencies();
		$disciplines = array();
		foreach ( $all as $row ) {
			$disciplines[ $row->discipline ?: '(no discipline)' ] = true;
		}

		if ( ! $all ) {
			echo '<p>No competency dictionary yet. Load the editable starter set (Nursing / Allied Health / Chaplaincy) or add domains manually below.</p>';
			echo '<p><button type="button" class="sslms-btn" data-sslms-post="competencies/seed-starter">Load starter set</button></p>';
		} else {
			echo '<p>';
			foreach ( array_keys( $disciplines ) as $d ) {
				if ( '(no discipline)' === $d ) {
					continue;
				}
				echo '<a class="sslms-btn sslms-btn--ghost" href="' . esc_url( add_query_arg( array( 'page' => 'sslms-competencies', 'matrix' => $d ), admin_url( 'admin.php' ) ) ) . '">Cohort matrix: ' . esc_html( $d ) . '</a> ';
			}
			echo '</p>';
		}

		$by_parent = array();
		foreach ( $all as $row ) {
			$by_parent[ (int) $row->parent_id ][] = $row;
		}
		foreach ( $by_parent[0] ?? array() as $domain ) {
			echo '<fieldset><legend>' . esc_html( ( $domain->discipline ? $domain->discipline . ' — ' : '' ) . $domain->title )
				. ( $domain->is_active ? '' : ' <span class="sslms-badge sslms-badge--muted">inactive</span>' ) . '</legend>';
			echo self::edit_form( $domain );
			$children = $by_parent[ (int) $domain->id ] ?? array();
			if ( $children ) {
				echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>Competency</th><th>Code</th><th>Evidence mapped</th><th>Active</th><th></th></tr></thead><tbody>';
				foreach ( $children as $comp ) {
					$map_url = esc_url( add_query_arg( array( 'page' => 'sslms-competencies', 'competency' => $comp->id ), admin_url( 'admin.php' ) ) );
					echo '<tr>';
					echo '<td><a href="' . $map_url . '">' . esc_html( $comp->title ) . '</a></td>';
					echo '<td>' . esc_html( $comp->code ) . '</td>';
					echo '<td>' . count( SSLMS_Competencies::get_mappings( (int) $comp->id ) ) . '</td>';
					echo '<td>' . ( $comp->is_active ? '<span class="sslms-badge sslms-badge--ok">active</span>' : '<span class="sslms-badge sslms-badge--muted">inactive</span>' ) . '</td>';
					echo '<td><a class="sslms-btn sslms-btn--ghost" href="' . $map_url . '">Map evidence</a> <button type="button" class="sslms-btn sslms-btn--danger" data-sslms-del="competencies/' . (int) $comp->id . '">Delete</button></td>';
					echo '</tr>';
				}
				echo '</tbody></table></div>';
			}
			echo '<form data-sslms-endpoint="competencies" data-sslms-method="POST" class="sslms-flex">';
			echo '<input type="hidden" name="parent_id" value="' . (int) $domain->id . '">';
			echo '<input type="hidden" name="discipline" value="' . esc_attr( $domain->discipline ) . '">';
			echo '<input type="text" name="title" placeholder="New competency under this domain" required style="width:40%">';
			echo '<input type="text" name="code" placeholder="Code (optional)" style="width:12%">';
			echo '<button type="submit" class="sslms-btn">Add competency</button>';
			echo '</form>';
			echo '</fieldset>';
		}

		echo '<h2>Add new domain</h2>';
		echo '<form data-sslms-endpoint="competencies" data-sslms-method="POST">';
		echo '<input type="hidden" name="parent_id" value="0">';
		echo '<label>Discipline<input type="text" name="discipline" placeholder="e.g. Nursing" required></label>';
		echo '<label>Domain title<input type="text" name="title" required></label>';
		echo '<label>Description<textarea name="description" rows="2"></textarea></label>';
		echo '<button type="submit" class="sslms-btn">Add domain</button>';
		echo '</form>';
	}

	private static function edit_form( object $row ): string {
		$html  = '<form data-sslms-endpoint="competencies/' . (int) $row->id . '" data-sslms-method="PUT" data-sslms-noreload class="sslms-flex">';
		$html .= '<input type="text" name="title" value="' . esc_attr( $row->title ) . '" style="width:35%">';
		$html .= '<input type="text" name="code" value="' . esc_attr( $row->code ) . '" placeholder="Code" style="width:10%">';
		$html .= '<label style="width:auto"><input type="checkbox" name="is_active" value="1" style="width:auto" ' . checked( $row->is_active, 1, false ) . '> Active</label>';
		$html .= '<button type="submit" class="sslms-btn sslms-btn--ghost">Save</button>';
		$html .= '</form>';
		return $html;
	}

	private static function render_mapping_editor( int $competency_id ): void {
		$comp = SSLMS_Competencies::get_competency( $competency_id );
		if ( ! $comp ) {
			echo '<p>Competency not found.</p>';
			return;
		}
		$back = esc_url( add_query_arg( array( 'page' => 'sslms-competencies' ), admin_url( 'admin.php' ) ) );
		echo '<p><a href="' . $back . '">&larr; Dictionary</a></p>';
		echo '<h2>' . esc_html( ( $comp->discipline ? $comp->discipline . ' — ' : '' ) . $comp->title ) . '</h2>';
		if ( $comp->description ) {
			echo '<p>' . esc_html( $comp->description ) . '</p>';
		}

		echo '<fieldset><legend>Mapped evidence</legend>';
		$mappings = SSLMS_Competencies::get_mappings( $competency_id );
		if ( $mappings ) {
			echo '<ul>';
			foreach ( $mappings as $m ) {
				echo '<li>' . esc_html( $m->object_label )
					. ' <button type="button" class="sslms-btn sslms-btn--danger" data-sslms-del="competencies/map/' . (int) $m->id . '">Unmap</button></li>';
			}
			echo '</ul>';
		} else {
			echo '<p>No evidence mapped yet — attainment stays "Not yet" until something is mapped.</p>';
		}

		echo '<h3>Map new evidence</h3>';
		echo '<form data-sslms-endpoint="competencies/' . (int) $competency_id . '/map" data-sslms-method="POST" class="sslms-flex">';
		echo '<select name="object_type" required>';
		foreach ( SSLMS_Competencies::OBJECT_TYPES as $type ) {
			echo '<option value="' . esc_attr( $type ) . '">' . esc_html( str_replace( '_', ' ', $type ) ) . '</option>';
		}
		echo '</select>';
		echo '<input type="number" name="object_id" min="1" placeholder="ID (see reference below)" required style="width:10em">';
		echo '<button type="submit" class="sslms-btn">Map</button>';
		echo '</form></fieldset>';

		self::render_reference_tables();
	}

	/** ID reference so educators can map without memorising IDs. */
	private static function render_reference_tables(): void {
		echo '<fieldset><legend>Reference — available evidence IDs</legend>';

		echo '<h4>Quizzes</h4><ul>';
		global $wpdb;
		$qt = SSLMS_DB::table( 'quizzes' );
		foreach ( $wpdb->get_results( "SELECT id, title FROM {$qt} ORDER BY title ASC LIMIT 200" ) as $row ) {
			echo '<li>#' . (int) $row->id . ' — ' . esc_html( $row->title ) . '</li>';
		}
		echo '</ul>';

		echo '<h4>Rubrics (and their criteria)</h4><ul>';
		foreach ( SSLMS_Rubrics::list_rubrics() as $rubric ) {
			echo '<li>rubric #' . (int) $rubric->id . ' — ' . esc_html( $rubric->title ) . '<ul>';
			foreach ( SSLMS_Rubrics::get_criteria( (int) $rubric->id ) as $criterion ) {
				echo '<li>criterion #' . (int) $criterion->id . ' — ' . esc_html( wp_trim_words( $criterion->criterion_text, 12 ) ) . '</li>';
			}
			echo '</ul></li>';
		}
		echo '</ul>';

		echo '<h4>Checklists (and their items)</h4><ul>';
		foreach ( SSLMS_Checklists::list_checklists() as $checklist ) {
			echo '<li>' . esc_html( $checklist->title ) . '<ul>';
			foreach ( SSLMS_Checklists::get_items( (int) $checklist->id ) as $item ) {
				echo '<li>item #' . (int) $item->id . ' — ' . esc_html( wp_trim_words( $item->item_text, 12 ) ) . '</li>';
			}
			echo '</ul></li>';
		}
		echo '</ul>';
		echo '<p>Rotations are per-learner; map a rotation ID from the Hours screen when a specific placement evidences this competency.</p>';
		echo '</fieldset>';
	}

	private static function render_matrix( string $discipline ): void {
		$back = esc_url( add_query_arg( array( 'page' => 'sslms-competencies' ), admin_url( 'admin.php' ) ) );
		echo '<p><a href="' . $back . '">&larr; Dictionary</a></p>';
		echo '<h2>Cohort matrix — ' . esc_html( $discipline ) . '</h2>';
		$learners = get_users( array( 'capability' => array( 'sslms_learn' ), 'orderby' => 'display_name', 'fields' => array( 'ID' ) ) );
		$user_ids = wp_list_pluck( $learners, 'ID' );
		if ( ! $user_ids ) {
			echo '<p>No learners.</p>';
			return;
		}
		$matrix = SSLMS_Competencies::cohort_matrix( $discipline, $user_ids );
		if ( ! $matrix['competencies'] ) {
			echo '<p>No competencies defined for this discipline.</p>';
			return;
		}
		$can_sign = current_user_can( 'sslms_manage' );
		echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>Learner</th>';
		foreach ( $matrix['competencies'] as $comp ) {
			echo '<th>' . esc_html( $comp->code ?: wp_trim_words( $comp->title, 3, '…' ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $matrix['learners'] as $learner ) {
			echo '<tr><td>' . esc_html( $learner->user_name ) . '</td>';
			foreach ( $learner->cells as $cell ) {
				if ( $cell->level ) {
					echo '<td><span class="sslms-badge sslms-badge--ok">' . esc_html( $cell->level ) . '</span></td>';
				} elseif ( 'evidence_complete' === $cell->state ) {
					echo '<td><span class="sslms-badge sslms-badge--warn">ready to sign</span>';
					if ( $can_sign ) {
						echo '<br><button type="button" class="sslms-btn sslms-btn--ghost" data-sslms-attain="' . (int) $cell->competency_id . '" data-user="' . (int) $learner->user_id . '">Sign</button>';
					}
					echo '</td>';
				} elseif ( 'in_progress' === $cell->state ) {
					echo '<td><span class="sslms-badge sslms-badge--warn">' . (int) $cell->satisfied . '/' . (int) $cell->total . '</span></td>';
				} else {
					echo '<td><span class="sslms-badge sslms-badge--muted">—</span></td>';
				}
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<p>Legend: — not started &middot; n/m evidence in progress &middot; "ready to sign" = all mapped evidence satisfied, awaiting educator sign-off. Signing is deliberate and never automatic.</p>';
	}

	private static function inline_script(): void {
		?>
		<script>
		(function () {
			document.addEventListener('click', function (ev) {
				var post = ev.target.closest('[data-sslms-post]');
				if (post) {
					SSLMS.api(post.getAttribute('data-sslms-post'), { method: 'POST' })
						.then(function () { window.location.reload(); })
						.catch(function (e) { alert(e.message); });
					return;
				}
				var del = ev.target.closest('[data-sslms-del]');
				if (del) {
					if (!confirm('Delete this?')) { return; }
					SSLMS.api(del.getAttribute('data-sslms-del'), { method: 'DELETE' })
						.then(function () { window.location.reload(); })
						.catch(function (e) { alert(e.message); });
					return;
				}
				var attain = ev.target.closest('[data-sslms-attain]');
				if (attain) {
					var level = prompt('Attainment level: novice, competent or proficient', 'competent');
					if (!level) { return; }
					SSLMS.api('competencies/' + attain.getAttribute('data-sslms-attain') + '/attain', {
						method: 'POST',
						body: { user_id: attain.getAttribute('data-user'), level: level.trim().toLowerCase() }
					}).then(function () { window.location.reload(); }).catch(function (e) { alert(e.message); });
				}
			});
		})();
		</script>
		<?php
	}
}
SSLMS_Admin_Competencies::init();
