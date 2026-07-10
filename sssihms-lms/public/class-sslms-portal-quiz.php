<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Learner-facing quiz launcher (SPEC F3.6, F12).
 *
 * Renders lesson-attached quizzes via the Module A contract hook
 * 'sslms_lesson_view' and course-level quizzes via 'sslms_course_view'.
 *
 * NOTE for the orchestrator (see FINAL REPORT): Module A's course player
 * must call do_action( 'sslms_course_view', $course ) somewhere on the
 * course page (analogous to the lesson_view hook already required by
 * CONVENTIONS.md) for course-level (non-lesson) quizzes to appear there.
 * Until that hook is fired, course-level quizzes remain reachable via the
 * [sslms_quiz id="123"] shortcode registered below.
 *
 * Never renders correct answers: the REST payloads consumed by the
 * quiz-taking JS never contain them (see SSLMS_Quizzes::serve_questions()).
 */
class SSLMS_Portal_Quiz {

	public static function init(): void {
		add_action( 'sslms_lesson_view', array( __CLASS__, 'render_lesson_quizzes' ), 10, 2 );
		add_action( 'sslms_course_view', array( __CLASS__, 'render_course_quizzes' ), 10, 1 );
		add_shortcode( 'sslms_quiz', array( __CLASS__, 'shortcode' ) );
	}

	/** Hooked: do_action( 'sslms_lesson_view', $lesson, $course ). */
	public static function render_lesson_quizzes( $lesson, $course ): void {
		if ( ! is_user_logged_in() || empty( $lesson->id ) ) {
			return;
		}
		$quizzes = SSLMS_Quizzes::for_lesson( (int) $lesson->id );
		if ( ! $quizzes ) {
			return;
		}
		self::enqueue_assets();
		echo '<div class="sslms-lesson-quizzes">';
		foreach ( $quizzes as $quiz ) {
			echo self::render_quiz_card( $quiz ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within render_quiz_card()
		}
		echo '</div>';
	}

	/** Hooked: do_action( 'sslms_course_view', $course ) — see class note above. */
	public static function render_course_quizzes( $course ): void {
		if ( ! is_user_logged_in() || empty( $course->id ) ) {
			return;
		}
		$quizzes = array_filter( SSLMS_Quizzes::for_course( (int) $course->id ), static function ( $q ) {
			return empty( $q->lesson_id );
		} );
		if ( ! $quizzes ) {
			return;
		}
		self::enqueue_assets();
		echo '<div class="sslms-course-quizzes"><h2>Course assessment</h2>';
		foreach ( $quizzes as $quiz ) {
			echo self::render_quiz_card( $quiz ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within render_quiz_card()
		}
		echo '</div>';
	}

	/** [sslms_quiz id="123"] — used internally by the two hooks above, and
	 * available for manual embedding (e.g. in a course description) so a
	 * course-level quiz is reachable even before Module A fires
	 * 'sslms_course_view'. */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'sslms_quiz' );
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$quiz = SSLMS_Quizzes::get_quiz( absint( $atts['id'] ) );
		if ( ! $quiz ) {
			return '';
		}
		self::enqueue_assets();
		return self::render_quiz_card( $quiz );
	}

	private static function enqueue_assets(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		wp_enqueue_style( 'sslms' );
		wp_enqueue_script( 'sslms' );
		wp_register_style( 'sslms-quiz', SSLMS_URL . 'assets/css/sslms-quiz.css', array( 'sslms' ), SSLMS_VERSION );
		wp_enqueue_style( 'sslms-quiz' );
		wp_register_script( 'sslms-quiz', SSLMS_URL . 'assets/js/sslms-quiz.js', array( 'sslms' ), SSLMS_VERSION, true );
		wp_enqueue_script( 'sslms-quiz' );
	}

	/** Renders the launcher card for one quiz: status, and a Start/Resume button. */
	private static function render_quiz_card( object $quiz ): string {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}

		$used   = SSLMS_Quizzes::attempts_used( (int) $quiz->id, $user_id );
		$best   = SSLMS_Quizzes::best_score( (int) $quiz->id, $user_id );
		$passed = SSLMS_Quizzes::user_passed( (int) $quiz->id, $user_id );
		$open   = SSLMS_Quizzes::open_attempt( (int) $quiz->id, $user_id );
		$max    = (int) $quiz->max_attempts;
		$exhausted = $max > 0 && $used >= $max && ! $open;

		$limit_label = $max > 0 ? (string) $max : 'Unlimited';

		if ( $passed ) {
			$badge = '<span class="sslms-badge sslms-badge--ok">Passed</span>';
		} elseif ( $used > 0 ) {
			$badge = '<span class="sslms-badge sslms-badge--bad">Not yet passed</span>';
		} else {
			$badge = '<span class="sslms-badge sslms-badge--muted">Not attempted</span>';
		}

		ob_start();
		?>
		<div class="sslms-quiz-card">
			<h3><?php echo esc_html( $quiz->title ); ?></h3>
			<p>
				<?php echo esc_html( $used . ' of ' . $limit_label . ' attempts used' ); ?>
				<?php if ( null !== $best ) : ?>
					&middot; Best score: <?php echo esc_html( number_format( (float) $best, 1 ) . '%' ); ?>
				<?php endif; ?>
				&middot; Pass mark: <?php echo esc_html( number_format( (float) $quiz->pass_pct, 0 ) . '%' ); ?>
				<?php if ( (int) $quiz->time_limit_min > 0 ) : ?>
					&middot; Time limit: <?php echo esc_html( (int) $quiz->time_limit_min . ' min' ); ?>
				<?php endif; ?>
				<?php echo $badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a fixed set of escaped strings above ?>
			</p>
			<?php if ( $exhausted ) : ?>
				<p class="sslms-badge sslms-badge--muted">No attempts remaining</p>
			<?php else : ?>
				<div class="sslms-quiz-launcher" data-quiz-id="<?php echo esc_attr( $quiz->id ); ?>">
					<button type="button" class="sslms-btn sslms-quiz-start-btn"><?php echo esc_html( $open ? 'Resume quiz' : 'Start quiz' ); ?></button>
					<div class="sslms-quiz-body" hidden></div>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
SSLMS_Portal_Quiz::init();
