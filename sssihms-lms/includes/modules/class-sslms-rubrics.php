<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 3a — Rubrics engine + multi-source assessment records.
 *
 * A rubric is N weighted criteria, each with M performance levels (label,
 * descriptor, marks). Criteria may be flagged critical: choosing the
 * bottom level of a critical criterion fails the whole assessment
 * regardless of the weighted total ("unsafe practice = fail").
 *
 * Assessment records are multi-source: the same rubric may be completed by
 * the learner (self), a peer, a preceptor/evaluator, a mentor, or an LMS
 * administrator (educator), with the source recorded. Scoping mirrors the
 * checklist pattern — non-self assessors need an explicit relationships
 * row to the learner; sslms_manage bypasses.
 *
 * Signed records are immutable (NABH): no score edit, no delete, ever.
 * Draft records are editable/deletable only by the assessor who opened them.
 */
class SSLMS_Rubrics {

	const FORM_TYPES = array( 'custom', 'mini_cex', 'dops', 'professionalism', 'communication', 'ward_feedback' );

	/** relationships.rel_type values that may assess, mapped to the recorded source role. */
	const REL_ROLES = array(
		'preceptor' => 'preceptor',
		'evaluator' => 'evaluator',
		'mentor'    => 'mentor',
		'peer'      => 'peer',
	);

	/* ---------------------------------------------------------------
	 * Rubric template CRUD
	 * ------------------------------------------------------------- */

	public static function get_rubric( int $id ): ?object {
		return SSLMS_DB::get_row( 'rubrics', $id );
	}

