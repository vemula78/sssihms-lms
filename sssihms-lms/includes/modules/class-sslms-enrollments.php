<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module A — enrollment (SPEC F2.4). Admin/instructor enrol individuals;
 * "open enrollment" courses let eligible learners self-enrol.
 */
class SSLMS_Enrollments {

	/* -----------------------------------------------------------------
	 * Cross-module contract (CONVENTIONS.md) — do not change signatures.
	 * ------------------------------------------------------------- */

	/** Currently enrolled (active or completed) — not withdrawn. */
	public static function is_enrolled( int $course_id, int $user_id ): bool {
		$row = self::find( $course_id, $user_id );
		return (bool) ( $row && in_array( $row->status, array( 'active', 'completed' ), true ) );
	}

	/**
	 * Enrols (or re-activates a withdrawn enrollment for) a user in a course.
	 * Idempotent for an already active/completed enrollment.
	 *
	 * @return int|WP_Error Enrollment id.
	 */
	public static function enroll( int $course_id, int $user_id, int $by ) {
		$course = SSLMS_Courses::get( $course_id );
		if ( ! $course ) {
			return new WP_Error( 'sslms_not_found', 'Course not found.', array( 'status' => 404 ) );
		}
		if ( ! user_can( $user_id, 'sslms_learn' ) ) {
			return new WP_Error( 'sslms_not_a_learner', 'This user does not hold a learner role.', array( 'status' => 400 ) );
		}

		$existing = self::find( $course_id, $user_id );
		$now      = SSLMS_DB::now();

		if ( $existing && in_array( $existing->status, array( 'active', 'completed' ), true ) ) {
			return (int) $existing->id; // Already enrolled — idempotent, no duplicate audit entry.
		}

		if ( $existing ) {
			// Withdrawn previously — re-activate the same row (unique key on course_id+user_id).
			SSLMS_DB::update(
				'enrollments',
				array(
					'status'       => 'active',
					'enrolled_by'  => $by,
					'enrolled_at'  => $now,
					'completed_at' => null,
				),
				array( 'id' => $existing->id )
			);
			$id = (int) $existing->id;
		} else {
			$id = SSLMS_DB::insert( 'enrollments', array(
				'course_id'   => $course_id,
				'user_id'     => $user_id,
				'enrolled_by' => $by,
				'status'      => 'active',
				'enrolled_at' => $now,
			) );
			if ( ! $id ) {
				return new WP_Error( 'sslms_db_error', 'Could not enrol this user.', array( 'status' => 500 ) );
			}
		}

		SSLMS_Audit::log(
			'enrollment_created',
			'enrollment',
			$id,
			sprintf( 'User #%d enrolled in course #%d by user #%d', $user_id, $course_id, $by )
		);

		// A course with no lessons/quizzes is vacuously complete on enrollment.
		if ( class_exists( 'SSLMS_Progress' ) ) {
			SSLMS_Progress::recalculate( $course_id, $user_id );
		}

		return $id;
	}

	/** Courses created by this user (used for instructor-scoped views everywhere). */
	public static function instructor_course_ids( int $user_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'courses' );
		return $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE created_by = %d", $user_id ) );
	}

	/* -----------------------------------------------------------------
	 * Withdraw / self-enrol / listings
	 * ------------------------------------------------------------- */

	/** @return true|WP_Error */
	public static function withdraw( int $course_id, int $user_id, int $by = 0 ) {
		$existing = self::find( $course_id, $user_id );
		if ( ! $existing ) {
			return new WP_Error( 'sslms_not_enrolled', 'This user is not enrolled in this course.', array( 'status' => 404 ) );
		}
		if ( 'withdrawn' === $existing->status ) {
			return true; // Idempotent.
		}
		SSLMS_DB::update( 'enrollments', array( 'status' => 'withdrawn' ), array( 'id' => $existing->id ) );
		SSLMS_Audit::log(
			'enrollment_withdrawn',
			'enrollment',
			$existing->id,
			sprintf( 'User #%d withdrawn from course #%d by user #%d', $user_id, $course_id, $by )
		);
		return true;
	}

	/**
	 * Self-enrol: course must be published + open_enrollment, and the user
	 * must hold sslms_learn.
	 *
	 * @return int|WP_Error
	 */
	public static function self_enrol( int $course_id, int $user_id ) {
		if ( ! user_can( $user_id, 'sslms_learn' ) ) {
			return new WP_Error( 'sslms_forbidden', 'Not permitted.', array( 'status' => 403 ) );
		}
		$course = SSLMS_Courses::get( $course_id );
		if ( ! $course ) {
			return new WP_Error( 'sslms_not_found', 'Course not found.', array( 'status' => 404 ) );
		}
		if ( 'published' !== $course->status || empty( $course->open_enrollment ) ) {
			return new WP_Error( 'sslms_not_open', 'This course is not open for self-enrolment.', array( 'status' => 403 ) );
		}
		return self::enroll( $course_id, $user_id, $user_id );
	}

	/** Enrollment row (any status) for one course+user, or null. */
	public static function find( int $course_id, int $user_id ): ?object {
		global $wpdb;
		$t = SSLMS_DB::table( 'enrollments' );
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE course_id = %d AND user_id = %d",
			$course_id,
			$user_id
		) ) ?: null;
	}

	/** Current (active/completed) enrollments for a user, joined to course fields — "my courses". */
	public static function for_user( int $user_id ): array {
		global $wpdb;
		$enr_t    = SSLMS_DB::table( 'enrollments' );
		$course_t = SSLMS_DB::table( 'courses' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT e.id AS enrollment_id, e.status AS enrollment_status, e.enrolled_at, e.completed_at,
			        c.id AS course_id, c.title, c.track, c.status AS course_status
			 FROM {$enr_t} e
			 INNER JOIN {$course_t} c ON c.id = e.course_id
			 WHERE e.user_id = %d AND e.status IN ('active','completed')
			   AND c.status = 'published'
			 ORDER BY e.enrolled_at DESC",
			$user_id
		) );
	}

	/** Full roster (any status) for a course, joined to user display name — admin Enrol panel. */
	public static function for_course( int $course_id ): array {
		global $wpdb;
		$enr_t = SSLMS_DB::table( 'enrollments' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT e.id, e.user_id, e.status, e.enrolled_at, e.completed_at, u.display_name
			 FROM {$enr_t} e
			 INNER JOIN {$wpdb->users} u ON u.ID = e.user_id
			 WHERE e.course_id = %d
			 ORDER BY u.display_name ASC",
			$course_id
		) );
	}

	/**
	 * Published + open-enrollment courses the user is not already
	 * active/completed in — the "available to join" list.
	 */
	public static function available_open_courses( int $user_id ): array {
		global $wpdb;
		$course_t = SSLMS_DB::table( 'courses' );
		$enr_t    = SSLMS_DB::table( 'enrollments' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT c.* FROM {$course_t} c
			 WHERE c.status = 'published' AND c.open_enrollment = 1
			 AND c.id NOT IN (
			     SELECT course_id FROM {$enr_t} WHERE user_id = %d AND status IN ('active','completed')
			 )
			 ORDER BY c.title ASC",
			$user_id
		) );
	}
}
