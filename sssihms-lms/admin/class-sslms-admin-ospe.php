<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen — OSPE exams (Phase 3c): blueprint editor (stations,
 * examiners, candidates), moderation grid, publish + CSV export.
 */
class SSLMS_Admin_OSPE {

	const CAP = 'sslms_author_courses';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 20 );
	}

	public static function register(): void {
		add_submenu_page(
			'sslms',
			'OSPE Exams',
			'OSPE',
			self::CAP,
			'sslms-ospe',
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not permitted.' );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>OSPE Exams</h1><div class="sslms">';
		if ( $id ) {
			self::render_editor( $id );
		} else {
			self::render_list();
		}
		echo '</div></div>';
		self::inline_script();
	}

	private static function status_badge( string $status ): string {
		$class = 'published' === $status ? 'ok' : ( 'scheduled' === $status ? 'warn' : 'muted' );
		return '<span class="sslms-badge sslms-badge--' . esc_attr( $class ) . '">' . esc_html( $status ) . '</span>';
	}

	private static function render_list(): void {
		$exams = SSLMS_OSPE::list_exams();
		echo '<h2>Exams</h2>';
		if ( $exams ) {
			echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>Title</th><th>Discipline</th><th>Date</th><th>Stations</th><th>Candidates</th><th>Status</th><th></th></tr></thead><tbody>';
			foreach ( $exams as $exam ) {
				$edit_url = esc_url( add_query_arg( array( 'page' => 'sslms-ospe', 'id' => $exam->id ), admin_url( 'admin.php' ) ) );
				echo '<tr>';
				echo '<td><a href="' . $edit_url . '">' . esc_html( $exam->title ) . '</a></td>';
				echo '<td>' . esc_html( $exam->discipline ) . '</td>';
				echo '<td>' . esc_html( SSLMS_DB::fmt_date( $exam->exam_date ) ) . '</td>';
				echo '<td>' . count( SSLMS_OSPE::get_stations( (int) $exam->id ) ) . '</td>';
				echo '<td>' . count( SSLMS_OSPE::get_candidates( (int) $exam->id ) ) . '</td>';
				echo '<td>' . self::status_badge( $exam->status ) . '</td>';
				echo '<td><a href="' . $edit_url . '" class="sslms-btn sslms-btn--ghost">Open</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No OSPE exams yet.</p>';
		}

		echo '<h2>Create exam</h2>';
		echo '<form data-sslms-endpoint="ospe" data-sslms-method="POST">';
		echo '<label>Title<input type="text" name="title" required></label>';
		echo '<label>Discipline<input type="text" name="discipline" placeholder="e.g. Nursing"></label>';
		echo '<label>Exam date<input type="date" name="exam_date"></label>';
		echo '<label>Overall pass %<input type="number" name="pass_pct" min="0" max="100" step="0.5" value="50"></label>';
		echo '<label>Minimum per-station % (0 = none)<input type="number" name="min_station_pct" min="0" max="100" step="0.5" value="0"></label>';
		echo '<button type="submit" class="sslms-btn">Create</button>';
		echo '</form>';
	}

	private static function render_editor( int $id ): void {
		$exam = SSLMS_OSPE::get_exam( $id );
		if ( ! $exam ) {
			echo '<p>Exam not found.</p>';
			return;
		}
		$locked = 'published' === $exam->status;
		$back   = esc_url( add_query_arg( array( 'page' => 'sslms-ospe' ), admin_url( 'admin.php' ) ) );
		echo '<p><a href="' . $back . '">&larr; All exams</a></p>';
		echo '<h2>' . esc_html( $exam->title ) . ' ' . self::status_badge( $exam->status ) . '</h2>';
		echo '<p><a class="sslms-btn sslms-btn--ghost" href="' . esc_url( rest_url( 'sslms/v1/ospe/' . $id . '/export' ) ) . '">Download mark-sheet CSV</a></p>';

		if ( ! $locked ) {
			echo '<fieldset><legend>Exam details</legend>';
			echo '<form data-sslms-endpoint="ospe/' . (int) $id . '" data-sslms-method="PUT">';
			echo '<label>Title<input type="text" name="title" value="' . esc_attr( $exam->title ) . '" required></label>';
			echo '<label>Discipline<input type="text" name="discipline" value="' . esc_attr( $exam->discipline ) . '"></label>';
			echo '<label>Exam date<input type="date" name="exam_date" value="' . esc_attr( (string) $exam->exam_date ) . '"></label>';
			echo '<label>Overall pass %<input type="number" name="pass_pct" min="0" max="100" step="0.5" value="' . esc_attr( (float) $exam->pass_pct ) . '"></label>';
			echo '<label>Minimum per-station %<input type="number" name="min_station_pct" min="0" max="100" step="0.5" value="' . esc_attr( (float) $exam->min_station_pct ) . '"></label>';
			echo '<label>Status<select name="status"><option value="draft" ' . selected( $exam->status, 'draft', false ) . '>draft</option><option value="scheduled" ' . selected( $exam->status, 'scheduled', false ) . '>scheduled</option></select></label>';
			echo '<button type="submit" class="sslms-btn">Save</button>';
			echo '</form></fieldset>';
		}

		self::render_stations( $exam, $locked );
		self::render_candidates_and_grid( $exam, $locked );

		if ( ! $locked && current_user_can( 'sslms_manage' ) ) {
			echo '<fieldset><legend>Publish results</legend>';
			echo '<p>Publishing computes final results, locks the exam permanently and shows results to candidates. Every non-absent candidate must be fully scored.</p>';
			echo '<button type="button" class="sslms-btn" data-sslms-publish="' . (int) $id . '">Publish results</button>';
			echo '</fieldset>';
		}
	}

	private static function render_stations( object $exam, bool $locked ): void {
		$stations  = SSLMS_OSPE::get_stations( (int) $exam->id );
		$examiners = get_users( array( 'capability' => array( 'sslms_signoff_checklists' ), 'orderby' => 'display_name' ) );
		$rubrics   = SSLMS_Rubrics::list_rubrics( true );

		echo '<fieldset><legend>Stations</legend>';
		if ( $stations ) {
			echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>#</th><th>Title</th><th>Type</th><th>Min</th><th>Max marks</th><th>Examiner</th><th>Rubric ref.</th><th></th></tr></thead><tbody>';
			foreach ( $stations as $s ) {
				$examiner = $s->examiner_id ? get_userdata( (int) $s->examiner_id ) : false;
				$rubric   = $s->rubric_id ? SSLMS_Rubrics::get_rubric( (int) $s->rubric_id ) : null;
				echo '<tr>';
				echo '<td>' . (int) $s->station_no . '</td>';
				echo '<td>' . esc_html( $s->title ) . '</td>';
				echo '<td>' . esc_html( $s->station_type ) . '</td>';
				echo '<td>' . (int) $s->duration_min . '</td>';
				echo '<td>' . esc_html( (float) $s->max_marks ) . '</td>';
				echo '<td>' . esc_html( $examiner ? $examiner->display_name : '—' ) . '</td>';
				echo '<td>' . esc_html( $rubric ? $rubric->title : '—' ) . '</td>';
				echo '<td>' . ( $locked ? '' : '<button type="button" class="sslms-btn sslms-btn--danger" data-sslms-del="ospe/stations/' . (int) $s->id . '">Delete</button>' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No stations yet.</p>';
		}
		if ( ! $locked ) {
			echo '<h3>Add station</h3>';
			echo '<form data-sslms-endpoint="ospe/' . (int) $exam->id . '/stations" data-sslms-method="POST">';
			echo '<label>Title<input type="text" name="title" required></label>';
			echo '<label>Type<select name="station_type">';
			foreach ( SSLMS_OSPE::STATION_TYPES as $type ) {
				echo '<option value="' . esc_attr( $type ) . '">' . esc_html( $type ) . '</option>';
			}
			echo '</select></label>';
			echo '<label>Duration (min)<input type="number" name="duration_min" min="1" value="5"></label>';
			echo '<label>Max marks<input type="number" name="max_marks" min="0" step="0.5" value="10"></label>';
			echo '<label>Examiner<select name="examiner_id"><option value="0">— assign later —</option>';
			foreach ( $examiners as $u ) {
				echo '<option value="' . (int) $u->ID . '">' . esc_html( $u->display_name ) . '</option>';
			}
			echo '</select></label>';
			echo '<label>Rubric reference (optional scoresheet)<select name="rubric_id"><option value="0">— none —</option>';
			foreach ( $rubrics as $rubric ) {
				echo '<option value="' . (int) $rubric->id . '">' . esc_html( $rubric->title ) . '</option>';
			}
			echo '</select></label>';
			echo '<button type="submit" class="sslms-btn">Add station</button>';
			echo '</form>';
		}
		echo '</fieldset>';
	}

	private static function render_candidates_and_grid( object $exam, bool $locked ): void {
		$candidates = SSLMS_OSPE::get_candidates( (int) $exam->id );
		$stations   = SSLMS_OSPE::get_stations( (int) $exam->id );
		$matrix     = SSLMS_OSPE::scores_matrix( (int) $exam->id );
		$can_manage = current_user_can( 'sslms_manage' );

		echo '<fieldset><legend>Candidates &amp; scores</legend>';
		if ( $candidates ) {
			echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>#</th><th>Candidate</th>';
			foreach ( $stations as $s ) {
				if ( 'rest' === $s->station_type ) {
					continue;
				}
				echo '<th>S' . (int) $s->station_no . ' /' . esc_html( (float) $s->max_marks ) . '</th>';
			}
			echo '<th>Result</th><th></th></tr></thead><tbody>';
			foreach ( $candidates as $cand ) {
				$user = get_userdata( (int) $cand->user_id );
				echo '<tr>';
				echo '<td>' . (int) $cand->candidate_no . '</td>';
				echo '<td>' . esc_html( $user ? $user->display_name : ( 'User ' . $cand->user_id ) ) . ( 'absent' === $cand->status ? ' <span class="sslms-badge sslms-badge--muted">absent</span>' : '' ) . '</td>';
				foreach ( $stations as $s ) {
					if ( 'rest' === $s->station_type ) {
						continue;
					}
					$score = $matrix[ (int) $s->id ][ (int) $cand->id ] ?? null;
					if ( $locked || ! $can_manage ) {
						echo '<td>' . ( $score ? esc_html( (float) $score->marks ) : '—' ) . '</td>';
					} else {
						// Coordinator moderation cell (examiners score via the portal).
						echo '<td><button type="button" class="sslms-btn sslms-btn--ghost" data-sslms-moderate="' . (int) $s->id . '" data-candidate="' . (int) $cand->id . '" data-max="' . esc_attr( (float) $s->max_marks ) . '">' . ( $score ? esc_html( (float) $score->marks ) : '—' ) . '</button></td>';
					}
				}
				if ( 'completed' === $cand->status ) {
					$class = 'pass' === $cand->outcome ? 'ok' : 'bad';
					echo '<td><span class="sslms-badge sslms-badge--' . $class . '">' . esc_html( $cand->outcome . ' · ' . round( (float) $cand->total_pct, 1 ) . '%' ) . '</span></td>';
				} else {
					$result = SSLMS_OSPE::compute_result( (int) $exam->id, (int) $cand->id );
					echo '<td>' . ( $result ? esc_html( 'provisional ' . $result['outcome'] . ' · ' . $result['total_pct'] . '%' ) : '<span class="sslms-badge sslms-badge--muted">incomplete</span>' ) . '</td>';
				}
				echo '<td>';
				if ( ! $locked ) {
					if ( $can_manage && 'absent' !== $cand->status ) {
						echo '<button type="button" class="sslms-btn sslms-btn--ghost" data-sslms-absent="' . (int) $cand->id . '">Absent</button> ';
					}
					echo '<button type="button" class="sslms-btn sslms-btn--danger" data-sslms-del="ospe/candidates/' . (int) $cand->id . '">Remove</button>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No candidates yet.</p>';
		}
		if ( ! $locked ) {
			$learners = get_users( array( 'capability' => array( 'sslms_learn' ), 'orderby' => 'display_name' ) );
			echo '<h3>Register candidate</h3>';
			echo '<form data-sslms-endpoint="ospe/' . (int) $exam->id . '/candidates" data-sslms-method="POST" class="sslms-flex">';
			echo '<select name="user_id" required><option value="">— choose learner —</option>';
			foreach ( $learners as $u ) {
				echo '<option value="' . (int) $u->ID . '">' . esc_html( $u->display_name ) . '</option>';
			}
			echo '</select>';
			echo '<button type="submit" class="sslms-btn">Register</button>';
			echo '</form>';
		}
		echo '</fieldset>';
	}

	private static function inline_script(): void {
		?>
		<script>
		(function () {
			document.addEventListener('click', function (ev) {
				var del = ev.target.closest('[data-sslms-del]');
				if (del) {
					if (!confirm('Delete this?')) { return; }
					SSLMS.api(del.getAttribute('data-sslms-del'), { method: 'DELETE' })
						.then(function () { window.location.reload(); })
						.catch(function (e) { alert(e.message); });
					return;
				}
				var pub = ev.target.closest('[data-sslms-publish]');
				if (pub) {
					if (!confirm('Publish results? This locks the exam PERMANENTLY.')) { return; }
					SSLMS.api('ospe/' + pub.getAttribute('data-sslms-publish') + '/publish', { method: 'POST' })
						.then(function () { window.location.reload(); })
						.catch(function (e) { alert(e.message); });
					return;
				}
				var absent = ev.target.closest('[data-sslms-absent]');
				if (absent) {
					if (!confirm('Mark this candidate absent?')) { return; }
					SSLMS.api('ospe/candidates/' + absent.getAttribute('data-sslms-absent') + '/absent', { method: 'POST' })
						.then(function () { window.location.reload(); })
						.catch(function (e) { alert(e.message); });
					return;
				}
				var mod = ev.target.closest('[data-sslms-moderate]');
				if (mod) {
					var marks = prompt('Marks (0–' + mod.getAttribute('data-max') + '):');
					if (marks === null || marks === '') { return; }
					var note = prompt('Moderation note (required):');
					if (!note) { alert('A moderation note is required.'); return; }
					SSLMS.api('ospe/stations/' + mod.getAttribute('data-sslms-moderate') + '/score', {
						method: 'POST',
						body: { candidate_id: mod.getAttribute('data-candidate'), marks: marks, note: note, moderation: 1 }
					}).then(function () { window.location.reload(); }).catch(function (e) { alert(e.message); });
				}
			});
		})();
		</script>
		<?php
	}
}
SSLMS_Admin_OSPE::init();
