<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 3c — OSPE (station-based practical exams).
 *
 * An exam is an ordered set of stations (procedure / question / rest), each
 * with duration, max marks, an assigned examiner and optionally an attached
 * 3a rubric as its scoresheet reference. Candidates come from the learner
 * pool; examiners score only their own station (sslms_manage bypasses).
 *
 * Lifecycle: draft → scheduled → published. Scores are entered and may be
 * corrected by the station examiner while the exam is unpublished (every
 * save audit-logged); moderation adjustments by sslms_manage carry a note.
 * Publishing computes per-candidate results (overall % + minimum-per-station
 * rule), locks everything permanently (NABH — no unpublish), and makes
 * results visible to candidates.
 */
class SSLMS_OSPE {

	const STATION_TYPES = array( 'procedure', 'question', 'rest' );
	const STATUSES      = array( 'draft', 'scheduled', 'published' );

	/* ---------------------------------------------------------------
	 * Exam CRUD
	 * ------------------------------------------------------------- */

	public static function get_exam( int $id ): ?object {
		return SSLMS_DB::get_row( 'ospe_exams', $id );
	}

	public static function list_exams(): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'ospe_exams' );
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY exam_date DESC, id DESC" );
	}

	public static function create_exam( array $args, int $by ) {
		$id = SSLMS_DB::insert( 'ospe_exams', array(
			'title'           => $args['title'],
			'discipline'      => $args['discipline'] ?? '',
			'exam_date'       => $args['exam_date'] ?? null,
			'status'          => 'draft',
			'pass_pct'        => isset( $args['pass_pct'] ) ? (float) $args['pass_pct'] : 50,
			'min_station_pct' => isset( $args['min_station_pct'] ) ? (float) $args['min_station_pct'] : 0,
			'coordinator_id'  => $by,
			'created_at'      => SSLMS_DB::now(),
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_db_error', 'Could not create the exam.' );
		}
		SSLMS_Audit::log( 'ospe_created', 'ospe_exam', $id, 'OSPE "' . $args['title'] . '" created' );
		return $id;
	}

	public static function update_exam( int $id, array $args ) {
		$exam = self::get_exam( $id );
		if ( ! $exam ) {
			return new WP_Error( 'sslms_not_found', 'Exam not found.' );
		}
		if ( 'published' === $exam->status ) {
			return new WP_Error( 'sslms_locked', 'A published OSPE is a permanent record and cannot be edited.' );
		}
		$data = array();
		foreach ( array( 'title', 'discipline' ) as $f ) {
			if ( array_key_exists( $f, $args ) ) {
				$data[ $f ] = $args[ $f ];
			}
		}
		if ( array_key_exists( 'exam_date', $args ) ) {
			$data['exam_date'] = $args['exam_date'] ?: null;
		}
		foreach ( array( 'pass_pct', 'min_station_pct' ) as $f ) {
			if ( array_key_exists( $f, $args ) ) {
				$data[ $f ] = (float) $args[ $f ];
			}
		}
		if ( array_key_exists( 'status', $args ) && in_array( $args['status'], array( 'draft', 'scheduled' ), true ) ) {
			$data['status'] = $args['status']; // 'published' only via publish().
		}
		if ( ! $data ) {
			return false;
		}
		$ok = SSLMS_DB::update( 'ospe_exams', $data, array( 'id' => $id ) );
		if ( $ok ) {
			SSLMS_Audit::log( 'ospe_updated', 'ospe_exam', $id, 'Fields: ' . implode( ',', array_keys( $data ) ) );
		}
		return $ok;
	}

	/** Refuses once any score exists (NABH). */
	public static function delete_exam( int $id ) {
		$exam = self::get_exam( $id );
		if ( ! $exam ) {
			return new WP_Error( 'sslms_not_found', 'Exam not found.' );
		}
		if ( 'published' === $exam->status || self::score_count( $id ) > 0 ) {
			return new WP_Error( 'sslms_in_use', 'This OSPE has recorded scores and cannot be deleted (NABH retention).' );
		}
		global $wpdb;
		$wpdb->delete( SSLMS_DB::table( 'ospe_stations' ), array( 'exam_id' => $id ) );
		$wpdb->delete( SSLMS_DB::table( 'ospe_candidates' ), array( 'exam_id' => $id ) );
		$ok = SSLMS_DB::delete( 'ospe_exams', array( 'id' => $id ) );
		if ( $ok ) {
			SSLMS_Audit::log( 'ospe_deleted', 'ospe_exam', $id, 'Unused OSPE deleted' );
		}
		return $ok;
	}

	private static function score_count( int $exam_id ): int {
		global $wpdb;
		$sc = SSLMS_DB::table( 'ospe_scores' );
		$st = SSLMS_DB::table( 'ospe_stations' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$sc} s JOIN {$st} t ON t.id = s.station_id WHERE t.exam_id = %d", $exam_id
		) );
	}

	/* ---------------------------------------------------------------
	 * Stations
	 * ------------------------------------------------------------- */

	public static function get_station( int $id ): ?object {
		return SSLMS_DB::get_row( 'ospe_stations', $id );
	}

	public static function get_stations( int $exam_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'ospe_stations' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE exam_id = %d ORDER BY station_no ASC, id ASC", $exam_id
		) );
	}

	public static function add_station( int $exam_id, array $args ) {
		$exam = self::get_exam( $exam_id );
		if ( ! $exam ) {
			return new WP_Error( 'sslms_not_found', 'Exam not found.' );
		}
		if ( 'published' === $exam->status ) {
			return new WP_Error( 'sslms_locked', 'A published OSPE cannot be edited.' );
		}
		$type = $args['station_type'] ?? 'procedure';
		if ( ! in_array( $type, self::STATION_TYPES, true ) ) {
			return new WP_Error( 'sslms_invalid_type', 'Invalid station type.' );
		}
		if ( ! empty( $args['rubric_id'] ) && ! SSLMS_DB::get_row( 'rubrics', (int) $args['rubric_id'] ) ) {
			return new WP_Error( 'sslms_not_found', 'Attached rubric not found.' );
		}
		global $wpdb;
		$t   = SSLMS_DB::table( 'ospe_stations' );
		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(station_no) FROM {$t} WHERE exam_id = %d", $exam_id ) );
		$id  = SSLMS_DB::insert( 'ospe_stations', array(
			'exam_id'      => $exam_id,
			'station_no'   => $max + 1,
			'title'        => $args['title'],
			'station_type' => $type,
			'duration_min' => max( 1, (int) ( $args['duration_min'] ?? 5 ) ),
			'max_marks'    => 'rest' === $type ? 0 : max( 0, (float) ( $args['max_marks'] ?? 10 ) ),
			'rubric_id'    => (int) ( $args['rubric_id'] ?? 0 ),
			'examiner_id'  => (int) ( $args['examiner_id'] ?? 0 ),
		) );
		SSLMS_Audit::log( 'ospe_station_added', 'ospe_exam', $exam_id, 'Station "' . $args['title'] . '" added' );
		return $id;
	}

	public static function update_station( int $station_id, array $args ) {
		$station = self::get_station( $station_id );
		if ( ! $station ) {
			return new WP_Error( 'sslms_not_found', 'Station not found.' );
		}
		$exam = self::get_exam( (int) $station->exam_id );
		if ( $exam && 'published' === $exam->status ) {
			return new WP_Error( 'sslms_locked', 'A published OSPE cannot be edited.' );
		}
		$data = array();
		if ( array_key_exists( 'title', $args ) ) {
			$data['title'] = $args['title'];
		}
		if ( array_key_exists( 'station_type', $args ) && in_array( $args['station_type'], self::STATION_TYPES, true ) ) {
			$data['station_type'] = $args['station_type'];
		}
		foreach ( array( 'duration_min', 'station_no', 'examiner_id', 'rubric_id' ) as $f ) {
			if ( array_key_exists( $f, $args ) ) {
				$data[ $f ] = (int) $args[ $f ];
			}
		}
		if ( array_key_exists( 'max_marks', $args ) ) {
			$data['max_marks'] = max( 0, (float) $args['max_marks'] );
		}
		if ( ! $data ) {
			return false;
		}
		return SSLMS_DB::update( 'ospe_stations', $data, array( 'id' => $station_id ) );
	}

	public static function delete_station( int $station_id ) {
		global $wpdb;
		$sc = SSLMS_DB::table( 'ospe_scores' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sc} WHERE station_id = %d", $station_id ) ) ) {
			return new WP_Error( 'sslms_in_use', 'This station has recorded scores and cannot be deleted (NABH retention).' );
		}
		return SSLMS_DB::delete( 'ospe_stations', array( 'id' => $station_id ) );
	}

	/* ---------------------------------------------------------------
	 * Candidates
	 * ------------------------------------------------------------- */

	public static function get_candidate( int $id ): ?object {
		return SSLMS_DB::get_row( 'ospe_candidates', $id );
	}

	public static function get_candidates( int $exam_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'ospe_candidates' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE exam_id = %d ORDER BY candidate_no ASC, id ASC", $exam_id
		) );
	}

	public static function add_candidate( int $exam_id, int $user_id ) {
		$exam = self::get_exam( $exam_id );
		if ( ! $exam ) {
			return new WP_Error( 'sslms_not_found', 'Exam not found.' );
		}
		if ( 'published' === $exam->status ) {
			return new WP_Error( 'sslms_locked', 'A published OSPE cannot be edited.' );
		}
		if ( ! user_can( $user_id, 'sslms_learn' ) ) {
			return new WP_Error( 'sslms_invalid_user', 'That user is not a learner.' );
		}
		global $wpdb;
		$t = SSLMS_DB::table( 'ospe_candidates' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE exam_id = %d AND user_id = %d", $exam_id, $user_id ) ) ) {
			return new WP_Error( 'sslms_duplicate', 'Already a candidate in this exam.' );
		}
		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(candidate_no) FROM {$t} WHERE exam_id = %d", $exam_id ) );
		$id  = SSLMS_DB::insert( 'ospe_candidates', array(
			'exam_id'      => $exam_id,
			'user_id'      => $user_id,
			'candidate_no' => $max + 1,
			'status'       => 'registered',
		) );
		SSLMS_Audit::log( 'ospe_candidate_added', 'ospe_exam', $exam_id, 'Candidate user ' . $user_id . ' registered' );
		return $id;
	}

	public static function remove_candidate( int $candidate_id ) {
		$cand = self::get_candidate( $candidate_id );
		if ( ! $cand ) {
			return new WP_Error( 'sslms_not_found', 'Candidate not found.' );
		}
		global $wpdb;
		$sc = SSLMS_DB::table( 'ospe_scores' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sc} WHERE candidate_id = %d", $candidate_id ) ) ) {
			return new WP_Error( 'sslms_in_use', 'This candidate has recorded scores and cannot be removed (NABH retention).' );
		}
		return SSLMS_DB::delete( 'ospe_candidates', array( 'id' => $candidate_id ) );
	}

	/* ---------------------------------------------------------------
	 * Scoring (exam-day, per station)
	 * ------------------------------------------------------------- */

	/** May $user_id score this station? Assigned examiner or sslms_manage. */
	public static function can_score_station( object $station, int $user_id ): bool {
		return (int) $station->examiner_id === $user_id || user_can( $user_id, 'sslms_manage' );
	}

	/** Stations assigned to an examiner in unpublished exams (their worklist). */
	public static function stations_for_examiner( int $examiner_id ): array {
		global $wpdb;
		$st = SSLMS_DB::table( 'ospe_stations' );
		$ex = SSLMS_DB::table( 'ospe_exams' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT s.*, e.title AS exam_title, e.exam_date, e.status AS exam_status FROM {$st} s
			 JOIN {$ex} e ON e.id = s.exam_id
			 WHERE s.examiner_id = %d AND e.status != 'published'
			 ORDER BY e.exam_date ASC, s.station_no ASC", $examiner_id
		) );
	}

	/**
	 * Record (or correct) one candidate's marks at one station.
	 * Allowed while the exam is unpublished; moderation flag forces a note
	 * and is reserved for sslms_manage.
	 */
	public static function score( int $station_id, int $candidate_id, float $marks, string $note, int $actor, bool $moderation = false ) {
		$station = self::get_station( $station_id );
		if ( ! $station ) {
			return new WP_Error( 'sslms_not_found', 'Station not found.' );
		}
		if ( 'rest' === $station->station_type ) {
			return new WP_Error( 'sslms_invalid_type', 'Rest stations are not scored.' );
		}
		$exam = self::get_exam( (int) $station->exam_id );
		if ( ! $exam || 'published' === $exam->status ) {
			return new WP_Error( 'sslms_locked', 'This OSPE is published; scores are final.' );
		}
		$candidate = self::get_candidate( $candidate_id );
		if ( ! $candidate || (int) $candidate->exam_id !== (int) $station->exam_id ) {
			return new WP_Error( 'sslms_invalid_item', 'That candidate is not in this exam.' );
		}
		if ( $moderation ) {
			if ( ! user_can( $actor, 'sslms_manage' ) ) {
				return new WP_Error( 'sslms_forbidden', 'Only the exam coordinator/administrator may moderate.', array( 'status' => 403 ) );
			}
			if ( '' === trim( $note ) ) {
				return new WP_Error( 'sslms_note_required', 'A moderation note is required.' );
			}
		} elseif ( ! self::can_score_station( $station, $actor ) ) {
			return new WP_Error( 'sslms_forbidden', 'You are not the examiner for this station.', array( 'status' => 403 ) );
		}
		if ( $marks < 0 || $marks > (float) $station->max_marks ) {
			return new WP_Error( 'sslms_invalid_marks', 'Marks must be between 0 and ' . $station->max_marks . '.' );
		}

		global $wpdb;
		$sc  = SSLMS_DB::table( 'ospe_scores' );
		$now = SSLMS_DB::now();
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$sc} WHERE station_id = %d AND candidate_id = %d", $station_id, $candidate_id
		) );
		$data = array( 'marks' => $marks, 'note' => $note, 'signed_by' => $actor, 'signed_at' => $now );
		if ( $existing ) {
			$wpdb->update( $sc, $data, array( 'id' => (int) $existing ) );
		} else {
			$wpdb->insert( $sc, $data + array( 'station_id' => $station_id, 'candidate_id' => $candidate_id ) );
		}
		SSLMS_Audit::log(
			$moderation ? 'ospe_score_moderated' : 'ospe_scored',
			'ospe_exam', (int) $station->exam_id,
			'Station ' . $station_id . ' candidate ' . $candidate_id . ': ' . $marks . '/' . $station->max_marks . ' by user ' . $actor
			. ( $moderation ? ' (moderation)' : '' )
		);
		return true;
	}

	/** All scores of one exam, keyed [station_id][candidate_id]. */
	public static function scores_matrix( int $exam_id ): array {
		global $wpdb;
		$sc   = SSLMS_DB::table( 'ospe_scores' );
		$st   = SSLMS_DB::table( 'ospe_stations' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.* FROM {$sc} s JOIN {$st} t ON t.id = s.station_id WHERE t.exam_id = %d", $exam_id
		) );
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->station_id ][ (int) $row->candidate_id ] = $row;
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * Results, publication, export
	 * ------------------------------------------------------------- */

	/**
	 * Compute one candidate's result: overall % across scoreable stations
	 * plus the minimum-per-station rule. Null if any station unscored.
	 */
	public static function compute_result( int $exam_id, int $candidate_id ): ?array {
		$exam     = self::get_exam( $exam_id );
		$stations = self::get_stations( $exam_id );
		$matrix   = self::scores_matrix( $exam_id );
		$earned   = 0.0;
		$max      = 0.0;
		$station_ok = true;
		foreach ( $stations as $station ) {
			if ( 'rest' === $station->station_type || (float) $station->max_marks <= 0 ) {
				continue;
			}
			$score = $matrix[ (int) $station->id ][ $candidate_id ] ?? null;
			if ( ! $score ) {
				return null;
			}
			$earned += (float) $score->marks;
			$max    += (float) $station->max_marks;
			$pct     = 100 * (float) $score->marks / (float) $station->max_marks;
			if ( (float) $exam->min_station_pct > 0 && $pct < (float) $exam->min_station_pct ) {
				$station_ok = false;
			}
		}
		if ( $max <= 0 ) {
			return null;
		}
		$total_pct = round( 100 * $earned / $max, 2 );
		$passed    = $station_ok && $total_pct >= (float) $exam->pass_pct;
		return array( 'total_pct' => $total_pct, 'outcome' => $passed ? 'pass' : 'fail' );
	}

	/**
	 * Publish: every registered candidate must be fully scored (or marked
	 * absent). Stores results, locks the exam permanently, notifies 3b via
	 * the sslms_ospe_published action.
	 */
	public static function publish( int $exam_id, int $actor ) {
		if ( ! user_can( $actor, 'sslms_manage' ) ) {
			return new WP_Error( 'sslms_forbidden', 'Only the exam coordinator/administrator may publish results.', array( 'status' => 403 ) );
		}
		$exam = self::get_exam( $exam_id );
		if ( ! $exam ) {
			return new WP_Error( 'sslms_not_found', 'Exam not found.' );
		}
		if ( 'published' === $exam->status ) {
			return new WP_Error( 'sslms_locked', 'Already published.' );
		}
		$candidates = self::get_candidates( $exam_id );
		if ( ! $candidates ) {
			return new WP_Error( 'sslms_empty', 'No candidates registered.' );
		}
		$pending = array();
		foreach ( $candidates as $cand ) {
			if ( 'absent' === $cand->status ) {
				continue;
			}
			if ( null === self::compute_result( $exam_id, (int) $cand->id ) ) {
				$pending[] = $cand->candidate_no;
			}
		}
		if ( $pending ) {
			return new WP_Error( 'sslms_incomplete', 'Candidates not fully scored: #' . implode( ', #', $pending ) . '. Score them or mark them absent.' );
		}
		foreach ( $candidates as $cand ) {
			if ( 'absent' === $cand->status ) {
				continue;
			}
			$result = self::compute_result( $exam_id, (int) $cand->id );
			SSLMS_DB::update( 'ospe_candidates', array(
				'status'    => 'completed',
				'total_pct' => $result['total_pct'],
				'outcome'   => $result['outcome'],
			), array( 'id' => (int) $cand->id ) );
		}
		SSLMS_DB::update( 'ospe_exams', array( 'status' => 'published' ), array( 'id' => $exam_id ) );
		SSLMS_Audit::log( 'ospe_published', 'ospe_exam', $exam_id, 'Results published by user ' . $actor );

		/** Cross-module hook: competency passport (3b) evidence, certificates. */
		do_action( 'sslms_ospe_published', $exam_id );
		return true;
	}

	public static function mark_absent( int $candidate_id, int $actor ) {
		if ( ! user_can( $actor, 'sslms_manage' ) ) {
			return new WP_Error( 'sslms_forbidden', 'Not permitted.', array( 'status' => 403 ) );
		}
		$cand = self::get_candidate( $candidate_id );
		if ( ! $cand ) {
			return new WP_Error( 'sslms_not_found', 'Candidate not found.' );
		}
		$exam = self::get_exam( (int) $cand->exam_id );
		if ( $exam && 'published' === $exam->status ) {
			return new WP_Error( 'sslms_locked', 'This OSPE is published.' );
		}
		SSLMS_DB::update( 'ospe_candidates', array( 'status' => 'absent' ), array( 'id' => $candidate_id ) );
		SSLMS_Audit::log( 'ospe_candidate_absent', 'ospe_exam', (int) $cand->exam_id, 'Candidate ' . $candidate_id . ' marked absent' );
		return true;
	}

	/** Published results for one learner (their portal view). */
	public static function results_for_user( int $user_id ): array {
		global $wpdb;
		$c = SSLMS_DB::table( 'ospe_candidates' );
		$e = SSLMS_DB::table( 'ospe_exams' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT c.*, e.title AS exam_title, e.exam_date, e.pass_pct FROM {$c} c
			 JOIN {$e} e ON e.id = c.exam_id
			 WHERE c.user_id = %d AND e.status = 'published'
			 ORDER BY e.exam_date DESC", $user_id
		) );
	}

	/** Full mark-sheet rows for CSV export of one exam. */
	public static function export_rows( int $exam_id ): array {
		$stations   = self::get_stations( $exam_id );
		$candidates = self::get_candidates( $exam_id );
		$matrix     = self::scores_matrix( $exam_id );
		$rows       = array();
		foreach ( $candidates as $cand ) {
			$user = get_userdata( (int) $cand->user_id );
			$row  = array(
				'Candidate #' => $cand->candidate_no,
				'Name'        => $user ? $user->display_name : ( 'User ' . $cand->user_id ),
				'Status'      => $cand->status,
			);
			foreach ( $stations as $station ) {
				if ( 'rest' === $station->station_type ) {
					continue;
				}
				$score = $matrix[ (int) $station->id ][ (int) $cand->id ] ?? null;
				$row[ 'S' . $station->station_no . ' ' . $station->title . ' (/' . (float) $station->max_marks . ')' ] = $score ? (float) $score->marks : '';
			}
			$row['Total %'] = null === $cand->total_pct ? '' : (float) $cand->total_pct;
			$row['Outcome'] = $cand->outcome;
			$rows[]         = $row;
		}
		return $rows;
	}
}
