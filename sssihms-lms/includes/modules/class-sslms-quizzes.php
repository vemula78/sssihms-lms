<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quiz engine: question banks, randomized attempts, server-side grading (SPEC F3).
 *
 * Question storage format (see class-sslms-install.php, schema FINAL):
 *   questions.options = JSON [ {"id":1,"text":"..."}, {"id":2,"text":"..."}, ... ]
 *   questions.correct = JSON [ id, id, ... ]  (subset of option ids)
 *   tf questions always use the fixed options { id:1 => "True", id:2 => "False" }.
 *
 * Grading is always by option id, never by presentation order — options are
 * shuffled at serve time (start_attempt / resume) but that shuffle is never
 * persisted; the stable ids already stored on the question are the source of
 * truth for matching a submission, so re-shuffling on resume is harmless.
 */
class SSLMS_Quizzes {

	/** Grace period (seconds) after time_limit_min before a submission is graded as unanswered. */
	const GRACE_SECONDS = 60;

	public static function init(): void {
		// No hooks of its own; consumed by the REST, admin and portal files.
	}

	/* ------------------------------------------------------------------ *
	 * Cross-module contract (CONVENTIONS.md) — exact signatures required.
	 * ------------------------------------------------------------------ */

	/** All quizzes for a course, including lesson-attached ones. */
	public static function for_course( int $course_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'quizzes' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE course_id = %d ORDER BY (lesson_id IS NULL) ASC, lesson_id ASC, id ASC",
			$course_id
		) );
	}

	/** Has the user ever passed this quiz? */
	public static function user_passed( int $quiz_id, int $user_id ): bool {
		global $wpdb;
		$t   = SSLMS_DB::table( 'quiz_attempts' );
		$row = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE quiz_id = %d AND user_id = %d AND passed = 1 LIMIT 1",
			$quiz_id, $user_id
		) );
		return (bool) $row;
	}

	/* ------------------------------------------------------------------ *
	 * Extra lookups used by the REST/admin/portal files (not part of the
	 * cross-module contract, but kept here so grading/format logic lives
	 * in one place).
	 * ------------------------------------------------------------------ */

	/** Quizzes attached to a specific lesson (subset of for_course). */
	public static function for_lesson( int $lesson_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'quizzes' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE lesson_id = %d ORDER BY id ASC",
			$lesson_id
		) );
	}

	public static function get_quiz( int $quiz_id ): ?object {
		return SSLMS_DB::get_row( 'quizzes', $quiz_id );
	}

	public static function get_bank( int $bank_id ): ?object {
		return SSLMS_DB::get_row( 'question_banks', $bank_id );
	}

	public static function get_question( int $question_id ): ?object {
		return SSLMS_DB::get_row( 'questions', $question_id );
	}

	public static function active_question_count( int $bank_id ): int {
		global $wpdb;
		$t = SSLMS_DB::table( 'questions' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE bank_id = %d AND is_active = 1",
			$bank_id
		) );
	}

	public static function questions_for_bank( int $bank_id, bool $include_inactive = true ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'questions' );
		if ( $include_inactive ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE bank_id = %d ORDER BY id ASC", $bank_id ) );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE bank_id = %d AND is_active = 1 ORDER BY id ASC", $bank_id ) );
	}

	/** The user's currently-open (unsubmitted) attempt for a quiz, if any. */
	public static function open_attempt( int $quiz_id, int $user_id ): ?object {
		global $wpdb;
		$t = SSLMS_DB::table( 'quiz_attempts' );
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE quiz_id = %d AND user_id = %d AND submitted_at IS NULL ORDER BY id DESC LIMIT 1",
			$quiz_id, $user_id
		) ) ?: null;
	}

	public static function attempts_used( int $quiz_id, int $user_id ): int {
		global $wpdb;
		$t = SSLMS_DB::table( 'quiz_attempts' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE quiz_id = %d AND user_id = %d AND submitted_at IS NOT NULL",
			$quiz_id, $user_id
		) );
	}

	/** Null = unlimited attempts. */
	public static function attempts_left( int $quiz_id, int $user_id, int $max_attempts ): ?int {
		if ( $max_attempts <= 0 ) {
			return null;
		}
		return max( 0, $max_attempts - self::attempts_used( $quiz_id, $user_id ) );
	}

	public static function best_score( int $quiz_id, int $user_id ): ?float {
		global $wpdb;
		$t = SSLMS_DB::table( 'quiz_attempts' );
		$v = $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(score_pct) FROM {$t} WHERE quiz_id = %d AND user_id = %d AND submitted_at IS NOT NULL",
			$quiz_id, $user_id
		) );
		return null === $v ? null : (float) $v;
	}

	/**
	 * Class average: mean of each learner's best submitted score on this quiz.
	 * Aggregate only — never exposes any individual learner's score/identity.
	 * Returns null when fewer than 2 learners have attempted (nothing to
	 * compare against, and avoids a "class average" of exactly one person).
	 */
	public static function class_average( int $quiz_id ): ?float {
		global $wpdb;
		$t = SSLMS_DB::table( 'quiz_attempts' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT AVG(best) AS avg_best, COUNT(*) AS n FROM (
				SELECT MAX(score_pct) AS best FROM {$t}
				WHERE quiz_id = %d AND submitted_at IS NOT NULL
				GROUP BY user_id
			) AS per_user",
			$quiz_id
		) );
		if ( ! $row || (int) $row->n < 2 || null === $row->avg_best ) {
			return null;
		}
		return (float) $row->avg_best;
	}

	/** Own attempt history for a quiz, most recent first. */
	public static function attempt_history( int $quiz_id, int $user_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'quiz_attempts' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, started_at, submitted_at, score_pct, passed FROM {$t} WHERE quiz_id = %d AND user_id = %d ORDER BY id DESC",
			$quiz_id, $user_id
		) ) ?: array();
	}

	/** True if any quiz references this bank (blocks bank deletion). */
	public static function bank_in_use( int $bank_id ): bool {
		global $wpdb;
		$t = SSLMS_DB::table( 'quizzes' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE bank_id = %d LIMIT 1", $bank_id ) );
	}

	/** True if any attempt exists for this quiz (blocks quiz deletion — audit trail). */
	public static function quiz_has_attempts( int $quiz_id ): bool {
		global $wpdb;
		$t = SSLMS_DB::table( 'quiz_attempts' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE quiz_id = %d LIMIT 1", $quiz_id ) );
	}

	/* ------------------------------------------------------------------ *
	 * Attempt lifecycle.
	 * ------------------------------------------------------------------ */

	/**
	 * Start (or resume) an attempt for a learner.
	 *
	 * @return array|WP_Error Attempt payload (see attempt_payload()) or error.
	 */
	public static function start_attempt( int $quiz_id, int $user_id ) {
		$quiz = self::get_quiz( $quiz_id );
		if ( ! $quiz ) {
			return new WP_Error( 'sslms_no_quiz', 'Quiz not found.', array( 'status' => 404 ) );
		}

		if ( ! class_exists( 'SSLMS_Enrollments' ) || ! SSLMS_Enrollments::is_enrolled( (int) $quiz->course_id, $user_id ) ) {
			return new WP_Error( 'sslms_not_enrolled', 'You must be enrolled in this course to take the quiz.', array( 'status' => 403 ) );
		}
		$course = class_exists( 'SSLMS_Courses' ) ? SSLMS_Courses::get( (int) $quiz->course_id ) : null;
		if ( ! $course || 'published' !== $course->status ) {
			return new WP_Error( 'sslms_course_unavailable', 'This course is not currently available.', array( 'status' => 403 ) );
		}

		// Resume an already-open attempt rather than starting a second one.
		$open = self::open_attempt( $quiz_id, $user_id );
		if ( $open ) {
			return self::attempt_payload( $open, $quiz );
		}

		if ( (int) $quiz->max_attempts > 0 && self::attempts_used( $quiz_id, $user_id ) >= (int) $quiz->max_attempts ) {
			return new WP_Error( 'sslms_no_attempts_left', 'No attempts remaining for this quiz.', array( 'status' => 403 ) );
		}

		$question_ids = self::pick_question_ids( (int) $quiz->bank_id, (int) $quiz->num_questions );
		if ( ! $question_ids ) {
			return new WP_Error( 'sslms_no_questions', 'This quiz has no active questions to serve.', array( 'status' => 409 ) );
		}

		$id = SSLMS_DB::insert( 'quiz_attempts', array(
			'quiz_id'      => $quiz_id,
			'user_id'      => $user_id,
			'started_at'   => SSLMS_DB::now(),
			'submitted_at' => null,
			'question_ids' => wp_json_encode( array_values( $question_ids ) ),
			'answers'      => wp_json_encode( array() ),
			'score_pct'    => null,
			'passed'       => 0,
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_attempt_failed', 'Could not start the attempt.', array( 'status' => 500 ) );
		}

		SSLMS_Audit::log( 'quiz_started', 'quiz', $quiz_id, sprintf( 'Attempt #%d started (%d questions)', $id, count( $question_ids ) ) );

		return self::attempt_payload( SSLMS_DB::get_row( 'quiz_attempts', $id ), $quiz );
	}

	/**
	 * Grade a submitted attempt.
	 *
	 * @param array $answers Raw answers as received: [ question_id => option_id|option_id[] ].
	 * @return array|WP_Error ['attempt_id','score_pct','passed','late','attempts_left'] or error.
	 */
	public static function submit_attempt( int $attempt_id, int $user_id, array $answers ) {
		$attempt = SSLMS_DB::get_row( 'quiz_attempts', $attempt_id );
		if ( ! $attempt || (int) $attempt->user_id !== $user_id ) {
			return new WP_Error( 'sslms_no_attempt', 'Attempt not found.', array( 'status' => 404 ) );
		}
		if ( $attempt->submitted_at ) {
			return new WP_Error( 'sslms_already_submitted', 'This attempt has already been submitted.', array( 'status' => 409 ) );
		}
		$quiz = self::get_quiz( (int) $attempt->quiz_id );
		if ( ! $quiz ) {
			return new WP_Error( 'sslms_no_quiz', 'Quiz not found.', array( 'status' => 404 ) );
		}

		$answers = self::sanitize_answers( $answers );

		// N1/F3.5: enforce time_limit_min server-side. A submission arriving after
		// limit+grace is still recorded (verbatim, for audit) but is graded as if
		// no answers were given — simplest correct behaviour for a single-shot
		// (non-autosaving) submit endpoint.
		$late = false;
		if ( (int) $quiz->time_limit_min > 0 ) {
			$deadline = strtotime( $attempt->started_at . ' UTC' ) + ( (int) $quiz->time_limit_min * 60 ) + self::GRACE_SECONDS;
			$late     = time() > $deadline;
		}
		$graded_answers = $late ? array() : $answers;

		$question_ids = self::decode_ids( $attempt->question_ids );
		$questions    = self::load_questions( $question_ids );

		$earned = 0.0;
		$total  = 0.0;
		foreach ( $question_ids as $qid ) {
			if ( empty( $questions[ $qid ] ) ) {
				continue; // question removed after the attempt started
			}
			$q      = $questions[ $qid ];
			$points = (float) $q->points;
			$total += $points;

			$correct_ids = self::decode_ids( $q->correct );
			$given       = isset( $graded_answers[ $qid ] ) ? $graded_answers[ $qid ] : array();

			// mcq: exact single match; multi: exact set match; tf: boolean — all
			// three reduce to "the submitted option-id set equals the correct set".
			if ( self::sets_match( $given, $correct_ids ) ) {
				$earned += $points;
			}
		}

		$score_pct = $total > 0 ? round( ( $earned / $total ) * 100, 2 ) : 0.0;
		$passed    = $score_pct >= (float) $quiz->pass_pct;

		SSLMS_DB::update( 'quiz_attempts', array(
			'submitted_at' => SSLMS_DB::now(),
			'answers'      => wp_json_encode( $answers ), // verbatim, even when late (SPEC F3.4)
			'score_pct'    => $score_pct,
			'passed'       => $passed ? 1 : 0,
		), array( 'id' => $attempt_id ) );

		$graded = SSLMS_DB::get_row( 'quiz_attempts', $attempt_id );

		SSLMS_Audit::log(
			'quiz_submitted',
			'quiz',
			(int) $quiz->id,
			sprintf( 'Attempt #%d: %.2f%%, %s%s', $attempt_id, $score_pct, $passed ? 'passed' : 'failed', $late ? ' (late — graded as unanswered)' : '' )
		);

		/** Module C listens for this to recalc course completion. */
		do_action( 'sslms_quiz_graded', $graded );

		return array(
			'attempt_id'    => $attempt_id,
			'score_pct'     => $score_pct,
			'passed'        => $passed,
			'late'          => $late,
			'attempts_left' => self::attempts_left( (int) $quiz->id, $user_id, (int) $quiz->max_attempts ),
		);
	}

	/**
	 * Build the learner-facing payload for an attempt: id, remaining time, and
	 * the served questions with shuffled options and NO correct answers.
	 */
	public static function attempt_payload( object $attempt, ?object $quiz = null ): array {
		$quiz = $quiz ?: self::get_quiz( (int) $attempt->quiz_id );
		return array(
			'attempt_id'        => (int) $attempt->id,
			'quiz_id'           => (int) $attempt->quiz_id,
			'started_at'        => $attempt->started_at,
			'time_limit_min'    => $quiz ? (int) $quiz->time_limit_min : 0,
			'remaining_seconds' => self::remaining_seconds( $attempt, $quiz ),
			'questions'         => self::serve_questions( self::decode_ids( $attempt->question_ids ) ),
		);
	}

	/** Seconds left before the time limit expires; null when the quiz is untimed. */
	public static function remaining_seconds( object $attempt, ?object $quiz ): ?int {
		if ( ! $quiz || (int) $quiz->time_limit_min <= 0 ) {
			return null;
		}
		$deadline = strtotime( $attempt->started_at . ' UTC' ) + ( (int) $quiz->time_limit_min * 60 );
		return max( 0, $deadline - time() );
	}

	/** Serve questions for presentation: no correct answers, options shuffled. */
	public static function serve_questions( array $question_ids ): array {
		if ( ! $question_ids ) {
			return array();
		}
		$questions = self::load_questions( $question_ids );
		$out       = array();
		foreach ( $question_ids as $qid ) {
			if ( empty( $questions[ $qid ] ) ) {
				continue;
			}
			$row     = $questions[ $qid ];
			$options = self::decode_options( $row->options );
			shuffle( $options ); // presentation only — grading matches by option id.
			$out[] = array(
				'id'      => (int) $row->id,
				'prompt'  => $row->prompt,
				'qtype'   => $row->qtype,
				'points'  => (int) $row->points,
				'options' => array_values( array_map( static function ( $o ) {
					return array( 'id' => (int) ( $o['id'] ?? 0 ), 'text' => (string) ( $o['text'] ?? '' ) );
				}, $options ) ),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Internal helpers.
	 * ------------------------------------------------------------------ */

	private static function pick_question_ids( int $bank_id, int $num_questions ): array {
		global $wpdb;
		$t   = SSLMS_DB::table( 'questions' );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE bank_id = %d AND is_active = 1 ORDER BY RAND() LIMIT %d",
			$bank_id, max( 1, $num_questions )
		) );
		return array_map( 'intval', $ids );
	}

	/** @return object[] keyed by question id */
	private static function load_questions( array $question_ids ): array {
		if ( ! $question_ids ) {
			return array();
		}
		global $wpdb;
		$t            = SSLMS_DB::table( 'questions' );
		$placeholders = implode( ',', array_fill( 0, count( $question_ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE id IN ({$placeholders})", $question_ids ) );
		$by_id        = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row->id ] = $row;
		}
		return $by_id;
	}

	private static function sanitize_answers( array $answers ): array {
		$out = array();
		foreach ( $answers as $qid => $opts ) {
			$qid = (int) $qid;
			if ( $qid <= 0 ) {
				continue;
			}
			$opts        = is_array( $opts ) ? $opts : array( $opts );
			$out[ $qid ] = array_values( array_unique( array_map( 'intval', $opts ) ) );
		}
		return $out;
	}

	private static function sets_match( array $a, array $b ): bool {
		$a = array_values( array_unique( array_map( 'intval', $a ) ) );
		$b = array_values( array_unique( array_map( 'intval', $b ) ) );
		sort( $a );
		sort( $b );
		return $a === $b;
	}

	public static function decode_options( $json ): array {
		$arr = json_decode( (string) $json, true );
		return is_array( $arr ) ? $arr : array();
	}

	public static function decode_ids( $json ): array {
		$arr = is_string( $json ) ? json_decode( $json, true ) : $json;
		return is_array( $arr ) ? array_map( 'intval', $arr ) : array();
	}
}
SSLMS_Quizzes::init();
