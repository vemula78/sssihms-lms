<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module E — reflective journals with confidentiality controls (SPEC F8, ADR-5).
 *
 * user_can_read_entry() is the single gate. Every read path (single-entry
 * fetch, list_own, list_shared_with, comments) either calls the gate directly
 * or is built from SQL that encodes the same rule (mentor relationship /
 * instructor-course-enrollment join) — defense in depth per ADR-5. There is
 * NO admin override for entry content, including private entries; admins get
 * metadata counts only via admin_counts().
 */
class SSLMS_Journals {

	const VISIBILITIES = array( 'private', 'mentor', 'instructor' );

	/**
	 * THE gate. Author always. 'mentor' → viewer has a relationships row
	 * (user_id = author, related_user_id = viewer, rel_type = 'mentor').
	 * 'instructor' → viewer created a course the author is actively/completed
	 * enrolled in, and viewer holds sslms_author_courses. No admin override.
	 */
	public static function user_can_read_entry( object $entry, int $user_id ): bool {
		if ( (int) $entry->user_id === (int) $user_id ) {
			return true;
		}
		if ( 'mentor' === $entry->visibility ) {
			return self::is_mentor_of( $user_id, (int) $entry->user_id );
		}
		if ( 'instructor' === $entry->visibility ) {
			return self::is_qualifying_instructor( $user_id, (int) $entry->user_id );
		}
		return false; // private, or unrecognised visibility: author only.
	}

