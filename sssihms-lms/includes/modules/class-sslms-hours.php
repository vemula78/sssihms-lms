<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module F7 — Clinical hours / rotation tracking.
 *
 * Scoping: a preceptor may review hour logs of learner X only if
 * rotations.preceptor_id = <acting user> for that rotation, OR a
 * relationships row exists (user_id=X, related_user_id=<acting user>,
 * rel_type='preceptor'). Users with sslms_manage bypass scoping.
 */
class SSLMS_Hours {

	const MIN_HOURS  = 0.25;
	const MAX_HOURS  = 24;
	const GRACE_DAYS = 7;

	/* ---------------------------------------------------------------
	 * Rotation CRUD
	 * ------------------------------------------------------------- */

	public static function get_rotation( int $id ): ?object {
		return SSLMS_DB::get_row( 'rotations', $id );
	}

	public static function create_rotation( int $user_id, string $department, string $start_date, string $end_date, int $preceptor_id, float $required_hours ) {
		if ( strtotime( $end_date ) < strtotime( $start_date ) ) {
			return new WP_Error( 'sslms_invalid_dates', 'End date cannot be before start date.' );
		}
		if ( $preceptor_id === $user_id ) {
			return new WP_Error( 'sslms_self_preceptor', 'A learner cannot be their own preceptor.' );
		}
		$id = SSLMS_DB::insert( 'rotations', array(
			'user_id'        => $user_id,
			'department'     => $department,
			'start_date'     => $start_date,
			'end_date'       => $end_date,
			'preceptor_id'   => $preceptor_id,
			'required_hours' => $required_hours,
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_db_error', 'Could not create rotation.' );
		}
		SSLMS_Audit::log( 'rotation_created', 'rotation', $id, 'Rotation created for user ' . $user_id . ' in ' . $department );
		return $id;
	}

	public static function update_rotation( int $id, array $args ): bool {
		$data = array();
		foreach ( array( 'department', 'start_date', 'end_date' ) as $f ) {
			if ( array_key_exists( $f, $args ) ) {
				$data[ $f ] = $args[ $f ];
			}
		}
		if ( array_key_exists( 'preceptor_id', $args ) ) {
			$data['preceptor_id'] = (int) $args['preceptor_id'];
		}
		if ( array_key_exists( 'required_hours', $args ) ) {
			$data['required_hours'] = (float) $args['required_hours'];
		}
		if ( ! $data ) {
			return false;
		}
		$rotation = self::get_rotation( $id );
		if ( $rotation ) {
			$new_preceptor = array_key_exists( 'preceptor_id', $data ) ? (int) $data['preceptor_id'] : (int) $rotation->preceptor_id;
			if ( $new_preceptor === (int) $rotation->user_id ) {
				return false; // A learner cannot be their own preceptor.
			}
		}
		$ok = SSLMS_DB::update( 'rotations', $data, array( 'id' => $id ) );
		if ( $ok ) {
			SSLMS_Audit::log( 'rotation_updated', 'rotation', $id, 'Fields: ' . implode( ',', array_keys( $data ) ) );
		}
		return $ok;
	}

	public static function delete_rotation( int $id ) {
		global $wpdb;
		$hl   = SSLMS_DB::table( 'hour_logs' );
		$logs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$hl} WHERE rotation_id = %d", $id ) );
		if ( $logs > 0 ) {
			return new WP_Error( 'sslms_rotation_has_logs', 'This rotation has hour logs and cannot be deleted (NABH retention).' );
		}
		$ok = SSLMS_DB::delete( 'rotations', array( 'id' => $id ) );
		if ( $ok ) {
			SSLMS_Audit::log( 'rotation_deleted', 'rotation', $id, 'Rotation deleted (no logs)' );
		}
		return $ok;
	}

