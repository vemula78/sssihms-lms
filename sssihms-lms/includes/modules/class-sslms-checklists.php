<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module F6 — Skills checklists with preceptor/evaluator sign-off.
 *
 * Scoping (per CONVENTIONS/task brief): a preceptor/evaluator may sign items
 * for learner X only if a `relationships` row exists with user_id = X,
 * related_user_id = <acting user>, rel_type IN ('preceptor','evaluator').
 * Users with the sslms_manage capability bypass this scoping.
 */
class SSLMS_Checklists {

	const RATINGS = array( 'needs_practice', 'with_supervision', 'competent' );

	/* ---------------------------------------------------------------
	 * Template CRUD
	 * ------------------------------------------------------------- */

	public static function get_checklist( int $id ): ?object {
		return SSLMS_DB::get_row( 'checklists', $id );
	}

	/** All templates, or only active ones. */
	public static function list_checklists( bool $active_only = false ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'checklists' );
		if ( $active_only ) {
			return $wpdb->get_results( "SELECT * FROM {$t} WHERE is_active = 1 ORDER BY title ASC" );
		}
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY title ASC" );
	}

	public static function create_checklist( string $title, string $discipline, string $description ): int {
		return SSLMS_DB::insert( 'checklists', array(
			'title'       => $title,
			'discipline'  => $discipline,
			'description' => $description,
			'is_active'   => 1,
		) );
	}

	public static function update_checklist( int $id, array $args ): bool {
		$data = array();
		foreach ( array( 'title', 'discipline', 'description' ) as $f ) {
			if ( array_key_exists( $f, $args ) ) {
				$data[ $f ] = $args[ $f ];
			}
		}
		if ( array_key_exists( 'is_active', $args ) ) {
			$data['is_active'] = (int) (bool) $args['is_active'];
		}
		if ( ! $data ) {
			return false;
		}
		$ok = SSLMS_DB::update( 'checklists', $data, array( 'id' => $id ) );
		if ( $ok ) {
			SSLMS_Audit::log( 'checklist_updated', 'checklist', $id, 'Fields: ' . implode( ',', array_keys( $data ) ) );
		}
		return $ok;
	}

	/**
	 * Delete a template. Refuses (returns WP_Error) once it has any
	 * assignments, since assignments carry sign-off history that must be
	 * retained for NABH audit; deactivate() instead in that case.
	 */
	public static function delete_checklist( int $id ) {
		global $wpdb;
		$au = SSLMS_DB::table( 'checklist_assignments' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$au} WHERE checklist_id = %d", $id ) );
		if ( $count > 0 ) {
			return new WP_Error( 'sslms_in_use', 'This checklist has assignments and cannot be deleted; deactivate it instead.' );
		}
		$it = SSLMS_DB::table( 'checklist_items' );
		$wpdb->delete( $it, array( 'checklist_id' => $id ) );
		$ok = SSLMS_DB::delete( 'checklists', array( 'id' => $id ) );
		if ( $ok ) {
			SSLMS_Audit::log( 'checklist_deleted', 'checklist', $id, 'Unused template deleted' );
		}
		return $ok;
	}

	/* ---------------------------------------------------------------
	 * Item CRUD
	 * ------------------------------------------------------------- */

	/** Ordered items of a checklist. */
	public static function get_items( int $checklist_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'checklist_items' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE checklist_id = %d ORDER BY sort_order ASC, id ASC", $checklist_id
		) );
	}

	public static function add_item( int $checklist_id, string $item_text, ?int $sort_order = null ): int {
		if ( null === $sort_order ) {
			global $wpdb;
			$t = SSLMS_DB::table( 'checklist_items' );
			$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$t} WHERE checklist_id = %d", $checklist_id ) );
			$sort_order = $max + 10;
		}
		return SSLMS_DB::insert( 'checklist_items', array(
			'checklist_id' => $checklist_id,
			'item_text'    => $item_text,
			'sort_order'   => $sort_order,
		) );
	}

	public static function update_item( int $item_id, array $args ): bool {
		$data = array();
		if ( array_key_exists( 'item_text', $args ) ) {
			$data['item_text'] = $args['item_text'];
		}
		if ( array_key_exists( 'sort_order', $args ) ) {
			$data['sort_order'] = (int) $args['sort_order'];
		}
		if ( ! $data ) {
			return false;
		}
		return SSLMS_DB::update( 'checklist_items', $data, array( 'id' => $item_id ) );
	}

	public static function delete_item( int $item_id ) {
		global $wpdb;
		$so   = SSLMS_DB::table( 'checklist_signoffs' );
		$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$so} WHERE item_id = %d", $item_id ) );
		if ( $used > 0 ) {
			return new WP_Error( 'sslms_item_has_signoffs', 'This item has recorded sign-offs and cannot be deleted (NABH retention). Edit its text instead.' );
		}
		$ok = SSLMS_DB::delete( 'checklist_items', array( 'id' => $item_id ) );
		if ( $ok ) {
			SSLMS_Audit::log( 'checklist_item_deleted', 'checklist_item', $item_id, 'Checklist item deleted' );
		}
		return $ok;
	}

	/** Reorder: $ordered_item_ids = item ids of one checklist, in the new order. */
	public static function reorder_items( int $checklist_id, array $ordered_item_ids ): bool {
		global $wpdb;
		$t = SSLMS_DB::table( 'checklist_items' );
		$pos = 10;
		$ok = true;
		foreach ( $ordered_item_ids as $item_id ) {
			$item_id = absint( $item_id );
			if ( ! $item_id ) {
				continue;
			}
			$res = $wpdb->update( $t, array( 'sort_order' => $pos ), array( 'id' => $item_id, 'checklist_id' => $checklist_id ) );
			$ok  = $ok && ( false !== $res );
			$pos += 10;
		}
		return $ok;
	}

	/* ---------------------------------------------------------------
	 * Assignment
	 * ------------------------------------------------------------- */

	public static function get_assignment( int $assignment_id ): ?object {
		return SSLMS_DB::get_row( 'checklist_assignments', $assignment_id );
	}

	/** Assign a checklist template to a learner. Unique per (checklist, user). */
	public static function assign( int $checklist_id, int $user_id, int $by ) {
		global $wpdb;
		$t = SSLMS_DB::table( 'checklist_assignments' );
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE checklist_id = %d AND user_id = %d", $checklist_id, $user_id
		) );
		if ( $exists ) {
			return new WP_Error( 'sslms_duplicate', 'This checklist is already assigned to that learner.' );
		}
		$id = SSLMS_DB::insert( 'checklist_assignments', array(
			'checklist_id' => $checklist_id,
			'user_id'      => $user_id,
			'assigned_by'  => $by,
			'assigned_at'  => SSLMS_DB::now(),
			'completed_at' => null,
			'locked'       => 0,
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_db_error', 'Could not create assignment.' );
		}
		SSLMS_Audit::log( 'checklist_assigned', 'checklist_assignment', $id, 'Checklist ' . $checklist_id . ' assigned to user ' . $user_id );
		return $id;
	}

	/** Assignments (with checklist meta) for one learner. */
	public static function assignments_for_user( int $user_id ): array {
		global $wpdb;
		$a = SSLMS_DB::table( 'checklist_assignments' );
		$c = SSLMS_DB::table( 'checklists' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT a.*, c.title, c.discipline FROM {$a} a JOIN {$c} c ON c.id = a.checklist_id
			 WHERE a.user_id = %d ORDER BY a.assigned_at DESC", $user_id
		) );
	}

	/** All assignments of one template (for the admin assignment panel). */
	public static function assignments_for_checklist( int $checklist_id ): array {
		global $wpdb;
		$a = SSLMS_DB::table( 'checklist_assignments' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$a} WHERE checklist_id = %d ORDER BY assigned_at DESC", $checklist_id
		) );
	}

	/**
	 * Open (unlocked) assignments of learners the given signer (preceptor or
	 * evaluator) is scoped to via `relationships`. Used for the "learners to
	 * sign" list. Does not include an sslms_manage bypass — this is the
	 * signer's own worklist, not an admin view.
	 */
	public static function learners_to_sign( int $signer_id ): array {
		global $wpdb;
		$a = SSLMS_DB::table( 'checklist_assignments' );
		$c = SSLMS_DB::table( 'checklists' );
		$r = SSLMS_DB::table( 'relationships' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT a.*, c.title, c.discipline FROM {$a} a
			 JOIN {$c} c ON c.id = a.checklist_id
			 JOIN {$r} rel ON rel.user_id = a.user_id AND rel.related_user_id = %d
			 WHERE rel.rel_type IN ('preceptor','evaluator') AND a.locked = 0
			 ORDER BY a.assigned_at ASC",
			$signer_id
		) );
	}

	/**
	 * Per-item status of an assignment: item id/text plus latest rating
	 * (null if unrated), note, signer, timestamp.
	 */
	public static function items_status( int $assignment_id ): array {
		$assignment = self::get_assignment( $assignment_id );
		if ( ! $assignment ) {
			return array();
		}
		global $wpdb;
		$items = self::get_items( (int) $assignment->checklist_id );
		$so    = SSLMS_DB::table( 'checklist_signoffs' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$so} WHERE assignment_id = %d", $assignment_id ) );
		$by_item = array();
		foreach ( $rows as $row ) {
			$by_item[ (int) $row->item_id ] = $row;
		}
		$out = array();
		foreach ( $items as $item ) {
			$signoff = $by_item[ (int) $item->id ] ?? null;
			$out[] = (object) array(
				'item_id'   => (int) $item->id,
				'item_text' => $item->item_text,
				'rating'    => $signoff ? $signoff->rating : null,
				'note'      => $signoff ? $signoff->note : '',
				'signed_by' => $signoff ? (int) $signoff->signed_by : 0,
				'signed_at' => $signoff ? $signoff->signed_at : null,
			);
		}
		return $out;
	}

	/** Summary counts for an assignment: total items / competent items. */
	public static function assignment_summary( int $assignment_id ): array {
		$items     = self::items_status( $assignment_id );
		$total     = count( $items );
		$competent = 0;
		foreach ( $items as $item ) {
			if ( 'competent' === $item->rating ) {
				$competent++;
			}
		}
		return array( 'total' => $total, 'competent' => $competent );
	}

	/**
	 * Can $signer_id sign checklist items for learner $learner_id?
	 * True if $signer_id has sslms_manage, or a relationships row
	 * (user_id=$learner_id, related_user_id=$signer_id, rel_type in
	 * preceptor|evaluator) exists.
	 */
	public static function signer_in_scope( int $learner_id, int $signer_id ): bool {
		if ( user_can( $signer_id, 'sslms_manage' ) ) {
			return true;
		}
		global $wpdb;
		$r = SSLMS_DB::table( 'relationships' );
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$r} WHERE user_id = %d AND related_user_id = %d AND rel_type IN ('preceptor','evaluator') LIMIT 1",
			$learner_id, $signer_id
		) );
		return (bool) $found;
	}

	/**
	 * Rate one checklist item. Re-rating is allowed until the assignment is
	 * locked. Locks + completes the assignment once every item is rated
	 * 'competent'.
	 *
	 * @return array|WP_Error Updated item status array on success.
	 */
	public static function sign_item( int $assignment_id, int $item_id, string $rating, string $note, int $signer ) {
		$assignment = self::get_assignment( $assignment_id );
		if ( ! $assignment ) {
			return new WP_Error( 'sslms_not_found', 'Assignment not found.' );
		}
		if ( ! empty( $assignment->locked ) ) {
			return new WP_Error( 'sslms_locked', 'This checklist is locked and can no longer be edited.' );
		}
		if ( ! in_array( $rating, self::RATINGS, true ) ) {
			return new WP_Error( 'sslms_invalid_rating', 'Invalid rating.' );
		}
		$item = SSLMS_DB::get_row( 'checklist_items', $item_id );
		if ( ! $item || (int) $item->checklist_id !== (int) $assignment->checklist_id ) {
			return new WP_Error( 'sslms_invalid_item', 'That item does not belong to this checklist.' );
		}
		if ( ! self::signer_in_scope( (int) $assignment->user_id, $signer ) ) {
			return new WP_Error( 'sslms_forbidden', 'You are not the assigned preceptor/evaluator for this learner.', array( 'status' => 403 ) );
		}

		global $wpdb;
		$so = SSLMS_DB::table( 'checklist_signoffs' );
		$now = SSLMS_DB::now();
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$so} WHERE assignment_id = %d AND item_id = %d", $assignment_id, $item_id
		) );
		if ( $existing ) {
			$wpdb->update( $so, array(
				'rating'    => $rating,
				'note'      => $note,
				'signed_by' => $signer,
				'signed_at' => $now,
			), array( 'id' => (int) $existing ) );
		} else {
			$wpdb->insert( $so, array(
				'assignment_id' => $assignment_id,
				'item_id'       => $item_id,
				'rating'        => $rating,
				'note'          => $note,
				'signed_by'     => $signer,
				'signed_at'     => $now,
			) );
		}
		SSLMS_Audit::log( 'checklist_item_signed', 'checklist_assignment', $assignment_id,
			'Item ' . $item_id . ' rated ' . $rating . ' by user ' . $signer );

		$summary = self::assignment_summary( $assignment_id );
		if ( $summary['total'] > 0 && $summary['competent'] === $summary['total'] ) {
			SSLMS_DB::update( 'checklist_assignments', array(
				'completed_at' => $now,
				'locked'       => 1,
			), array( 'id' => $assignment_id ) );
			SSLMS_Audit::log( 'checklist_completed', 'checklist_assignment', $assignment_id, 'All items rated competent' );
		}

		return self::items_status( $assignment_id );
	}

	/** Admin-only unlock of a completed/locked assignment. */
	public static function unlock( int $assignment_id ) {
		if ( ! current_user_can( 'sslms_manage' ) ) {
			return new WP_Error( 'sslms_forbidden', 'Only LMS administrators can unlock a checklist.', array( 'status' => 403 ) );
		}
		$assignment = self::get_assignment( $assignment_id );
		if ( ! $assignment ) {
			return new WP_Error( 'sslms_not_found', 'Assignment not found.' );
		}
		SSLMS_DB::update( 'checklist_assignments', array(
			'locked'       => 0,
			'completed_at' => null,
		), array( 'id' => $assignment_id ) );
		SSLMS_Audit::log( 'checklist_unlocked', 'checklist_assignment', $assignment_id, 'Unlocked by admin' );
		return true;
	}

	/** CSV rows (per-item) for exporting one assignment's sign-off record. */
	public static function export_rows( int $assignment_id ): array {
		$rows = array();
		foreach ( self::items_status( $assignment_id ) as $item ) {
			$signer = $item->signed_by ? get_userdata( $item->signed_by ) : false;
			$rows[] = array(
				'Item'       => $item->item_text,
				'Rating'     => $item->rating ?: 'unrated',
				'Note'       => $item->note,
				'Signed by'  => $signer ? $signer->display_name : '',
				'Signed at'  => $item->signed_at ? SSLMS_DB::fmt_date( $item->signed_at ) : '',
			);
		}
		return $rows;
	}
}