	private static function is_mentor_of( int $mentor_id, int $mentee_id ): bool {
		global $wpdb;
		$t   = SSLMS_DB::table( 'relationships' );
		$sql = "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND related_user_id = %d AND rel_type = 'mentor'";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $mentee_id, $mentor_id ) ) > 0;
	}

	private static function is_qualifying_instructor( int $viewer_id, int $author_id ): bool {
		if ( ! user_can( $viewer_id, 'sslms_author_courses' ) ) {
			return false;
		}
		global $wpdb;
		$courses = SSLMS_DB::table( 'courses' );
		$enroll  = SSLMS_DB::table( 'enrollments' );
		$sql     = "SELECT COUNT(*) FROM {$enroll} e
		            INNER JOIN {$courses} c ON c.id = e.course_id
		            WHERE c.created_by = %d AND e.user_id = %d AND e.status IN ('active','completed')";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $viewer_id, $author_id ) ) > 0;
	}

	private static function sanitize_visibility( string $v ): string {
		$v = strtolower( trim( $v ) );
		return in_array( $v, self::VISIBILITIES, true ) ? $v : '';
	}

	/** Raw row fetch — NOT gated. Callers must gate before exposing content. */
	public static function get_entry_row( int $id ): ?object {
		return SSLMS_DB::get_row( 'journal_entries', $id );
	}

	/**
	 * Gated single fetch for a viewer. Returns null (not found), false
	 * (forbidden) or the entry row.
	 */
	public static function get_for_viewer( int $id, int $viewer_id ) {
		$entry = self::get_entry_row( $id );
		if ( ! $entry ) {
			return null;
		}
		if ( ! self::user_can_read_entry( $entry, $viewer_id ) ) {
			return false;
		}
		return $entry;
	}

	public static function create_entry( int $user_id, string $title, string $body, string $visibility ) {
		$visibility = self::sanitize_visibility( $visibility );
		if ( ! $visibility ) {
			return new WP_Error( 'sslms_invalid_visibility', 'Invalid visibility.' );
		}
		$title = trim( sanitize_text_field( $title ) );
		if ( '' === $title ) {
			return new WP_Error( 'sslms_invalid', 'Title is required.' );
		}
		if ( mb_strlen( $title ) > 200 ) {
			$title = mb_substr( $title, 0, 200 );
		}
		$body = wp_kses_post( $body );
		$now  = SSLMS_DB::now();
		$id   = SSLMS_DB::insert( 'journal_entries', array(
			'user_id'    => $user_id,
			'title'      => $title,
			'body'       => $body,
			'visibility' => $visibility,
			'created_at' => $now,
			'updated_at' => $now,
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_db_error', 'Could not save entry.' );
		}
		// Metadata only — never the title or body content.
		SSLMS_Audit::log( 'journal_entry_created', 'journal_entry', $id, 'Visibility: ' . $visibility );
		return $id;
	}

	/** Owner-only update. $data may contain any of title/body/visibility. */
	public static function update_entry( int $id, int $user_id, array $data ) {
		$entry = self::get_entry_row( $id );
		if ( ! $entry ) {
			return new WP_Error( 'sslms_not_found', 'Entry not found.' );
		}
		if ( (int) $entry->user_id !== $user_id ) {
			return new WP_Error( 'sslms_forbidden', 'Not permitted.' );
		}

		$update = array( 'updated_at' => SSLMS_DB::now() );

		if ( array_key_exists( 'title', $data ) ) {
			$title = trim( sanitize_text_field( (string) $data['title'] ) );
			if ( '' === $title ) {
				return new WP_Error( 'sslms_invalid', 'Title is required.' );
			}
			$update['title'] = mb_substr( $title, 0, 200 );
		}

		if ( array_key_exists( 'body', $data ) ) {
			$update['body'] = wp_kses_post( (string) $data['body'] );
		}

		$old_visibility     = $entry->visibility;
		$visibility_changed = false;
		if ( array_key_exists( 'visibility', $data ) ) {
			$visibility = self::sanitize_visibility( (string) $data['visibility'] );
			if ( ! $visibility ) {
				return new WP_Error( 'sslms_invalid_visibility', 'Invalid visibility.' );
			}
			$update['visibility'] = $visibility;
			$visibility_changed   = ( $visibility !== $old_visibility );
		}

		$ok = SSLMS_DB::update( 'journal_entries', $update, array( 'id' => $id ) );
		if ( ! $ok ) {
			return new WP_Error( 'sslms_db_error', 'Could not update entry.' );
		}

		if ( $visibility_changed ) {
			// Metadata only — old/new visibility, never content.
			SSLMS_Audit::log(
				'journal_visibility_changed',
				'journal_entry',
				$id,
				sprintf( 'Visibility changed from %s to %s', $old_visibility, $update['visibility'] )
			);
		}

		return true;
	}

	/** Owner-only delete; also removes the entry's comments. */
	public static function delete_entry( int $id, int $user_id ) {
		$entry = self::get_entry_row( $id );
		if ( ! $entry ) {
			return new WP_Error( 'sslms_not_found', 'Entry not found.' );
		}
		if ( (int) $entry->user_id !== $user_id ) {
			return new WP_Error( 'sslms_forbidden', 'Not permitted.' );
		}
		global $wpdb;
		$wpdb->delete( SSLMS_DB::table( 'journal_comments' ), array( 'entry_id' => $id ), array( '%d' ) );
		SSLMS_DB::delete( 'journal_entries', array( 'id' => $id ) );
		SSLMS_Audit::log( 'journal_entry_deleted', 'journal_entry', $id, 'Visibility was: ' . $entry->visibility );
		return true;
	}

	/** Own entries, newest first. Scoped SQL (defense in depth). */
	public static function list_own( int $user_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'journal_entries' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE user_id = %d ORDER BY created_at DESC",
			$user_id
		) );
	}

	/**
	 * Entries readable by $viewer as mentor/instructor. Built entirely from
	 * scoped joins (relationships / enrollments+courses) — never fetch-all-
	 * then-filter-in-PHP. Combines both roles if the viewer holds both caps.
	 */
	public static function list_shared_with( int $viewer_id ): array {
		global $wpdb;
		$entries_t = SSLMS_DB::table( 'journal_entries' );
		$out       = array();

		if ( user_can( $viewer_id, 'sslms_mentor_journals' ) ) {
			$rel_t = SSLMS_DB::table( 'relationships' );
			$sql   = "SELECT je.* FROM {$entries_t} je
			          INNER JOIN {$rel_t} r ON r.user_id = je.user_id
			              AND r.related_user_id = %d AND r.rel_type = 'mentor'
			          WHERE je.visibility = 'mentor'
			          ORDER BY je.created_at DESC";
			foreach ( $wpdb->get_results( $wpdb->prepare( $sql, $viewer_id ) ) as $row ) {
				$out[ $row->id ] = $row;
			}
		}

		if ( user_can( $viewer_id, 'sslms_author_courses' ) ) {
			$enroll_t  = SSLMS_DB::table( 'enrollments' );
			$courses_t = SSLMS_DB::table( 'courses' );
			$sql       = "SELECT DISTINCT je.* FROM {$entries_t} je
			              INNER JOIN {$enroll_t} e ON e.user_id = je.user_id
			                  AND e.status IN ('active','completed')
			              INNER JOIN {$courses_t} c ON c.id = e.course_id AND c.created_by = %d
			              WHERE je.visibility = 'instructor'
			              ORDER BY je.created_at DESC";
			foreach ( $wpdb->get_results( $wpdb->prepare( $sql, $viewer_id ) ) as $row ) {
				$out[ $row->id ] = $row;
			}
		}

		$list = array_values( $out );
		usort( $list, static function ( $a, $b ) {
			return strcmp( (string) $b->created_at, (string) $a->created_at );
		} );
		return $list;
	}

	/**
	 * Comment allowed when the author passes the read gate AND is either the
	 * entry author replying, or the qualifying mentor/instructor for it.
	 */
	public static function add_comment( int $entry_id, int $author_id, string $body ) {
		$entry = self::get_entry_row( $entry_id );
		if ( ! $entry ) {
			return new WP_Error( 'sslms_not_found', 'Entry not found.' );
		}
		if ( ! self::user_can_read_entry( $entry, $author_id ) ) {
			return new WP_Error( 'sslms_forbidden', 'Not permitted.' );
		}

		$is_author = ( (int) $entry->user_id === $author_id );
		$qualifies = false;
		if ( ! $is_author ) {
			if ( 'mentor' === $entry->visibility
				&& user_can( $author_id, 'sslms_mentor_journals' )
				&& self::is_mentor_of( $author_id, (int) $entry->user_id ) ) {
				$qualifies = true;
			} elseif ( 'instructor' === $entry->visibility
				&& self::is_qualifying_instructor( $author_id, (int) $entry->user_id ) ) {
				$qualifies = true;
			}
		}
		if ( ! $is_author && ! $qualifies ) {
			return new WP_Error( 'sslms_forbidden', 'Not permitted.' );
		}

		$body = wp_kses_post( $body );
		if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
			return new WP_Error( 'sslms_invalid', 'Comment text is required.' );
		}

		$id = SSLMS_DB::insert( 'journal_comments', array(
			'entry_id'   => $entry_id,
			'author_id'  => $author_id,
			'body'       => $body,
			'created_at' => SSLMS_DB::now(),
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_db_error', 'Could not save comment.' );
		}
		SSLMS_Audit::log( 'journal_comment_added', 'journal_entry', $entry_id, 'Comment added' );
		return $id;
	}

	/** List comments — gated identically to the entry itself. */
	public static function list_comments( int $entry_id, int $viewer_id ) {
		$entry = self::get_entry_row( $entry_id );
		if ( ! $entry ) {
			return new WP_Error( 'sslms_not_found', 'Entry not found.' );
		}
		if ( ! self::user_can_read_entry( $entry, $viewer_id ) ) {
			return new WP_Error( 'sslms_forbidden', 'Not permitted.' );
		}
		global $wpdb;
		$t = SSLMS_DB::table( 'journal_comments' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE entry_id = %d ORDER BY created_at ASC",
			$entry_id
		) );
	}

	/** Count of comments left on the user's own entries in the last 7 days. */
	public static function new_comment_count_last7( int $user_id ): int {
		global $wpdb;
		$c     = SSLMS_DB::table( 'journal_comments' );
		$e     = SSLMS_DB::table( 'journal_entries' );
		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
		$sql   = "SELECT COUNT(*) FROM {$c} jc
		          INNER JOIN {$e} je ON je.id = jc.entry_id
		          WHERE je.user_id = %d AND jc.created_at >= %s";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $user_id, $since ) );
	}

	/**
	 * Admin metadata: per-user entry counts by visibility. Content is never
	 * touched or returned (SPEC F8.2 — admins get counts/metadata only).
	 */
	public static function admin_counts(): array {
		global $wpdb;
		$t    = SSLMS_DB::table( 'journal_entries' );
		$rows = $wpdb->get_results( "SELECT user_id, visibility, COUNT(*) AS c FROM {$t} GROUP BY user_id, visibility" );
		$out  = array();
		foreach ( $rows as $row ) {
			$uid = (int) $row->user_id;
			if ( ! isset( $out[ $uid ] ) ) {
				$user            = get_userdata( $uid );
				$out[ $uid ]     = array(
					'user_id'      => $uid,
					'display_name' => $user ? $user->display_name : ( 'User #' . $uid ),
					'private'      => 0,
					'mentor'       => 0,
					'instructor'   => 0,
					'total'        => 0,
				);
			}
			$out[ $uid ][ $row->visibility ] = (int) $row->c;
			$out[ $uid ]['total']           += (int) $row->c;
		}
		return array_values( $out );
	}
}
