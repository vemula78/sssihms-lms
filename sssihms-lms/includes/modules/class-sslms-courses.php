<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module A — course / module / lesson authoring (SPEC F2).
 *
 * Hierarchy: courses -> modules (ordered) -> lessons (ordered). This class
 * owns the contract functions consumed by Modules B/C (see CONVENTIONS.md)
 * plus the CRUD the admin authoring screens need.
 */
class SSLMS_Courses {

	const TRACKS   = array( 'clinical', 'allied', 'chaplaincy' );
	const STATUSES = array( 'draft', 'published', 'archived' );

	/* -----------------------------------------------------------------
	 * Cross-module contract (CONVENTIONS.md) — do not change signatures.
	 * ------------------------------------------------------------- */

	public static function get( int $id ): ?object {
		return SSLMS_DB::get_row( 'courses', $id );
	}

	/** Modules of a course, ordered. */
	public static function modules( int $course_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'modules' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE course_id = %d ORDER BY sort_order ASC, id ASC",
			$course_id
		) );
	}

	/** Lessons of a module, ordered. */
	public static function lessons( int $module_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'lessons' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE module_id = %d ORDER BY sort_order ASC, id ASC",
			$module_id
		) );
	}

	/** All lesson ids belonging to a course (across all its modules). */
	public static function lesson_ids( int $course_id ): array {
		global $wpdb;
		$lessons_t = SSLMS_DB::table( 'lessons' );
		$modules_t = SSLMS_DB::table( 'modules' );
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT l.id FROM {$lessons_t} l INNER JOIN {$modules_t} m ON m.id = l.module_id WHERE m.course_id = %d ORDER BY m.sort_order ASC, l.sort_order ASC",
			$course_id
		) );
	}

	/* -----------------------------------------------------------------
	 * Lookups
	 * ------------------------------------------------------------- */

	public static function get_module( int $id ): ?object {
		return SSLMS_DB::get_row( 'modules', $id );
	}

	public static function get_lesson( int $id ): ?object {
		return SSLMS_DB::get_row( 'lessons', $id );
	}

	/** Decoded attachment media IDs for a lesson row (empty array if none/invalid). */
	public static function lesson_attachments( object $lesson ): array {
		if ( empty( $lesson->attachments ) ) {
			return array();
		}
		$ids = json_decode( $lesson->attachments, true );
		return is_array( $ids ) ? array_values( array_map( 'absint', $ids ) ) : array();
	}

	public static function course_id_for_module( int $module_id ): int {
		$m = self::get_module( $module_id );
		return $m ? (int) $m->course_id : 0;
	}

	public static function course_id_for_lesson( int $lesson_id ): int {
		global $wpdb;
		$lessons_t = SSLMS_DB::table( 'lessons' );
		$modules_t = SSLMS_DB::table( 'modules' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT m.course_id FROM {$lessons_t} l INNER JOIN {$modules_t} m ON m.id = l.module_id WHERE l.id = %d",
			$lesson_id
		) );
	}

	/** True for sslms_manage, or the user who created the course. */
	public static function can_edit( int $course_id, int $user_id ): bool {
		if ( user_can( $user_id, 'sslms_manage' ) ) {
			return true;
		}
		$course = self::get( $course_id );
		return $course && (int) $course->created_by === $user_id;
	}

	public static function enrolled_count( int $course_id ): int {
		global $wpdb;
		$t = SSLMS_DB::table( 'enrollments' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE course_id = %d", $course_id ) );
	}

	/**
	 * Course list for the admin screen. $args: 'created_by' (int, 0 = no filter).
	 * Each row gets an `enrolled_count` property.
	 */
	public static function list_courses( array $args = array() ): array {
		global $wpdb;
		$t          = SSLMS_DB::table( 'courses' );
		$enr_t      = SSLMS_DB::table( 'enrollments' );
		$created_by = isset( $args['created_by'] ) ? (int) $args['created_by'] : 0;

		$sql = "SELECT c.*, (SELECT COUNT(*) FROM {$enr_t} e WHERE e.course_id = c.id) AS enrolled_count
				FROM {$t} c";
		if ( $created_by ) {
			$sql   .= ' WHERE c.created_by = %d';
			$rows   = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY c.title ASC', $created_by ) );
		} else {
			$rows = $wpdb->get_results( $sql . ' ORDER BY c.title ASC' );
		}
		foreach ( $rows as $row ) {
			$row->enrolled_count = (int) $row->enrolled_count;
		}
		return $rows;
	}

	/* -----------------------------------------------------------------
	 * Course CRUD
	 * ------------------------------------------------------------- */

	/**
	 * @param array $data title, description, track, status, open_enrollment.
	 * @return int|WP_Error New course id.
	 */
	public static function create( array $data, int $by ) {
		$title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
		if ( '' === trim( $title ) ) {
			return new WP_Error( 'sslms_missing_title', 'Course title is required.', array( 'status' => 400 ) );
		}
		$track  = in_array( $data['track'] ?? '', self::TRACKS, true ) ? $data['track'] : 'clinical';
		$status = in_array( $data['status'] ?? '', self::STATUSES, true ) ? $data['status'] : 'draft';
		$open   = empty( $data['open_enrollment'] ) ? 0 : 1;
		$desc   = isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '';
		$now    = SSLMS_DB::now();

		$id = SSLMS_DB::insert( 'courses', array(
			'title'           => $title,
			'slug'            => self::unique_slug( $title ),
			'description'     => $desc,
			'track'           => $track,
			'status'          => $status,
			'open_enrollment' => $open,
			'created_by'      => $by,
			'created_at'      => $now,
			'updated_at'      => $now,
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_db_error', 'Could not create the course.', array( 'status' => 500 ) );
		}

		SSLMS_Audit::log( 'course_created', 'course', $id, 'Course "' . $title . '" created' );
		if ( 'published' === $status ) {
			SSLMS_Audit::log( 'course_published', 'course', $id, 'Course "' . $title . '" published' );
		}
		return $id;
	}

	/**
	 * @param array $data any of title, description, track, status, open_enrollment.
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		$course = self::get( $id );
		if ( ! $course ) {
			return new WP_Error( 'sslms_not_found', 'Course not found.', array( 'status' => 404 ) );
		}

		$fields = array();
		if ( array_key_exists( 'title', $data ) ) {
			$title = sanitize_text_field( $data['title'] );
			if ( '' === trim( $title ) ) {
				return new WP_Error( 'sslms_missing_title', 'Course title is required.', array( 'status' => 400 ) );
			}
			$fields['title'] = $title;
		}
		if ( array_key_exists( 'description', $data ) ) {
			$fields['description'] = sanitize_textarea_field( $data['description'] );
		}
		if ( array_key_exists( 'track', $data ) && in_array( $data['track'], self::TRACKS, true ) ) {
			$fields['track'] = $data['track'];
		}
		if ( array_key_exists( 'open_enrollment', $data ) ) {
			$fields['open_enrollment'] = empty( $data['open_enrollment'] ) ? 0 : 1;
		}

		$new_status = null;
		if ( array_key_exists( 'status', $data ) && in_array( $data['status'], self::STATUSES, true ) && $data['status'] !== $course->status ) {
			$new_status       = $data['status'];
			$fields['status'] = $new_status;
		}

		if ( ! $fields ) {
			return true; // Nothing to do.
		}

		$fields['updated_at'] = SSLMS_DB::now();
		$ok                   = SSLMS_DB::update( 'courses', $fields, array( 'id' => $id ) );
		if ( ! $ok ) {
			return new WP_Error( 'sslms_db_error', 'Could not update the course.', array( 'status' => 500 ) );
		}

		$label = $fields['title'] ?? $course->title;
		SSLMS_Audit::log( 'course_updated', 'course', $id, 'Course "' . $label . '" updated' );
		if ( 'published' === $new_status ) {
			SSLMS_Audit::log( 'course_published', 'course', $id, 'Course "' . $label . '" published' );
		} elseif ( 'archived' === $new_status ) {
			SSLMS_Audit::log( 'course_archived', 'course', $id, 'Course "' . $label . '" archived' );
		}
		return true;
	}

	/**
	 * Blocks deletion of a course that has any enrollments — archive instead
	 * (per project rules). Cascades to modules/lessons and cleans their
	 * lesson_progress rows.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( int $id ) {
		$course = self::get( $id );
		if ( ! $course ) {
			return new WP_Error( 'sslms_not_found', 'Course not found.', array( 'status' => 404 ) );
		}
		if ( self::enrolled_count( $id ) > 0 ) {
			return new WP_Error(
				'sslms_has_enrollments',
				'This course has enrollments; archive it instead of deleting.',
				array( 'status' => 409 )
			);
		}

		foreach ( self::modules( $id ) as $module ) {
			foreach ( self::lessons( (int) $module->id ) as $lesson ) {
				self::purge_lesson( (int) $lesson->id );
			}
			SSLMS_DB::delete( 'modules', array( 'id' => $module->id ) );
		}
		SSLMS_DB::delete( 'courses', array( 'id' => $id ) );

		SSLMS_Audit::log( 'course_deleted', 'course', $id, 'Course "' . $course->title . '" deleted' );
		return true;
	}

	private static function unique_slug( string $title, int $exclude_id = 0 ): string {
		global $wpdb;
		$t    = SSLMS_DB::table( 'courses' );
		$base = sanitize_title( $title );
		if ( '' === $base ) {
			$base = 'course';
		}
		$slug = $base;
		$n    = 1;
		while ( true ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$t} WHERE slug = %s AND id != %d",
				$slug,
				$exclude_id
			) );
			if ( ! $exists ) {
				return $slug;
			}
			$n++;
			$slug = $base . '-' . $n;
		}
	}

	/* -----------------------------------------------------------------
	 * Module CRUD
	 * ------------------------------------------------------------- */

	/** @return int|WP_Error */
	public static function create_module( int $course_id, string $title, ?int $sort_order = null ) {
		if ( ! self::get( $course_id ) ) {
			return new WP_Error( 'sslms_not_found', 'Course not found.', array( 'status' => 404 ) );
		}
		$title = sanitize_text_field( $title );
		if ( '' === trim( $title ) ) {
			return new WP_Error( 'sslms_missing_title', 'Module title is required.', array( 'status' => 400 ) );
		}
		if ( null === $sort_order ) {
			$sort_order = self::next_sort_order( 'modules', 'course_id', $course_id );
		}
		$id = SSLMS_DB::insert( 'modules', array(
			'course_id'  => $course_id,
			'title'      => $title,
			'sort_order' => $sort_order,
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_db_error', 'Could not create the module.', array( 'status' => 500 ) );
		}
		SSLMS_Audit::log( 'module_created', 'module', $id, 'Module "' . $title . '" added to course #' . $course_id );
		return $id;
	}

	/** @return true|WP_Error */
	public static function update_module( int $id, array $data ) {
		$module = self::get_module( $id );
		if ( ! $module ) {
			return new WP_Error( 'sslms_not_found', 'Module not found.', array( 'status' => 404 ) );
		}
		$fields = array();
		if ( array_key_exists( 'title', $data ) ) {
			$title = sanitize_text_field( $data['title'] );
			if ( '' === trim( $title ) ) {
				return new WP_Error( 'sslms_missing_title', 'Module title is required.', array( 'status' => 400 ) );
			}
			$fields['title'] = $title;
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$fields['sort_order'] = (int) $data['sort_order'];
		}
		if ( ! $fields ) {
			return true;
		}
		SSLMS_DB::update( 'modules', $fields, array( 'id' => $id ) );
		SSLMS_Audit::log( 'module_updated', 'module', $id, 'Module updated' );
		return true;
	}

	/** @return true|WP_Error */
	public static function delete_module( int $id ) {
		$module = self::get_module( $id );
		if ( ! $module ) {
			return new WP_Error( 'sslms_not_found', 'Module not found.', array( 'status' => 404 ) );
		}
		foreach ( self::lessons( $id ) as $lesson ) {
			self::purge_lesson( (int) $lesson->id );
		}
		SSLMS_DB::delete( 'modules', array( 'id' => $id ) );
		SSLMS_Audit::log( 'module_deleted', 'module', $id, 'Module "' . $module->title . '" deleted' );
		return true;
	}

	/** Re-sequences sort_order 0..n-1 for the given module ids (must belong to $course_id). */
	public static function reorder_modules( int $course_id, array $ids ): void {
		$order = 0;
		foreach ( $ids as $module_id ) {
			$module_id = (int) $module_id;
			$module    = self::get_module( $module_id );
			if ( $module && (int) $module->course_id === $course_id ) {
				SSLMS_DB::update( 'modules', array( 'sort_order' => $order ), array( 'id' => $module_id ) );
			}
			$order++;
		}
	}

	/* -----------------------------------------------------------------
	 * Lesson CRUD
	 * ------------------------------------------------------------- */

	/**
	 * @param array $data title (required), content, video_url, attachments (int[]), est_minutes, sort_order.
	 * @return int|WP_Error
	 */
	public static function create_lesson( int $module_id, array $data ) {
		if ( ! self::get_module( $module_id ) ) {
			return new WP_Error( 'sslms_not_found', 'Module not found.', array( 'status' => 404 ) );
		}
		$title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
		if ( '' === trim( $title ) ) {
			return new WP_Error( 'sslms_missing_title', 'Lesson title is required.', array( 'status' => 400 ) );
		}
		$sort_order = isset( $data['sort_order'] ) ? (int) $data['sort_order'] : self::next_sort_order( 'lessons', 'module_id', $module_id );

		$id = SSLMS_DB::insert( 'lessons', array(
			'module_id'   => $module_id,
			'title'       => $title,
			'content'     => isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '',
			'video_url'   => self::sanitize_video_url( $data['video_url'] ?? '' ),
			'attachments' => self::encode_attachments( $data['attachments'] ?? array() ),
			'sort_order'  => $sort_order,
			'est_minutes' => isset( $data['est_minutes'] ) ? absint( $data['est_minutes'] ) : 0,
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_db_error', 'Could not create the lesson.', array( 'status' => 500 ) );
		}
		SSLMS_Audit::log( 'lesson_created', 'lesson', $id, 'Lesson "' . $title . '" added to module #' . $module_id );
		return $id;
	}

	/** @return true|WP_Error */
	public static function update_lesson( int $id, array $data ) {
		$lesson = self::get_lesson( $id );
		if ( ! $lesson ) {
			return new WP_Error( 'sslms_not_found', 'Lesson not found.', array( 'status' => 404 ) );
		}
		$fields = array();
		if ( array_key_exists( 'title', $data ) ) {
			$title = sanitize_text_field( $data['title'] );
			if ( '' === trim( $title ) ) {
				return new WP_Error( 'sslms_missing_title', 'Lesson title is required.', array( 'status' => 400 ) );
			}
			$fields['title'] = $title;
		}
		if ( array_key_exists( 'content', $data ) ) {
			$fields['content'] = wp_kses_post( $data['content'] );
		}
		if ( array_key_exists( 'video_url', $data ) ) {
			$fields['video_url'] = self::sanitize_video_url( $data['video_url'] );
		}
		if ( array_key_exists( 'attachments', $data ) ) {
			$fields['attachments'] = self::encode_attachments( $data['attachments'] );
		}
		if ( array_key_exists( 'est_minutes', $data ) ) {
			$fields['est_minutes'] = absint( $data['est_minutes'] );
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$fields['sort_order'] = (int) $data['sort_order'];
		}
		if ( ! $fields ) {
			return true;
		}
		SSLMS_DB::update( 'lessons', $fields, array( 'id' => $id ) );
		SSLMS_Audit::log( 'lesson_updated', 'lesson', $id, 'Lesson updated' );
		return true;
	}

	/** @return true|WP_Error */
	public static function delete_lesson( int $id ) {
		$lesson = self::get_lesson( $id );
		if ( ! $lesson ) {
			return new WP_Error( 'sslms_not_found', 'Lesson not found.', array( 'status' => 404 ) );
		}
		self::purge_lesson( $id );
		SSLMS_Audit::log( 'lesson_deleted', 'lesson', $id, 'Lesson "' . $lesson->title . '" deleted' );
		return true;
	}

	/** Re-sequences sort_order 0..n-1 for the given lesson ids (must belong to $module_id). */
	public static function reorder_lessons( int $module_id, array $ids ): void {
		$order = 0;
		foreach ( $ids as $lesson_id ) {
			$lesson_id = (int) $lesson_id;
			$lesson    = self::get_lesson( $lesson_id );
			if ( $lesson && (int) $lesson->module_id === $module_id ) {
				SSLMS_DB::update( 'lessons', array( 'sort_order' => $order ), array( 'id' => $lesson_id ) );
			}
			$order++;
		}
	}

	/* -----------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------- */

	/** Deletes a lesson row and its lesson_progress rows (no audit; callers log at their own granularity). */
	private static function purge_lesson( int $lesson_id ): void {
		global $wpdb;
		$progress_t = SSLMS_DB::table( 'lesson_progress' );
		$wpdb->delete( $progress_t, array( 'lesson_id' => $lesson_id ) );
		SSLMS_DB::delete( 'lessons', array( 'id' => $lesson_id ) );
	}

	private static function next_sort_order( string $table, string $fk_column, int $fk_value ): int {
		global $wpdb;
		$t   = SSLMS_DB::table( $table );
		$col = preg_replace( '/[^a-z_]/', '', $fk_column );
		$max = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$t} WHERE {$col} = %d", $fk_value ) );
		return null === $max ? 0 : ( (int) $max + 1 );
	}

	/** Only http(s) URLs are kept; anything else (or empty) becomes ''. */
	private static function sanitize_video_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		return substr( $url, 0, 500 );
	}

	/** Normalises an attachments payload (array of media IDs) to a JSON string of valid ints. */
	private static function encode_attachments( $ids ): string {
		if ( ! is_array( $ids ) ) {
			return '[]';
		}
		$clean = array();
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}
		return wp_json_encode( array_values( array_unique( $clean ) ) );
	}
}
