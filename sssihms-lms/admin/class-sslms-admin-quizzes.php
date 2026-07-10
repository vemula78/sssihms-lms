<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Quizzes & Banks" admin screen (SPEC F3). Read-side rendering queries the
 * DB directly (the page is already capability-gated); mutations are plain
 * HTML forms wired to sslms/v1 REST via the shared JS (data-sslms-endpoint).
 */
class SSLMS_Admin_Quizzes {

	const CAP  = 'sslms_author_courses';
	const SLUG = 'sslms-quizzes';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	public static function menu(): void {
		add_submenu_page(
			'sslms',
			'Quizzes & Banks',
			'Quizzes & Banks',
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sssihms-lms' ) );
		}

		$tab = isset( $_GET['tab'] ) && 'quizzes' === $_GET['tab'] ? 'quizzes' : 'banks'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap sslms-admin"><h1>Quizzes &amp; Banks</h1>';
		self::render_tabs( $tab );

		if ( 'quizzes' === $tab ) {
			$quiz_id = isset( $_GET['quiz_id'] ) ? absint( $_GET['quiz_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $quiz_id || isset( $_GET['new'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				self::render_quiz_editor( $quiz_id );
			} else {
				self::render_quiz_list();
			}
		} else {
			$bank_id = isset( $_GET['bank_id'] ) ? absint( $_GET['bank_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $bank_id ) {
				self::render_bank_detail( $bank_id );
			} else {
				self::render_bank_list();
			}
		}

		echo '</div>';
	}

	private static function render_tabs( string $tab ): void {
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		echo '<h2 class="nav-tab-wrapper">';
		printf(
			'<a href="%s" class="nav-tab %s">Question Banks</a>',
			esc_url( $base . '&tab=banks' ),
			'banks' === $tab ? 'nav-tab-active' : ''
		);
		printf(
			'<a href="%s" class="nav-tab %s">Quizzes</a>',
			esc_url( $base . '&tab=quizzes' ),
			'quizzes' === $tab ? 'nav-tab-active' : ''
		);
		echo '</h2>';
	}

	/* ------------------------------------------------------------------ *
	 * Question banks.
	 * ------------------------------------------------------------------ */

	private static function render_bank_list(): void {
		global $wpdb;
		$banks_t = SSLMS_DB::table( 'question_banks' );
		$q_t     = SSLMS_DB::table( 'questions' );
		$banks   = $wpdb->get_results(
			"SELECT b.*, (SELECT COUNT(*) FROM {$q_t} q WHERE q.bank_id = b.id) AS question_count,
			 (SELECT COUNT(*) FROM {$q_t} q WHERE q.bank_id = b.id AND q.is_active = 1) AS active_count
			 FROM {$banks_t} b ORDER BY b.title ASC"
		);

		echo '<h2>Question Banks</h2>';
		echo '<div class="sslms-table-scroll"><table class="widefat striped"><thead><tr>'
			. '<th>Title</th><th>Questions</th><th>Active</th><th></th></tr></thead><tbody>';
		if ( $banks ) {
			foreach ( $banks as $bank ) {
				$open = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'banks', 'bank_id' => $bank->id ), admin_url( 'admin.php' ) );
				printf(
					'<tr><td>%s</td><td>%d</td><td>%d</td><td><a href="%s" class="button button-small">Open</a></td></tr>',
					esc_html( $bank->title ),
					(int) $bank->question_count,
					(int) $bank->active_count,
					esc_url( $open )
				);
			}
		} else {
			echo '<tr><td colspan="4">No question banks yet.</td></tr>';
		}
		echo '</tbody></table></div>';

		echo '<h3>Add a bank</h3>';
		echo '<form data-sslms-endpoint="banks" data-sslms-method="POST" class="sslms-admin-form">';
		echo '<p><label>Title<br><input type="text" name="title" required></label></p>';
		echo '<p><button type="submit" class="button button-primary">Create bank</button></p>';
		echo '</form>';
	}