	public static function list_rotations( array $args = array() ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'rotations' );
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$vals[]  = (int) $args['user_id'];
		}
		if ( ! empty( $args['preceptor_id'] ) ) {
			$where[] = 'preceptor_id = %d';
			$vals[]  = (int) $args['preceptor_id'];
		}
		$sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY start_date DESC';
		if ( $vals ) {
			$sql = $wpdb->prepare( $sql, $vals );
		}
		return $wpdb->get_results( $sql );
	}

	/** Rotations for one learner, each carrying its totals(). Used by the portal. */
	public static function rotations_for_user( int $user_id ): array {
		$rotations = self::list_rotations( array( 'user_id' => $user_id ) );
		foreach ( $rotations as $r ) {
			$r->totals = self::totals( (int) $r->id );
		}
		return $rotations;
	}

	/* ---------------------------------------------------------------
	 * Hour logs
	 * ------------------------------------------------------------- */

	public static function get_log( int $id ): ?object {
		return SSLMS_DB::get_row( 'hour_logs', $id );
	}

	public static function logs_for_rotation( int $rotation_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'hour_logs' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE rotation_id = %d ORDER BY log_date DESC, id DESC", $rotation_id
		) );
	}

	public static function logs_for_user( int $user_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'hour_logs' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE user_id = %d ORDER BY log_date DESC, id DESC", $user_id
		) );
	}

	/**
	 * Learner logs an hour entry against their own rotation. Date must fall
	 * within the rotation's start/end dates, allowing a ±7 day grace window;
	 * hours must be between 0.25 and 24.
	 */
	public static function log_hours( int $rotation_id, int $user, string $date, float $hours, string $activity ) {
		$rotation = self::get_rotation( $rotation_id );
		if ( ! $rotation ) {
			return new WP_Error( 'sslms_not_found', 'Rotation not found.' );
		}
		if ( (int) $rotation->user_id !== $user ) {
			return new WP_Error( 'sslms_forbidden', 'You may only log hours against your own rotation.', array( 'status' => 403 ) );
		}
		$ts = strtotime( $date );
		if ( ! $ts ) {
			return new WP_Error( 'sslms_invalid_date', 'Invalid date.' );
		}
		$grace  = self::GRACE_DAYS * DAY_IN_SECONDS;
		$lo     = strtotime( $rotation->start_date ) - $grace;
		$hi     = strtotime( $rotation->end_date ) + $grace;
		if ( $ts < $lo || $ts > $hi ) {
			return new WP_Error( 'sslms_out_of_range', 'Date is outside the rotation period (with 7-day grace).' );
		}
		if ( $hours < self::MIN_HOURS || $hours > self::MAX_HOURS ) {
			return new WP_Error( 'sslms_invalid_hours', 'Hours must be between 0.25 and 24.' );
		}
		$id = SSLMS_DB::insert( 'hour_logs', array(
			'rotation_id' => $rotation_id,
			'user_id'     => $user,
			'log_date'    => gmdate( 'Y-m-d', $ts ),
			'hours'       => $hours,
			'activity'    => $activity,
			'status'      => 'pending',
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_db_error', 'Could not log hours.' );
		}
		SSLMS_Audit::log( 'hours_logged', 'hour_log', $id, $hours . 'h on ' . gmdate( 'Y-m-d', $ts ) . ' (rotation ' . $rotation_id . ')' );
		return $id;
	}

	/** Learner may edit their own pending (not yet reviewed) log only. */
	public static function edit_log( int $log_id, int $user, array $args ) {
		$log = self::get_log( $log_id );
		if ( ! $log ) {
			return new WP_Error( 'sslms_not_found', 'Log not found.' );
		}
		if ( (int) $log->user_id !== $user ) {
			return new WP_Error( 'sslms_forbidden', 'Not your log entry.', array( 'status' => 403 ) );
		}
		if ( 'pending' !== $log->status ) {
			return new WP_Error( 'sslms_locked', 'Only pending entries can be edited.' );
		}
		$data = array();
		if ( isset( $args['date'] ) ) {
			$rotation = self::get_rotation( (int) $log->rotation_id );
			$ts = strtotime( $args['date'] );
			if ( ! $ts ) {
				return new WP_Error( 'sslms_invalid_date', 'Invalid date.' );
			}
			$grace = self::GRACE_DAYS * DAY_IN_SECONDS;
			if ( $rotation && ( $ts < strtotime( $rotation->start_date ) - $grace || $ts > strtotime( $rotation->end_date ) + $grace ) ) {
				return new WP_Error( 'sslms_out_of_range', 'Date is outside the rotation period (with 7-day grace).' );
			}
			$data['log_date'] = gmdate( 'Y-m-d', $ts );
		}
		if ( isset( $args['hours'] ) ) {
			$hours = (float) $args['hours'];
			if ( $hours < self::MIN_HOURS || $hours > self::MAX_HOURS ) {
				return new WP_Error( 'sslms_invalid_hours', 'Hours must be between 0.25 and 24.' );
			}
			$data['hours'] = $hours;
		}
		if ( isset( $args['activity'] ) ) {
			$data['activity'] = $args['activity'];
		}
		if ( ! $data ) {
			return false;
		}
		return SSLMS_DB::update( 'hour_logs', $data, array( 'id' => $log_id ) );
	}

	/** Learner may delete their own pending log only. */
	public static function delete_log( int $log_id, int $user ) {
		$log = self::get_log( $log_id );
		if ( ! $log ) {
			return new WP_Error( 'sslms_not_found', 'Log not found.' );
		}
		if ( (int) $log->user_id !== $user ) {
			return new WP_Error( 'sslms_forbidden', 'Not your log entry.', array( 'status' => 403 ) );
		}
		if ( 'pending' !== $log->status ) {
			return new WP_Error( 'sslms_locked', 'Only pending entries can be deleted.' );
		}
		return SSLMS_DB::delete( 'hour_logs', array( 'id' => $log_id ) );
	}

	/**
	 * Can $reviewer_id review hour logs for the given rotation? True if
	 * rotation.preceptor_id === $reviewer_id, or a relationships row
	 * (user_id=rotation.user_id, related_user_id=$reviewer_id,
	 * rel_type='preceptor') exists, or reviewer has sslms_manage.
	 */
	public static function reviewer_in_scope( object $rotation, int $reviewer_id ): bool {
		if ( (int) $rotation->preceptor_id === $reviewer_id ) {
			return true;
		}
		if ( user_can( $reviewer_id, 'sslms_manage' ) ) {
			return true;
		}
		global $wpdb;
		$r = SSLMS_DB::table( 'relationships' );
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$r} WHERE user_id = %d AND related_user_id = %d AND rel_type = 'preceptor' LIMIT 1",
			$rotation->user_id, $reviewer_id
		) );
		return (bool) $found;
	}

	/** Approve or reject a pending hour log. */
	public static function review( int $log_id, int $reviewer, string $status, string $note = '' ) {
		if ( ! in_array( $status, array( 'approve', 'reject' ), true ) ) {
			return new WP_Error( 'sslms_invalid_status', 'Status must be approve or reject.' );
		}
		$log = self::get_log( $log_id );
		if ( ! $log ) {
			return new WP_Error( 'sslms_not_found', 'Log not found.' );
		}
		$rotation = self::get_rotation( (int) $log->rotation_id );
		if ( ! $rotation ) {
			return new WP_Error( 'sslms_not_found', 'Rotation not found.' );
		}
		if ( ! self::reviewer_in_scope( $rotation, $reviewer ) ) {
			return new WP_Error( 'sslms_forbidden', 'You are not the preceptor for this learner/rotation.', array( 'status' => 403 ) );
		}
		$new_status = 'approve' === $status ? 'approved' : 'rejected';
		$ok = SSLMS_DB::update( 'hour_logs', array(
			'status'      => $new_status,
			'reviewed_by' => $reviewer,
			'reviewed_at' => SSLMS_DB::now(),
			'review_note' => $note,
		), array( 'id' => $log_id ) );
		if ( $ok ) {
			SSLMS_Audit::log(
				'approve' === $status ? 'hours_approved' : 'hours_rejected',
				'hour_log',
				$log_id,
				$log->hours . 'h on ' . $log->log_date . ' by ' . $reviewer
			);
		}
		return $ok;
	}

	/** Pending logs awaiting review by $reviewer_id, across their scoped rotations. */
	public static function pending_approvals( int $reviewer_id ): array {
		global $wpdb;
		$hl = SSLMS_DB::table( 'hour_logs' );
		$ro = SSLMS_DB::table( 'rotations' );
		$r  = SSLMS_DB::table( 'relationships' );
		$sql = "SELECT DISTINCT hl.*, ro.department, ro.user_id AS learner_id FROM {$hl} hl
			JOIN {$ro} ro ON ro.id = hl.rotation_id
			LEFT JOIN {$r} rel ON rel.user_id = ro.user_id AND rel.related_user_id = %d AND rel.rel_type = 'preceptor'
			WHERE hl.status = 'pending' AND ( ro.preceptor_id = %d OR rel.id IS NOT NULL )
			ORDER BY hl.log_date ASC";
		return $wpdb->get_results( $wpdb->prepare( $sql, $reviewer_id, $reviewer_id ) );
	}

	/** approved / pending / required hour totals for a rotation. */
	public static function totals( int $rotation_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'hour_logs' );
		$rotation = self::get_rotation( $rotation_id );
		$approved = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(hours),0) FROM {$t} WHERE rotation_id = %d AND status = 'approved'", $rotation_id
		) );
		$pending = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(hours),0) FROM {$t} WHERE rotation_id = %d AND status = 'pending'", $rotation_id
		) );
		return array(
			'approved' => $approved,
			'pending'  => $pending,
			'required' => $rotation ? (float) $rotation->required_hours : 0.0,
		);
	}

	/** CSV rows: one learner's logs. */
	public static function export_rows_for_user( int $user_id ): array {
		$rows = array();
		foreach ( self::logs_for_user( $user_id ) as $log ) {
			$rotation = self::get_rotation( (int) $log->rotation_id );
			$rows[] = array(
				'Department' => $rotation ? $rotation->department : '',
				'Date'       => SSLMS_DB::fmt_date( $log->log_date ),
				'Hours'      => $log->hours,
				'Activity'   => $log->activity,
				'Status'     => $log->status,
				'Reviewed'   => $log->reviewed_at ? SSLMS_DB::fmt_date( $log->reviewed_at ) : '',
				'Note'       => $log->review_note,
			);
		}
		return $rows;
	}

	/** CSV rows: all logs of one rotation. */
	public static function export_rows_for_rotation( int $rotation_id ): array {
		$rows = array();
		foreach ( self::logs_for_rotation( $rotation_id ) as $log ) {
			$user = get_userdata( $log->user_id );
			$rows[] = array(
				'Learner'  => $user ? $user->display_name : $log->user_id,
				'Date'     => SSLMS_DB::fmt_date( $log->log_date ),
				'Hours'    => $log->hours,
				'Activity' => $log->activity,
				'Status'   => $log->status,
				'Reviewed' => $log->reviewed_at ? SSLMS_DB::fmt_date( $log->reviewed_at ) : '',
				'Note'     => $log->review_note,
			);
		}
		return $rows;
	}
}