	public static function list_rubrics( bool $active_only = false ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'rubrics' );
		if ( $active_only ) {
			return $wpdb->get_results( "SELECT * FROM {$t} WHERE is_active = 1 ORDER BY title ASC" );
		}
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY title ASC" );
	}

	public static function create_rubric( array $args, int $by ) {
		$form_type = $args['form_type'] ?? 'custom';
		if ( ! in_array( $form_type, self::FORM_TYPES, true ) ) {
			return new WP_Error( 'sslms_invalid_form_type', 'Invalid form type.' );
		}
		$id = SSLMS_DB::insert( 'rubrics', array(
			'title'       => $args['title'],
			'form_type'   => $form_type,
			'discipline'  => $args['discipline'] ?? '',
			'description' => $args['description'] ?? '',
			'pass_pct'    => isset( $args['pass_pct'] ) ? (float) $args['pass_pct'] : 0,
			'is_active'   => 1,
			'created_by'  => $by,
			'created_at'  => SSLMS_DB::now(),
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_db_error', 'Could not create rubric.' );
		}
		SSLMS_Audit::log( 'rubric_created', 'rubric', $id, 'Rubric "' . $args['title'] . '" created' );
		return $id;
	}

	public static function update_rubric( int $id, array $args ): bool {
		$data = array();
		foreach ( array( 'title', 'discipline', 'description' ) as $f ) {
			if ( array_key_exists( $f, $args ) ) {
				$data[ $f ] = $args[ $f ];
			}
		}
		if ( array_key_exists( 'form_type', $args ) && in_array( $args['form_type'], self::FORM_TYPES, true ) ) {
			$data['form_type'] = $args['form_type'];
		}
		if ( array_key_exists( 'pass_pct', $args ) ) {
			$data['pass_pct'] = (float) $args['pass_pct'];
		}
		if ( array_key_exists( 'is_active', $args ) ) {
			$data['is_active'] = (int) (bool) $args['is_active'];
		}
		if ( ! $data ) {
			return false;
		}
		$ok = SSLMS_DB::update( 'rubrics', $data, array( 'id' => $id ) );
		if ( $ok ) {
			SSLMS_Audit::log( 'rubric_updated', 'rubric', $id, 'Fields: ' . implode( ',', array_keys( $data ) ) );
		}
		return $ok;
	}

	/** Refuses once assessment records exist (NABH retention); deactivate instead. */
	public static function delete_rubric( int $id ) {
		global $wpdb;
		$ar    = SSLMS_DB::table( 'assessment_records' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ar} WHERE rubric_id = %d", $id ) );
		if ( $count > 0 ) {
			return new WP_Error( 'sslms_in_use', 'This rubric has assessment records and cannot be deleted; deactivate it instead.' );
		}
		$rc  = SSLMS_DB::table( 'rubric_criteria' );
		$rl  = SSLMS_DB::table( 'rubric_levels' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$rc} WHERE rubric_id = %d", $id ) );
		if ( $ids ) {
			$in = implode( ',', array_map( 'absint', $ids ) );
			$wpdb->query( "DELETE FROM {$rl} WHERE criterion_id IN ({$in})" );
		}
		$wpdb->delete( $rc, array( 'rubric_id' => $id ) );
		$ok = SSLMS_DB::delete( 'rubrics', array( 'id' => $id ) );
		if ( $ok ) {
			SSLMS_Audit::log( 'rubric_deleted', 'rubric', $id, 'Unused rubric deleted' );
		}
		return $ok;
	}

	/* ---------------------------------------------------------------
	 * Criteria + levels
	 * ------------------------------------------------------------- */

	public static function get_criteria( int $rubric_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'rubric_criteria' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE rubric_id = %d ORDER BY sort_order ASC, id ASC", $rubric_id
		) );
	}

	public static function get_levels( int $criterion_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'rubric_levels' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE criterion_id = %d ORDER BY level_index ASC, id ASC", $criterion_id
		) );
	}

	/** Rubric with criteria, each carrying its levels. */
	public static function get_full( int $rubric_id ): ?object {
		$rubric = self::get_rubric( $rubric_id );
		if ( ! $rubric ) {
			return null;
		}
		$rubric->criteria = self::get_criteria( $rubric_id );
		foreach ( $rubric->criteria as $criterion ) {
			$criterion->levels = self::get_levels( (int) $criterion->id );
		}
		return $rubric;
	}

	public static function add_criterion( int $rubric_id, string $text, float $weight = 1, bool $is_critical = false ): int {
		global $wpdb;
		$t   = SSLMS_DB::table( 'rubric_criteria' );
		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$t} WHERE rubric_id = %d", $rubric_id ) );
		return SSLMS_DB::insert( 'rubric_criteria', array(
			'rubric_id'      => $rubric_id,
			'criterion_text' => $text,
			'weight'         => max( 0.01, $weight ),
			'is_critical'    => (int) $is_critical,
			'sort_order'     => $max + 10,
		) );
	}

	public static function update_criterion( int $criterion_id, array $args ): bool {
		$data = array();
		if ( array_key_exists( 'criterion_text', $args ) ) {
			$data['criterion_text'] = $args['criterion_text'];
		}
		if ( array_key_exists( 'weight', $args ) ) {
			$data['weight'] = max( 0.01, (float) $args['weight'] );
		}
		if ( array_key_exists( 'is_critical', $args ) ) {
			$data['is_critical'] = (int) (bool) $args['is_critical'];
		}
		if ( array_key_exists( 'sort_order', $args ) ) {
			$data['sort_order'] = (int) $args['sort_order'];
		}
		if ( ! $data ) {
			return false;
		}
		return SSLMS_DB::update( 'rubric_criteria', $data, array( 'id' => $criterion_id ) );
	}

	/** Refuses once scored in any record (NABH retention). */
	public static function delete_criterion( int $criterion_id ) {
		global $wpdb;
		$sc   = SSLMS_DB::table( 'assessment_scores' );
		$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sc} WHERE criterion_id = %d", $criterion_id ) );
		if ( $used > 0 ) {
			return new WP_Error( 'sslms_in_use', 'This criterion has recorded scores and cannot be deleted (NABH retention). Edit its text instead.' );
		}
		$wpdb->delete( SSLMS_DB::table( 'rubric_levels' ), array( 'criterion_id' => $criterion_id ) );
		return SSLMS_DB::delete( 'rubric_criteria', array( 'id' => $criterion_id ) );
	}

	public static function add_level( int $criterion_id, string $label, string $descriptor, float $marks ): int {
		global $wpdb;
		$t   = SSLMS_DB::table( 'rubric_levels' );
		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(level_index) FROM {$t} WHERE criterion_id = %d", $criterion_id ) );
		return SSLMS_DB::insert( 'rubric_levels', array(
			'criterion_id' => $criterion_id,
			'level_index'  => $max + 1,
			'label'        => $label,
			'descriptor'   => $descriptor,
			'marks'        => $marks,
		) );
	}

	public static function update_level( int $level_id, array $args ): bool {
		$data = array();
		foreach ( array( 'label', 'descriptor' ) as $f ) {
			if ( array_key_exists( $f, $args ) ) {
				$data[ $f ] = $args[ $f ];
			}
		}
		if ( array_key_exists( 'marks', $args ) ) {
			$data['marks'] = (float) $args['marks'];
		}
		if ( array_key_exists( 'level_index', $args ) ) {
			$data['level_index'] = (int) $args['level_index'];
		}
		if ( ! $data ) {
			return false;
		}
		return SSLMS_DB::update( 'rubric_levels', $data, array( 'id' => $level_id ) );
	}

	/** Refuses once selected in any record (NABH retention). */
	public static function delete_level( int $level_id ) {
		global $wpdb;
		$sc   = SSLMS_DB::table( 'assessment_scores' );
		$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sc} WHERE level_id = %d", $level_id ) );
		if ( $used > 0 ) {
			return new WP_Error( 'sslms_in_use', 'This level has been used in recorded scores and cannot be deleted (NABH retention).' );
		}
		return SSLMS_DB::delete( 'rubric_levels', array( 'id' => $level_id ) );
	}

	/* ---------------------------------------------------------------
	 * Scoping — single gate for who may assess whom
	 * ------------------------------------------------------------- */

	/**
	 * May $assessor_id open/score an assessment on $learner_id?
	 * Returns the source role string, or null if not permitted.
	 * Order matters: sslms_manage → educator; self → self; else the
	 * relationships row (learner ← assessor) decides.
	 */
	public static function assessor_role( int $learner_id, int $assessor_id ): ?string {
		if ( user_can( $assessor_id, 'sslms_manage' ) ) {
			return 'educator';
		}
		if ( $learner_id === $assessor_id ) {
			return user_can( $assessor_id, 'sslms_learn' ) ? 'self' : null;
		}
		global $wpdb;
		$r        = SSLMS_DB::table( 'relationships' );
		$rel_type = $wpdb->get_var( $wpdb->prepare(
			"SELECT rel_type FROM {$r} WHERE user_id = %d AND related_user_id = %d
			 AND rel_type IN ('preceptor','evaluator','mentor','peer') LIMIT 1",
			$learner_id, $assessor_id
		) );
		return $rel_type ? ( self::REL_ROLES[ $rel_type ] ?? null ) : null;
	}

	/** Learners this assessor may assess (for the "new assessment" picker). */
	public static function learners_in_scope( int $assessor_id ): array {
		global $wpdb;
		$r = SSLMS_DB::table( 'relationships' );
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$r} WHERE related_user_id = %d
			 AND rel_type IN ('preceptor','evaluator','mentor','peer')",
			$assessor_id
		) );
	}

	/**
	 * May $viewer_id read a record? Learner (own), the assessor who wrote it,
	 * or sslms_manage. Single source of truth for every read path.
	 */
	public static function user_can_view_record( object $record, int $viewer_id ): bool {
		return (int) $record->learner_id === $viewer_id
			|| (int) $record->assessor_id === $viewer_id
			|| user_can( $viewer_id, 'sslms_manage' );
	}

	/* ---------------------------------------------------------------
	 * Assessment records
	 * ------------------------------------------------------------- */

	public static function get_record( int $id ): ?object {
		return SSLMS_DB::get_row( 'assessment_records', $id );
	}

	public static function open_record( int $rubric_id, int $learner_id, int $assessor_id, string $context = '' ) {
		$rubric = self::get_rubric( $rubric_id );
		if ( ! $rubric || ! $rubric->is_active ) {
			return new WP_Error( 'sslms_not_found', 'Rubric not found or inactive.' );
		}
		if ( ! self::get_criteria( $rubric_id ) ) {
			return new WP_Error( 'sslms_empty_rubric', 'This rubric has no criteria yet.' );
		}
		$role = self::assessor_role( $learner_id, $assessor_id );
		if ( ! $role ) {
			return new WP_Error( 'sslms_forbidden', 'You are not linked to this learner as an assessor.', array( 'status' => 403 ) );
		}
		$id = SSLMS_DB::insert( 'assessment_records', array(
			'rubric_id'     => $rubric_id,
			'learner_id'    => $learner_id,
			'assessor_id'   => $assessor_id,
			'assessor_role' => $role,
			'context'       => $context,
			'status'        => 'draft',
			'created_at'    => SSLMS_DB::now(),
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_db_error', 'Could not open the assessment.' );
		}
		SSLMS_Audit::log( 'assessment_opened', 'assessment_record', $id,
			'Rubric ' . $rubric_id . ' opened for learner ' . $learner_id . ' by ' . $role . ' ' . $assessor_id );
		return $id;
	}

	/** Score one criterion of a draft record. Only its assessor may score. */
	public static function score_criterion( int $record_id, int $criterion_id, int $level_id, string $note, int $actor ) {
		$record = self::get_record( $record_id );
		if ( ! $record ) {
			return new WP_Error( 'sslms_not_found', 'Assessment not found.' );
		}
		if ( 'signed' === $record->status ) {
			return new WP_Error( 'sslms_locked', 'This assessment is signed and can no longer be edited.' );
		}
		if ( (int) $record->assessor_id !== $actor ) {
			return new WP_Error( 'sslms_forbidden', 'Only the assessor who opened this assessment may score it.', array( 'status' => 403 ) );
		}
		$criterion = SSLMS_DB::get_row( 'rubric_criteria', $criterion_id );
		if ( ! $criterion || (int) $criterion->rubric_id !== (int) $record->rubric_id ) {
			return new WP_Error( 'sslms_invalid_item', 'That criterion does not belong to this rubric.' );
		}
		$level = SSLMS_DB::get_row( 'rubric_levels', $level_id );
		if ( ! $level || (int) $level->criterion_id !== $criterion_id ) {
			return new WP_Error( 'sslms_invalid_item', 'That level does not belong to this criterion.' );
		}

		global $wpdb;
		$sc       = SSLMS_DB::table( 'assessment_scores' );
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$sc} WHERE record_id = %d AND criterion_id = %d", $record_id, $criterion_id
		) );
		$data = array(
			'level_id' => $level_id,
			'marks'    => $level->marks,
			'note'     => $note,
		);
		if ( $existing ) {
			$wpdb->update( $sc, $data, array( 'id' => (int) $existing ) );
		} else {
			$wpdb->insert( $sc, $data + array( 'record_id' => $record_id, 'criterion_id' => $criterion_id ) );
		}
		self::recompute( $record_id );
		return self::record_detail( $record_id );
	}

	/** Recompute weighted score %, critical-fail flag and provisional outcome. */
	public static function recompute( int $record_id ): void {
		$record = self::get_record( $record_id );
		if ( ! $record ) {
			return;
		}
		global $wpdb;
		$sc     = SSLMS_DB::table( 'assessment_scores' );
		$scores = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$sc} WHERE record_id = %d", $record_id ) );
		$by_criterion = array();
		foreach ( $scores as $s ) {
			$by_criterion[ (int) $s->criterion_id ] = $s;
		}

		$criteria        = self::get_criteria( (int) $record->rubric_id );
		$weight_sum      = 0.0;
		$weighted_earned = 0.0;
		$complete        = (bool) $criteria;
		$critical_failed = 0;
		foreach ( $criteria as $criterion ) {
			$levels = self::get_levels( (int) $criterion->id );
			$max    = 0.0;
			$min    = null;
			foreach ( $levels as $level ) {
				$max = max( $max, (float) $level->marks );
				$min = null === $min ? (float) $level->marks : min( $min, (float) $level->marks );
			}
			$score = $by_criterion[ (int) $criterion->id ] ?? null;
			if ( ! $score ) {
				$complete = false;
				continue;
			}
			if ( $max > 0 ) {
				$weight_sum      += (float) $criterion->weight;
				$weighted_earned += (float) $criterion->weight * ( (float) $score->marks / $max );
			}
			if ( $criterion->is_critical && null !== $min && (float) $score->marks <= $min && count( $levels ) > 1 ) {
				$critical_failed = 1;
			}
		}
		$pct    = $weight_sum > 0 ? round( 100 * $weighted_earned / $weight_sum, 2 ) : null;
		$rubric = self::get_rubric( (int) $record->rubric_id );
		$outcome = '';
		if ( $complete && null !== $pct && $rubric ) {
			$passed  = ! $critical_failed && ( (float) $rubric->pass_pct <= 0 || $pct >= (float) $rubric->pass_pct );
			$outcome = $passed ? 'pass' : 'fail';
		}
		SSLMS_DB::update( 'assessment_records', array(
			'score_pct'       => $pct,
			'critical_failed' => $critical_failed,
			'outcome'         => $outcome,
		), array( 'id' => $record_id ) );
	}

	/** Sign a fully-scored draft. Immutable afterwards. */
	public static function sign_record( int $record_id, string $feedback, int $actor ) {
		$record = self::get_record( $record_id );
		if ( ! $record ) {
			return new WP_Error( 'sslms_not_found', 'Assessment not found.' );
		}
		if ( 'signed' === $record->status ) {
			return new WP_Error( 'sslms_locked', 'This assessment is already signed.' );
		}
		if ( (int) $record->assessor_id !== $actor ) {
			return new WP_Error( 'sslms_forbidden', 'Only the assessor who opened this assessment may sign it.', array( 'status' => 403 ) );
		}
		self::recompute( $record_id );
		$record = self::get_record( $record_id );
		if ( '' === $record->outcome ) {
			return new WP_Error( 'sslms_incomplete', 'Score every criterion before signing.' );
		}
		SSLMS_DB::update( 'assessment_records', array(
			'feedback'  => $feedback,
			'status'    => 'signed',
			'signed_at' => SSLMS_DB::now(),
		), array( 'id' => $record_id ) );
		SSLMS_Audit::log( 'assessment_signed', 'assessment_record', $record_id,
			'Outcome ' . $record->outcome . ' (' . $record->score_pct . '%) for learner ' . $record->learner_id . ' by ' . $record->assessor_role . ' ' . $actor );

		/** Cross-module hook: competency framework (3b) will map signed evidence. */
		do_action( 'sslms_assessment_signed', self::get_record( $record_id ) );
		return self::record_detail( $record_id );
	}

	/** Delete a draft. Signed records are permanent (NABH). */
	public static function delete_record( int $record_id, int $actor ) {
		$record = self::get_record( $record_id );
		if ( ! $record ) {
			return new WP_Error( 'sslms_not_found', 'Assessment not found.' );
		}
		if ( 'signed' === $record->status ) {
			return new WP_Error( 'sslms_locked', 'Signed assessments are permanent records and cannot be deleted (NABH retention).' );
		}
		if ( (int) $record->assessor_id !== $actor && ! user_can( $actor, 'sslms_manage' ) ) {
			return new WP_Error( 'sslms_forbidden', 'Not permitted.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$wpdb->delete( SSLMS_DB::table( 'assessment_scores' ), array( 'record_id' => $record_id ) );
		SSLMS_DB::delete( 'assessment_records', array( 'id' => $record_id ) );
		SSLMS_Audit::log( 'assessment_draft_deleted', 'assessment_record', $record_id, 'Unsigned draft discarded' );
		return true;
	}

	/* ---------------------------------------------------------------
	 * Read paths (all callers must have passed user_can_view_record)
	 * ------------------------------------------------------------- */

	/** Record with rubric title, criteria, levels and current scores. */
	public static function record_detail( int $record_id ): ?object {
		$record = self::get_record( $record_id );
		if ( ! $record ) {
			return null;
		}
		global $wpdb;
		$sc     = SSLMS_DB::table( 'assessment_scores' );
		$scores = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$sc} WHERE record_id = %d", $record_id ) );
		$by_criterion = array();
		foreach ( $scores as $s ) {
			$by_criterion[ (int) $s->criterion_id ] = $s;
		}
		$rubric = self::get_full( (int) $record->rubric_id );
		$record->rubric_title = $rubric ? $rubric->title : '';
		$record->form_type    = $rubric ? $rubric->form_type : '';
		$record->criteria     = array();
		if ( $rubric ) {
			foreach ( $rubric->criteria as $criterion ) {
				$score = $by_criterion[ (int) $criterion->id ] ?? null;
				$record->criteria[] = (object) array(
					'criterion_id'   => (int) $criterion->id,
					'criterion_text' => $criterion->criterion_text,
					'weight'         => (float) $criterion->weight,
					'is_critical'    => (int) $criterion->is_critical,
					'levels'         => $criterion->levels,
					'level_id'       => $score ? (int) $score->level_id : 0,
					'marks'          => $score ? (float) $score->marks : null,
					'note'           => $score ? $score->note : '',
				);
			}
		}
		return $record;
	}

	/** All records about one learner (their evidence list), newest first. */
	public static function records_for_learner( int $learner_id, bool $signed_only = false ): array {
		global $wpdb;
		$ar = SSLMS_DB::table( 'assessment_records' );
		$rb = SSLMS_DB::table( 'rubrics' );
		$where = $signed_only ? "AND a.status = 'signed'" : '';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT a.*, r.title AS rubric_title, r.form_type FROM {$ar} a
			 JOIN {$rb} r ON r.id = a.rubric_id
			 WHERE a.learner_id = %d {$where} ORDER BY a.created_at DESC", $learner_id
		) );
	}

	/** Records authored by one assessor, optionally filtered by status. */
	public static function records_by_assessor( int $assessor_id, string $status = '' ): array {
		global $wpdb;
		$ar = SSLMS_DB::table( 'assessment_records' );
		$rb = SSLMS_DB::table( 'rubrics' );
		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare(
				"SELECT a.*, r.title AS rubric_title, r.form_type FROM {$ar} a
				 JOIN {$rb} r ON r.id = a.rubric_id
				 WHERE a.assessor_id = %d AND a.status = %s ORDER BY a.created_at DESC", $assessor_id, $status
			) );
		}
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT a.*, r.title AS rubric_title, r.form_type FROM {$ar} a
			 JOIN {$rb} r ON r.id = a.rubric_id
			 WHERE a.assessor_id = %d ORDER BY a.created_at DESC", $assessor_id
		) );
	}

	/** CSV rows for exporting one signed record. */
	public static function export_rows( int $record_id ): array {
		$detail = self::record_detail( $record_id );
		if ( ! $detail ) {
			return array();
		}
		$rows = array();
		foreach ( $detail->criteria as $c ) {
			$label = '';
			foreach ( $c->levels as $level ) {
				if ( (int) $level->id === $c->level_id ) {
					$label = $level->label;
				}
			}
			$rows[] = array(
				'Criterion' => $c->criterion_text,
				'Level'     => $label ?: 'unscored',
				'Marks'     => null === $c->marks ? '' : $c->marks,
				'Critical'  => $c->is_critical ? 'yes' : '',
				'Note'      => $c->note,
			);
		}
		return $rows;
	}
}
