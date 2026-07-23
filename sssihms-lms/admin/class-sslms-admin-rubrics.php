<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen — Rubrics & Assessment Forms (Phase 3a): rubric builder
 * (criteria × levels grid) and a read view of recorded assessments.
 * Mutations go through the sslms/v1 REST API via the shared JS.
 */
class SSLMS_Admin_Rubrics {

	const CAP = 'sslms_author_courses';

	const FORM_TYPE_LABELS = array(
		'custom'          => 'Custom',
		'mini_cex'        => 'Mini-CEX',
		'dops'            => 'DOPS (procedural)',
		'professionalism' => 'Professionalism observation',
		'communication'   => 'Communication observation',
		'ward_feedback'   => 'Ward behaviour feedback',
	);

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 20 );
	}

	public static function register(): void {
		add_submenu_page(
			'sslms',
			'Rubrics & Assessments',
			'Rubrics',
			self::CAP,
			'sslms-rubrics',
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not permitted.' );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>Rubrics &amp; Assessment Forms</h1><div class="sslms">';
		if ( $id ) {
			self::render_editor( $id );
		} else {
			self::render_list();
		}
		echo '</div></div>';
		self::inline_script();
	}

	private static function render_list(): void {
		$rubrics = SSLMS_Rubrics::list_rubrics();
		echo '<h2>Rubric templates</h2>';
		if ( $rubrics ) {
			echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>Title</th><th>Type</th><th>Discipline</th><th>Criteria</th><th>Pass %</th><th>Active</th><th></th></tr></thead><tbody>';
			foreach ( $rubrics as $r ) {
				$edit_url = esc_url( add_query_arg( array( 'page' => 'sslms-rubrics', 'id' => $r->id ), admin_url( 'admin.php' ) ) );
				echo '<tr>';
				echo '<td><a href="' . $edit_url . '">' . esc_html( $r->title ) . '</a></td>';
				echo '<td>' . esc_html( self::FORM_TYPE_LABELS[ $r->form_type ] ?? $r->form_type ) . '</td>';
				echo '<td>' . esc_html( $r->discipline ) . '</td>';
				echo '<td>' . count( SSLMS_Rubrics::get_criteria( (int) $r->id ) ) . '</td>';
				echo '<td>' . ( (float) $r->pass_pct > 0 ? esc_html( round( (float) $r->pass_pct, 1 ) . '%' ) : '—' ) . '</td>';
				echo '<td>' . ( $r->is_active ? '<span class="sslms-badge sslms-badge--ok">active</span>' : '<span class="sslms-badge sslms-badge--muted">inactive</span>' ) . '</td>';
				echo '<td><a href="' . $edit_url . '" class="sslms-btn sslms-btn--ghost">Open</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No rubrics yet. Create the first one below — e.g. a mini-CEX or a professionalism observation form.</p>';
		}

		echo '<h2>Add new rubric</h2>';
		echo '<form data-sslms-endpoint="rubrics" data-sslms-method="POST">';
		echo '<label>Title<input type="text" name="title" required></label>';
		echo '<label>Form type<select name="form_type">';
		foreach ( self::FORM_TYPE_LABELS as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Discipline<input type="text" name="discipline" placeholder="e.g. Nursing, Cardiology"></label>';
		echo '<label>Pass mark % (0 = no pass mark)<input type="number" name="pass_pct" min="0" max="100" step="0.5" value="0"></label>';
		echo '<label>Description<textarea name="description" rows="3"></textarea></label>';
		echo '<button type="submit" class="sslms-btn">Create</button>';
		echo '</form>';
	}

	private static function render_editor( int $id ): void {
		$rubric = SSLMS_Rubrics::get_full( $id );
		if ( ! $rubric ) {
			echo '<p>Rubric not found.</p>';
			return;
		}
		$back = esc_url( add_query_arg( array( 'page' => 'sslms-rubrics' ), admin_url( 'admin.php' ) ) );
		echo '<p><a href="' . $back . '">&larr; All rubrics</a></p>';
		echo '<h2>' . esc_html( $rubric->title ) . '</h2>';

		echo '<fieldset><legend>Rubric details</legend>';
		echo '<form data-sslms-endpoint="rubrics/' . (int) $id . '" data-sslms-method="PUT">';
		echo '<label>Title<input type="text" name="title" value="' . esc_attr( $rubric->title ) . '" required></label>';
		echo '<label>Form type<select name="form_type">';
		foreach ( self::FORM_TYPE_LABELS as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '" ' . selected( $rubric->form_type, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Discipline<input type="text" name="discipline" value="' . esc_attr( $rubric->discipline ) . '"></label>';
		echo '<label>Pass mark % (0 = no pass mark)<input type="number" name="pass_pct" min="0" max="100" step="0.5" value="' . esc_attr( (float) $rubric->pass_pct ) . '"></label>';
		echo '<label>Description<textarea name="description" rows="3">' . esc_textarea( $rubric->description ) . '</textarea></label>';
		echo '<label><input type="checkbox" name="is_active" value="1" style="width:auto" ' . checked( $rubric->is_active, 1, false ) . '> Active</label>';
		echo '<button type="submit" class="sslms-btn">Save</button>';
		echo '</form></fieldset>';

		echo '<fieldset><legend>Criteria &times; performance levels</legend>';
		if ( $rubric->criteria ) {
			foreach ( $rubric->criteria as $criterion ) {
				echo '<div class="sslms-card">';
				echo '<form data-sslms-endpoint="rubrics/criteria/' . (int) $criterion->id . '" data-sslms-method="PUT" data-sslms-noreload>';
				echo '<label>Criterion<textarea name="criterion_text" rows="2">' . esc_textarea( $criterion->criterion_text ) . '</textarea></label>';
				echo '<label>Weight<input type="number" name="weight" min="0.01" step="0.01" value="' . esc_attr( (float) $criterion->weight ) . '" style="width:6em"></label> ';
				echo '<label><input type="checkbox" name="is_critical" value="1" style="width:auto" ' . checked( $criterion->is_critical, 1, false ) . '> Critical (bottom level = fail overall)</label> ';
				echo '<button type="submit" class="sslms-btn sslms-btn--ghost">Save criterion</button> ';
				echo '<button type="button" class="sslms-btn sslms-btn--danger" data-sslms-del="rubrics/criteria/' . (int) $criterion->id . '">Delete</button>';
				echo '</form>';

				if ( $criterion->levels ) {
					echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>#</th><th>Label</th><th>Descriptor</th><th>Marks</th><th></th></tr></thead><tbody>';
					foreach ( $criterion->levels as $level ) {
						echo '<tr>';
						echo '<td>' . (int) $level->level_index . '</td>';
						echo '<td colspan="3"><form data-sslms-endpoint="rubrics/levels/' . (int) $level->id . '" data-sslms-method="PUT" data-sslms-noreload style="margin:0" class="sslms-flex">';
						echo '<input type="text" name="label" value="' . esc_attr( $level->label ) . '" style="width:22%">';
						echo '<input type="text" name="descriptor" value="' . esc_attr( $level->descriptor ) . '" style="width:48%">';
						echo '<input type="number" name="marks" min="0" step="0.5" value="' . esc_attr( (float) $level->marks ) . '" style="width:6em">';
						echo '<button type="submit" class="sslms-btn sslms-btn--ghost">Save</button>';
						echo '</form></td>';
						echo '<td><button type="button" class="sslms-btn sslms-btn--danger" data-sslms-del="rubrics/levels/' . (int) $level->id . '">Delete</button></td>';
						echo '</tr>';
					}
					echo '</tbody></table></div>';
				} else {
					echo '<p>No levels yet — add at least two (e.g. Below expectations / Meets / Exceeds).</p>';
				}
				echo '<form data-sslms-endpoint="rubrics/criteria/' . (int) $criterion->id . '/levels" data-sslms-method="POST" class="sslms-flex">';
				echo '<input type="text" name="label" placeholder="Level label" required style="width:22%">';
				echo '<input type="text" name="descriptor" placeholder="Descriptor (what this level looks like)" style="width:48%">';
				echo '<input type="number" name="marks" min="0" step="0.5" placeholder="Marks" required style="width:6em">';
				echo '<button type="submit" class="sslms-btn">Add level</button>';
				echo '</form>';
				echo '</div>';
			}
		} else {
			echo '<p>No criteria yet.</p>';
		}
		echo '<form data-sslms-endpoint="rubrics/' . (int) $id . '/criteria" data-sslms-method="POST">';
		echo '<label>New criterion<textarea name="criterion_text" rows="2" required></textarea></label>';
		echo '<label>Weight<input type="number" name="weight" min="0.01" step="0.01" value="1" style="width:6em"></label> ';
		echo '<label><input type="checkbox" name="is_critical" value="1" style="width:auto"> Critical</label>';
		echo '<button type="submit" class="sslms-btn">Add criterion</button>';
		echo '</form></fieldset>';

		self::render_records_panel( $id );
	}

	private static function render_records_panel( int $rubric_id ): void {
		global $wpdb;
		$ar   = SSLMS_DB::table( 'assessment_records' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$ar} WHERE rubric_id = %d ORDER BY created_at DESC LIMIT 100", $rubric_id
		) );
		echo '<fieldset><legend>Recorded assessments (latest 100)</legend>';
		if ( ! $rows ) {
			echo '<p>None yet.</p></fieldset>';
			return;
		}
		echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>Learner</th><th>Assessor</th><th>Source</th><th>Context</th><th>Score</th><th>Outcome</th><th>Status</th><th>Date</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$learner  = get_userdata( (int) $row->learner_id );
			$assessor = get_userdata( (int) $row->assessor_id );
			echo '<tr>';
			echo '<td>' . esc_html( $learner ? $learner->display_name : ( 'User ' . $row->learner_id ) ) . '</td>';
			echo '<td>' . esc_html( $assessor ? $assessor->display_name : ( 'User ' . $row->assessor_id ) ) . '</td>';
			echo '<td>' . esc_html( $row->assessor_role ) . '</td>';
			echo '<td>' . esc_html( $row->context ) . '</td>';
			echo '<td>' . ( null === $row->score_pct ? '—' : esc_html( round( (float) $row->score_pct, 1 ) . '%' ) ) . '</td>';
			if ( 'pass' === $row->outcome ) {
				echo '<td><span class="sslms-badge sslms-badge--ok">pass</span></td>';
			} elseif ( 'fail' === $row->outcome ) {
				echo '<td><span class="sslms-badge sslms-badge--bad">fail' . ( $row->critical_failed ? ' (critical)' : '' ) . '</span></td>';
			} else {
				echo '<td><span class="sslms-badge sslms-badge--muted">—</span></td>';
			}
			echo '<td>' . ( 'signed' === $row->status ? '<span class="sslms-badge sslms-badge--ok">signed</span>' : '<span class="sslms-badge sslms-badge--warn">draft</span>' ) . '</td>';
			echo '<td>' . esc_html( SSLMS_DB::fmt_date( $row->created_at ) ) . '</td>';
			echo '<td><a class="sslms-btn sslms-btn--ghost" href="' . esc_url( rest_url( 'sslms/v1/assessments/' . $row->id . '/export' ) ) . '">CSV</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div></fieldset>';
	}

	/** Delete buttons the generic form-wiring doesn't cover. */
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
SSLMS_Admin_Rubrics::init();