	private static function render_bank_detail( int $bank_id ): void {
		$bank = SSLMS_Quizzes::get_bank( $bank_id );
		if ( ! $bank ) {
			echo '<p>Question bank not found.</p>';
			return;
		}

		$back = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'banks' ), admin_url( 'admin.php' ) );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; All banks</a></p>';
		echo '<h2>' . esc_html( $bank->title ) . '</h2>';

		echo '<form data-sslms-endpoint="banks/' . (int) $bank->id . '" data-sslms-method="PUT" class="sslms-admin-form" style="max-width:420px;">';
		echo '<p><label>Rename bank<br><input type="text" name="title" value="' . esc_attr( $bank->title ) . '" required></label></p>';
		echo '<p><button type="submit" class="button">Save title</button></p>';
		echo '</form>';

		$questions = SSLMS_Quizzes::questions_for_bank( $bank_id );
		echo '<h3>Questions</h3>';
		echo '<div class="sslms-table-scroll"><table class="widefat striped"><thead><tr>'
			. '<th>#</th><th>Type</th><th>Prompt</th><th>Points</th><th>Status</th><th></th><th></th></tr></thead><tbody>';
		if ( $questions ) {
			foreach ( $questions as $q ) {
				$edit_url = add_query_arg(
					array( 'page' => self::SLUG, 'tab' => 'banks', 'bank_id' => $bank_id, 'question_id' => $q->id ),
					admin_url( 'admin.php' )
				);
				$status       = (int) $q->is_active ? 'Active' : 'Inactive';
				$toggle_to    = (int) $q->is_active ? 0 : 1;
				$toggle_label = (int) $q->is_active ? 'Deactivate' : 'Activate';

				// The toggle form must resend the full, valid payload
				// (normalize_question_payload re-validates every field on
				// update) — so it round-trips the question's own current
				// options/correct/points, only flipping is_active.
				$options_html = '';
				$correct_ids  = SSLMS_Quizzes::decode_ids( $q->correct );
				if ( 'tf' !== $q->qtype ) {
					foreach ( SSLMS_Quizzes::decode_options( $q->options ) as $o ) {
						$options_html .= '<input type="hidden" name="options[]" value="' . esc_attr( (string) ( $o['text'] ?? '' ) ) . '">';
					}
				}
				$correct_html = '';
				foreach ( $correct_ids as $cid ) {
					$correct_html .= '<input type="hidden" name="correct[]" value="' . (int) $cid . '">';
				}

				printf(
					'<tr><td>%d</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td>'
					. '<td><a href="%s" class="button button-small">Edit</a></td>'
					. '<td><form data-sslms-endpoint="questions/%d" data-sslms-method="PUT" class="sslms-inline-form">'
					. '<input type="hidden" name="qtype" value="%s"><input type="hidden" name="prompt" value="%s">'
					. '%s%s'
					. '<input type="hidden" name="points" value="%d"><input type="hidden" name="is_active" value="%d">'
					. '<button type="submit" class="button button-small">%s</button></form></td></tr>',
					(int) $q->id,
					esc_html( strtoupper( $q->qtype ) ),
					esc_html( wp_trim_words( $q->prompt, 12 ) ),
					(int) $q->points,
					esc_html( $status ),
					esc_url( $edit_url ),
					(int) $q->id,
					esc_attr( $q->qtype ),
					esc_attr( $q->prompt ),
					$options_html,
					$correct_html,
					(int) $q->points,
					$toggle_to,
					esc_html( $toggle_label )
				);
			}
		} else {
			echo '<tr><td colspan="7">No questions yet.</td></tr>';
		}
		echo '</tbody></table></div>';

		$editing = isset( $_GET['question_id'] ) ? absint( $_GET['question_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::render_question_form( $bank_id, $editing );
	}

	private static function render_question_form( int $bank_id, int $question_id ): void {
		$question = $question_id ? SSLMS_Quizzes::get_question( $question_id ) : null;
		$qtype    = isset( $_GET['qtype'] ) ? sanitize_text_field( wp_unslash( $_GET['qtype'] ) ) : ( $question ? $question->qtype : 'mcq' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $qtype, array( 'mcq', 'multi', 'tf' ), true ) ) {
			$qtype = 'mcq';
		}

		$options = array();
		$correct = array();
		if ( $question ) {
			$options = SSLMS_Quizzes::decode_options( $question->options );
			$correct = SSLMS_Quizzes::decode_ids( $question->correct );
		}

		echo '<h3>' . ( $question ? 'Edit question' : 'Add a question' ) . '</h3>';

		// Type selector round-trips via GET so the correct type-specific
		// fields render before any data entry — no JS framework required.
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin-bottom:10px;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="banks">';
		echo '<input type="hidden" name="bank_id" value="' . (int) $bank_id . '">';
		if ( $question_id ) {
			echo '<input type="hidden" name="question_id" value="' . (int) $question_id . '">';
		}
		echo '<label>Question type ';
		echo '<select name="qtype" onchange="this.form.submit()">';
		foreach ( array( 'mcq' => 'Single-answer (MCQ)', 'multi' => 'Multiple-answer', 'tf' => 'True / False' ) as $val => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $qtype, $val, false ), esc_html( $label ) );
		}
		echo '</select></label>';
		echo '</form>';

		$endpoint = $question_id ? 'questions/' . $question_id : 'banks/' . $bank_id . '/questions';
		$method   = $question_id ? 'PUT' : 'POST';

		echo '<form data-sslms-endpoint="' . esc_attr( $endpoint ) . '" data-sslms-method="' . esc_attr( $method ) . '" class="sslms-admin-form" style="max-width:640px;">';
		echo '<input type="hidden" name="qtype" value="' . esc_attr( $qtype ) . '">';
		echo '<p><label>Prompt<br><textarea name="prompt" rows="3" required>' . esc_textarea( $question ? $question->prompt : '' ) . '</textarea></label></p>';

		if ( 'tf' === $qtype ) {
			echo '<fieldset><legend>Correct answer</legend>';
			foreach ( array( 1 => 'True', 2 => 'False' ) as $id => $label ) {
				printf(
					'<label class="sslms-quiz-option"><input type="radio" name="correct[]" value="%d"%s> %s</label>',
					$id,
					in_array( $id, $correct, true ) ? ' checked' : '',
					esc_html( $label )
				);
			}
			echo '</fieldset>';
		} else {
			$input_type = 'mcq' === $qtype ? 'radio' : 'checkbox';
			echo '<fieldset><legend>Options (2–8) &mdash; tick the correct one(s)</legend>';
			for ( $slot = 1; $slot <= 8; $slot++ ) {
				$opt_text = '';
				foreach ( $options as $o ) {
					if ( (int) ( $o['id'] ?? 0 ) === $slot ) {
						$opt_text = (string) ( $o['text'] ?? '' );
						break;
					}
				}
				printf(
					'<p><input type="%s" name="correct[]" value="%d"%s> '
					. '<input type="text" name="options[]" value="%s" placeholder="Option %d text"></p>',
					esc_attr( $input_type ),
					$slot,
					in_array( $slot, $correct, true ) ? ' checked' : '',
					esc_attr( $opt_text ),
					$slot
				);
			}
			echo '</fieldset>';
		}

		echo '<p><label>Points<br><input type="number" name="points" min="1" value="' . (int) ( $question->points ?? 1 ) . '" style="max-width:120px;"></label></p>';
		// Hidden fallback so an unchecked box still sends is_active=0 — plain
		// HTML checkboxes omit themselves entirely when unchecked.
		echo '<input type="hidden" name="is_active" value="0">';
		echo '<p><label><input type="checkbox" name="is_active" value="1"' . ( ! $question || (int) $question->is_active ? ' checked' : '' ) . '> Active</label></p>';
		echo '<p><button type="submit" class="button button-primary">' . ( $question ? 'Save question' : 'Add question' ) . '</button></p>';
		echo '</form>';
	}

	/* ------------------------------------------------------------------ *
	 * Quizzes.
	 * ------------------------------------------------------------------ */

	private static function render_quiz_list(): void {
		global $wpdb;
		$quizzes_t = SSLMS_DB::table( 'quizzes' );
		$courses_t = SSLMS_DB::table( 'courses' );
		$lessons_t = SSLMS_DB::table( 'lessons' );
		$rows      = $wpdb->get_results(
			"SELECT z.*, c.title AS course_title, l.title AS lesson_title FROM {$quizzes_t} z
			 LEFT JOIN {$courses_t} c ON c.id = z.course_id
			 LEFT JOIN {$lessons_t} l ON l.id = z.lesson_id
			 ORDER BY z.id DESC"
		);

		$new_url = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'quizzes', 'new' => 1 ), admin_url( 'admin.php' ) );
		echo '<h2>Quizzes</h2><p><a href="' . esc_url( $new_url ) . '" class="button button-primary">Add quiz</a></p>';

		echo '<div class="sslms-table-scroll"><table class="widefat striped"><thead><tr>'
			. '<th>Title</th><th>Course</th><th>Attached to</th><th>Questions</th><th>Pass %</th><th>Max attempts</th><th>Time limit</th><th>Required</th><th></th><th></th></tr></thead><tbody>';
		if ( $rows ) {
			foreach ( $rows as $quiz ) {
				$edit_url = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'quizzes', 'quiz_id' => $quiz->id ), admin_url( 'admin.php' ) );
				$active_count = SSLMS_Quizzes::active_question_count( (int) $quiz->bank_id );
				$warn = $active_count < (int) $quiz->num_questions
					? ' <span class="sslms-badge sslms-badge--warn">only ' . (int) $active_count . ' active</span>'
					: '';
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%d%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>'
					. '<td><a href="%s" class="button button-small">Edit</a></td>'
					// Note: the sslms.js global submit listener is bound on
					// `document` in the bubble phase, so a plain
					// onsubmit="return confirm(...)" would NOT stop the
					// AJAX delete on Cancel (preventDefault alone doesn't
					// halt bubbling) — stopPropagation() is required too.
					. '<td><form data-sslms-endpoint="quizzes/%d" data-sslms-method="DELETE" class="sslms-inline-form" onsubmit="if(!confirm(\'Delete this quiz?\')){event.stopPropagation();return false;}"><button type="submit" class="button button-small button-link-delete">Delete</button></form></td></tr>',
					esc_html( $quiz->title ),
					esc_html( $quiz->course_title ?: '—' ),
					esc_html( $quiz->lesson_title ? $quiz->lesson_title : 'Course-level' ),
					(int) $quiz->num_questions,
					$warn,
					esc_html( number_format( (float) $quiz->pass_pct, 0 ) . '%' ),
					esc_html( (int) $quiz->max_attempts > 0 ? (string) (int) $quiz->max_attempts : 'Unlimited' ),
					esc_html( (int) $quiz->time_limit_min > 0 ? (int) $quiz->time_limit_min . ' min' : 'None' ),
					(int) $quiz->is_required ? 'Yes' : 'No',
					esc_url( $edit_url ),
					(int) $quiz->id
				);
			}
		} else {
			echo '<tr><td colspan="10">No quizzes yet.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_quiz_editor( int $quiz_id ): void {
		$quiz = $quiz_id ? SSLMS_Quizzes::get_quiz( $quiz_id ) : null;
		if ( $quiz_id && ! $quiz ) {
			echo '<p>Quiz not found.</p>';
			return;
		}

		$back = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'quizzes' ), admin_url( 'admin.php' ) );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; All quizzes</a></p>';
		echo '<h2>' . ( $quiz ? 'Edit quiz' : 'Add quiz' ) . '</h2>';

		global $wpdb;
		$courses_t    = SSLMS_DB::table( 'courses' );
		$courses      = $wpdb->get_results( "SELECT id, title FROM {$courses_t} WHERE status != 'archived' ORDER BY title ASC" );
		$selected_course = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : ( $quiz ? (int) $quiz->course_id : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Course selector round-trips via GET so the lesson dropdown below can
		// be scoped to the chosen course's lessons.
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin-bottom:14px;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="quizzes">';
		if ( $quiz_id ) {
			echo '<input type="hidden" name="quiz_id" value="' . (int) $quiz_id . '">';
		} else {
			echo '<input type="hidden" name="new" value="1">';
		}
		echo '<label>Course<br><select name="course_id" onchange="this.form.submit()" required><option value="">— Select a course —</option>';
		foreach ( $courses as $c ) {
			printf( '<option value="%d"%s>%s</option>', (int) $c->id, selected( $selected_course, (int) $c->id, false ), esc_html( $c->title ) );
		}
		echo '</select></label>';
		echo '</form>';

		if ( ! $selected_course ) {
			echo '<p>Choose a course to continue.</p>';
			return;
		}

		$lessons_t = SSLMS_DB::table( 'lessons' );
		$modules_t = SSLMS_DB::table( 'modules' );
		$lessons   = $wpdb->get_results( $wpdb->prepare(
			"SELECT l.id, l.title FROM {$lessons_t} l INNER JOIN {$modules_t} m ON m.id = l.module_id WHERE m.course_id = %d ORDER BY m.sort_order ASC, l.sort_order ASC",
			$selected_course
		) );

		$banks_t = SSLMS_DB::table( 'question_banks' );
		$banks   = $wpdb->get_results( "SELECT id, title FROM {$banks_t} ORDER BY title ASC" );

		if ( $quiz && (int) $quiz->bank_id ) {
			$active_count = SSLMS_Quizzes::active_question_count( (int) $quiz->bank_id );
			if ( $active_count < (int) $quiz->num_questions ) {
				printf(
					'<div class="notice notice-warning inline"><p>This quiz asks for %d questions but its bank only has %d active. Attempts will be served %d.</p></div>',
					(int) $quiz->num_questions,
					$active_count,
					$active_count
				);
			}
		}

		$endpoint = $quiz_id ? 'quizzes/' . $quiz_id : 'quizzes';
		$method   = $quiz_id ? 'PUT' : 'POST';

		echo '<form data-sslms-endpoint="' . esc_attr( $endpoint ) . '" data-sslms-method="' . esc_attr( $method ) . '" class="sslms-admin-form" style="max-width:520px;">';
		echo '<input type="hidden" name="course_id" value="' . (int) $selected_course . '">';

		echo '<p><label>Lesson (optional — leave blank for a course-level quiz)<br><select name="lesson_id"><option value="0">— Course-level —</option>';
		foreach ( $lessons as $l ) {
			printf( '<option value="%d"%s>%s</option>', (int) $l->id, selected( $quiz ? (int) $quiz->lesson_id : 0, (int) $l->id, false ), esc_html( $l->title ) );
		}
		echo '</select></label></p>';

		echo '<p><label>Title<br><input type="text" name="title" value="' . esc_attr( $quiz ? $quiz->title : '' ) . '" required></label></p>';

		echo '<p><label>Question bank<br><select name="bank_id" required><option value="">— Select —</option>';
		foreach ( $banks as $b ) {
			printf( '<option value="%d"%s>%s</option>', (int) $b->id, selected( $quiz ? (int) $quiz->bank_id : 0, (int) $b->id, false ), esc_html( $b->title ) );
		}
		echo '</select></label></p>';

		echo '<p><label>Number of questions per attempt<br><input type="number" name="num_questions" min="1" value="' . (int) ( $quiz->num_questions ?? 10 ) . '"></label></p>';
		echo '<p><label>Pass percentage<br><input type="number" name="pass_pct" min="0" max="100" step="0.01" value="' . esc_attr( $quiz->pass_pct ?? 70 ) . '"></label></p>';
		echo '<p><label>Max attempts (0 = unlimited)<br><input type="number" name="max_attempts" min="0" value="' . (int) ( $quiz->max_attempts ?? 0 ) . '"></label></p>';
		echo '<p><label>Time limit, minutes (0 = untimed)<br><input type="number" name="time_limit_min" min="0" value="' . (int) ( $quiz->time_limit_min ?? 0 ) . '"></label></p>';
		echo '<input type="hidden" name="is_required" value="0">';
		echo '<p><label><input type="checkbox" name="is_required" value="1"' . ( ! $quiz || (int) $quiz->is_required ? ' checked' : '' ) . '> Required for course completion</label></p>';

		echo '<p><button type="submit" class="button button-primary">' . ( $quiz ? 'Save quiz' : 'Create quiz' ) . '</button></p>';
		echo '</form>';
	}
}
SSLMS_Admin_Quizzes::init();
