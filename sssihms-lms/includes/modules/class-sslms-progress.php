<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module C — progress tracking (SPEC F4).
 *
 * Lesson completion is per-user/per-lesson (lesson_progress). Course
 * completion = all lessons in the course done AND all required quizzes
 * passed (SPEC F4.2); recalculate() is the single place that decides this
 * and flips the enrollment to completed.
 */
class SSLMS_Progress {

	public static function init(): void {
		add_action( 'sslms_quiz_graded', array( __CLASS__, 'on_quiz_graded' ) );
	}

	/** Has this user completed this lesson? */
	public static function lesson_done( int $lesson_id, int $user_id ): bool {
		global $wpdb;
		$t  = SSLMS_DB::table( 'lesson_progress' );
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE lesson_id = %d AND user_id = %d",
			$lesson_id,
			$user_id
		) );
		return (bool) $id;
	}

	/**
	 * Weighted completion percentage: each lesson counts as one unit, each
	 * required quiz counts as one unit (passed/not passed). A course with no
	 * lessons and no required quizzes is vacuously 100% complete.
	 */
	public static function course_pct( int $course_id, int $user_id ): int {
		$lesson_ids       = SSLMS_Courses::lesson_ids( $course_id );
		$required_quizzes = self::required_quizzes( $course_id );

		$total = count( $lesson_ids ) + count( $required_quizzes );
		if ( 0 === $total ) {
			return 100;
		}

		$done = 0;
		foreach ( $lesson_ids as $lesson_id ) {
			if ( self::lesson_done( (int) $lesson_id, $user_id ) ) {
				$done++;
			}
		}
		foreach ( $required_quizzes as $quiz ) {
			if ( SSLMS_Quizzes::user_passed( (int) $quiz->id, $user_id ) ) {
				$done++;
			}
		}

		return (int) round( ( $done / $total ) * 100 );
	}

	/**
	 * Re-evaluate whether $user_id has completed $course_id. When complete,
	 * flips the enrollment to status=completed (once — never downgrades an
	 * already-completed enrollment) and fires sslms_course_completed.
	 */
	public static function recalculate( int $course_id, int $user_id ): void {
		global $wpdb;
		$enr_t = SSLMS_DB::table( 'enrollments' );
		$enrollment = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$enr_t} WHERE course_id = %d AND user_id = %d",
			$course_id,
			$user_id
		) );

		if ( ! $enrollment ) {
			return;
		}
		// Already completed: idempotent, never downgrade/re-fire. Withdrawn
		// enrollments are not promoted to completed by background recalcs.
		if ( in_array( $enrollment->status, array( 'completed', 'withdrawn' ), true ) ) {
			return;
		}

		if ( ! self::is_course_complete( $course_id, $user_id ) ) {
			return;
		}

		$now = SSLMS_DB::now();
		SSLMS_DB::update(
			'enrollments',
			array(
				'status'       => 'completed',
				'completed_at' => $now,
			),
			array( 'id' => $enrollment->id )
		);

		$enrollment->status       = 'completed';
		$enrollment->completed_at = $now;

		SSLMS_Audit::log(
			'course_completed',
			'course',
			$course_id,
			sprintf( 'User #%d completed course #%d', $user_id, $course_id )
		);

		do_action( 'sslms_course_completed', $enrollment );
	}

	/**
	 * Marks a lesson complete for the current learner. Validates the lesson
	 * belongs to a course the user is enrolled in, inserts lesson_progress
	 * (idempotent), audits, then recalculates course completion.
	 *
	 * @return true|WP_Error
	 */
	public static function mark_lesson_complete( int $lesson_id, int $user_id ) {
		$course_id = self::course_id_for_lesson( $lesson_id );
		if ( ! $course_id ) {
			return new WP_Error( 'sslms_lesson_not_found', 'Lesson not found.', array( 'status' => 404 ) );
		}
		if ( ! SSLMS_Enrollments::is_enrolled( $course_id, $user_id ) ) {
			return new WP_Error( 'sslms_not_enrolled', 'You are not enrolled in this course.', array( 'status' => 403 ) );
		}

		global $wpdb;
		$t = SSLMS_DB::table( 'lesson_progress' );
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$t} (lesson_id, user_id, completed_at) VALUES (%d, %d, %s)",
			$lesson_id,
			$user_id,
			SSLMS_DB::now()
		) );

		if ( $inserted ) {
			SSLMS_Audit::log(
				'lesson_completed',
				'lesson',
				$lesson_id,
				sprintf( 'User #%d completed lesson #%d', $user_id, $lesson_id )
			);
		}

		self::recalculate( $course_id, $user_id );

		return true;
	}

	/** Hooked to sslms_quiz_graded — recalculates the quiz's course/user. */
	public static function on_quiz_graded( $attempt ): void {
		if ( ! is_object( $attempt ) || empty( $attempt->quiz_id ) || empty( $attempt->user_id ) ) {
			return;
		}
		global $wpdb;
		$quizzes_t = SSLMS_DB::table( 'quizzes' );
		$course_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT course_id FROM {$quizzes_t} WHERE id = %d",
			(int) $attempt->quiz_id
		) );
		if ( $course_id ) {
			self::recalculate( $course_id, (int) $attempt->user_id );
		}
	}

	/** All lessons done AND all required quizzes passed for this course/user. */
	private static function is_course_complete( int $course_id, int $user_id ): bool {
		foreach ( SSLMS_Courses::lesson_ids( $course_id ) as $lesson_id ) {
			if ( ! self::lesson_done( (int) $lesson_id, $user_id ) ) {
				return false;
			}
		}
		foreach ( self::required_quizzes( $course_id ) as $quiz ) {
			if ( ! SSLMS_Quizzes::user_passed( (int) $quiz->id, $user_id ) ) {
				return false;
			}
		}
		return true;
	}

	/** Required quizzes (course-level or lesson-gate) for a course. */
	private static function required_quizzes( int $course_id ): array {
		return array_values( array_filter(
			SSLMS_Quizzes::for_course( $course_id ),
			static function ( $quiz ) {
				return ! empty( $quiz->is_required );
			}
		) );
	}

	/** Resolves a lesson's course via lessons.module_id -> modules.course_id. */
	private static function course_id_for_lesson( int $lesson_id ): int {
		global $wpdb;
		$lessons_t = SSLMS_DB::table( 'lessons' );
		$modules_t = SSLMS_DB::table( 'modules' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT m.course_id FROM {$lessons_t} l INNER JOIN {$modules_t} m ON m.id = l.module_id WHERE l.id = %d",
			$lesson_id
		) );
	}
}
SSLMS_Progress::init();
