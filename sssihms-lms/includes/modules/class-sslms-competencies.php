<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 3b — Competency framework + learner passport.
 *
 * Dictionary: hierarchical competencies (domain → competency via parent_id),
 * per discipline. Evidence mapping: quizzes, checklist items, rubrics,
 * rubric criteria and rotations can be tagged to competencies; existing
 * data participates retroactively because attainment is computed on read.
 *
 * Attainment: the computed state ('not_yet' → 'in_progress' →
 * 'evidence_complete') is derived from mapped evidence; the final attained
 * LEVEL (novice/competent/proficient) is always a human sign-off by a user
 * with sslms_manage, stored in competency_attainments (append-only history,
 * latest row wins — never deleted, NABH).
 */
class SSLMS_Competencies {

	const OBJECT_TYPES = array( 'quiz', 'checklist_item', 'rubric', 'rubric_criterion', 'rotation' );
	const LEVELS       = array( 'novice', 'competent', 'proficient' );

	/* ---------------------------------------------------------------
	 * Dictionary CRUD
	 * ------------------------------------------------------------- */

	public static function get_competency( int $id ): ?object {
		return SSLMS_DB::get_row( 'competencies', $id );
	}

	/** Whole dictionary (optionally one discipline), ordered for tree display. */
	public static function list_competencies( string $discipline = '', bool $active_only = false ): array {
		global $wpdb;
		$t     = SSLMS_DB::table( 'competencies' );
		$where = array( '1=1' );
		$args  = array();
		if ( $discipline ) {
			$where[] = 'discipline = %s';
			$args[]  = $discipline;
		}
		if ( $active_only ) {
			$where[] = 'is_active = 1';
		}
		$sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY discipline ASC, parent_id ASC, sort_order ASC, id ASC';
		return $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
	}

	/** Top-level domains with their child competencies attached. */
	public static function tree( string $discipline = '', bool $active_only = true ): array {
		$all      = self::list_competencies( $discipline, $active_only );
		$children = array();
		foreach ( $all as $row ) {
			$children[ (int) $row->parent_id ][] = $row;
		}
		$tree = array();
		foreach ( $children[0] ?? array() as $domain ) {
			$domain->children = $children[ (int) $domain->id ] ?? array();
			$tree[] = $domain;
		}
		return $tree;
	}

	public static function create_competency( array $args ) {
		$parent_id = (int) ( $args['parent_id'] ?? 0 );
		if ( $parent_id && ! self::get_competency( $parent_id ) ) {
			return new WP_Error( 'sslms_not_found', 'Parent competency not found.' );
		}
		global $wpdb;
		$t   = SSLMS_DB::table( 'competencies' );
		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$t} WHERE parent_id = %d", $parent_id ) );
		$id  = SSLMS_DB::insert( 'competencies', array(
			'parent_id'   => $parent_id,
			'discipline'  => $args['discipline'] ?? '',
			'code'        => $args['code'] ?? '',
			'title'       => $args['title'],
			'description' => $args['description'] ?? '',
			'sort_order'  => $max + 10,
			'is_active'   => 1,
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_db_error', 'Could not create competency.' );
		}
		SSLMS_Audit::log( 'competency_created', 'competency', $id, 'Competency "' . $args['title'] . '" created' );
		return $id;
	}

	public static function update_competency( int $id, array $args ): bool {
		$data = array();
		foreach ( array( 'discipline', 'code', 'title', 'description' ) as $f ) {
			if ( array_key_exists( $f, $args ) ) {
				$data[ $f ] = $args[ $f ];
			}
		}
		if ( array_key_exists( 'sort_order', $args ) ) {
			$data['sort_order'] = (int) $args['sort_order'];
		}
		if ( array_key_exists( 'is_active', $args ) ) {
			$data['is_active'] = (int) (bool) $args['is_active'];
		}
		if ( ! $data ) {
			return false;
		}
		$ok = SSLMS_DB::update( 'competencies', $data, array( 'id' => $id ) );
		if ( $ok ) {
			SSLMS_Audit::log( 'competency_updated', 'competency', $id, 'Fields: ' . implode( ',', array_keys( $data ) ) );
		}
		return $ok;
	}

	/** Refuses with children, mappings or attainments (NABH); deactivate instead. */
	public static function delete_competency( int $id ) {
		global $wpdb;
		$t = SSLMS_DB::table( 'competencies' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE parent_id = %d", $id ) ) ) {
			return new WP_Error( 'sslms_in_use', 'This domain has child competencies; move or delete them first.' );
		}
		$m = SSLMS_DB::table( 'competency_map' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} WHERE competency_id = %d", $id ) ) ) {
			return new WP_Error( 'sslms_in_use', 'This competency has mapped evidence; remove the mappings first or deactivate it.' );
		}
		$a = SSLMS_DB::table( 'competency_attainments' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$a} WHERE competency_id = %d", $id ) ) ) {
			return new WP_Error( 'sslms_in_use', 'This competency has signed attainments and cannot be deleted (NABH retention); deactivate it instead.' );
		}
		$ok = SSLMS_DB::delete( 'competencies', array( 'id' => $id ) );
		if ( $ok ) {
			SSLMS_Audit::log( 'competency_deleted', 'competency', $id, 'Unused competency deleted' );
		}
		return $ok;
	}

	/* ---------------------------------------------------------------
	 * Evidence mapping
	 * ------------------------------------------------------------- */

	public static function get_mappings( int $competency_id ): array {
		global $wpdb;
		$t    = SSLMS_DB::table( 'competency_map' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE competency_id = %d ORDER BY object_type ASC, object_id ASC", $competency_id
		) );
		foreach ( $rows as $row ) {
			$row->object_label = self::object_label( $row->object_type, (int) $row->object_id );
		}
		return $rows;
	}

	public static function add_mapping( int $competency_id, string $object_type, int $object_id, int $by ) {
		if ( ! in_array( $object_type, self::OBJECT_TYPES, true ) ) {
			return new WP_Error( 'sslms_invalid_type', 'Invalid evidence type.' );
		}
		if ( ! self::get_competency( $competency_id ) ) {
			return new WP_Error( 'sslms_not_found', 'Competency not found.' );
		}
		if ( ! self::object_exists( $object_type, $object_id ) ) {
			return new WP_Error( 'sslms_not_found', 'No ' . $object_type . ' with id ' . $object_id . ' exists.' );
		}
		global $wpdb;
		$t = SSLMS_DB::table( 'competency_map' );
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE competency_id = %d AND object_type = %s AND object_id = %d",
			$competency_id, $object_type, $object_id
		) );
		if ( $exists ) {
			return new WP_Error( 'sslms_duplicate', 'That evidence is already mapped to this competency.' );
		}
		$id = SSLMS_DB::insert( 'competency_map', array(
			'competency_id' => $competency_id,
			'object_type'   => $object_type,
			'object_id'     => $object_id,
			'created_by'    => $by,
			'created_at'    => SSLMS_DB::now(),
		) );
		SSLMS_Audit::log( 'competency_mapped', 'competency', $competency_id, $object_type . ' ' . $object_id . ' mapped as evidence' );
		return $id;
	}

	public static function remove_mapping( int $mapping_id ) {
		$row = SSLMS_DB::get_row( 'competency_map', $mapping_id );
		if ( ! $row ) {
			return new WP_Error( 'sslms_not_found', 'Mapping not found.' );
		}
		SSLMS_DB::delete( 'competency_map', array( 'id' => $mapping_id ) );
		SSLMS_Audit::log( 'competency_unmapped', 'competency', (int) $row->competency_id, $row->object_type . ' ' . $row->object_id . ' unmapped' );
		return true;
	}

	private static function object_exists( string $type, int $id ): bool {
		$table = array(
			'quiz'             => 'quizzes',
			'checklist_item'   => 'checklist_items',
			'rubric'           => 'rubrics',
			'rubric_criterion' => 'rubric_criteria',
			'rotation'         => 'rotations',
		)[ $type ];
		return (bool) SSLMS_DB::get_row( $table, $id );
	}

	public static function object_label( string $type, int $id ): string {
		switch ( $type ) {
			case 'quiz':
				$row = SSLMS_DB::get_row( 'quizzes', $id );
				return $row ? 'Quiz: ' . $row->title : 'Quiz #' . $id . ' (deleted)';
			case 'checklist_item':
				$row = SSLMS_DB::get_row( 'checklist_items', $id );
				if ( ! $row ) {
					return 'Checklist item #' . $id . ' (deleted)';
				}
				$list = SSLMS_DB::get_row( 'checklists', (int) $row->checklist_id );
				return 'Checklist item: ' . wp_trim_words( $row->item_text, 10 ) . ( $list ? ' (' . $list->title . ')' : '' );
			case 'rubric':
				$row = SSLMS_DB::get_row( 'rubrics', $id );
				return $row ? 'Rubric: ' . $row->title : 'Rubric #' . $id . ' (deleted)';
			case 'rubric_criterion':
				$row = SSLMS_DB::get_row( 'rubric_criteria', $id );
				if ( ! $row ) {
					return 'Rubric criterion #' . $id . ' (deleted)';
				}
				$rubric = SSLMS_DB::get_row( 'rubrics', (int) $row->rubric_id );
				return 'Criterion: ' . wp_trim_words( $row->criterion_text, 10 ) . ( $rubric ? ' (' . $rubric->title . ')' : '' );
			case 'rotation':
				$row = SSLMS_DB::get_row( 'rotations', $id );
				return $row ? 'Rotation: ' . $row->department . ' (' . SSLMS_DB::fmt_date( $row->start_date ) . ')' : 'Rotation #' . $id . ' (deleted)';
		}
		return $type . ' #' . $id;
	}

	/* ---------------------------------------------------------------
	 * Evidence evaluation (computed on read; retroactive by design)
	 * ------------------------------------------------------------- */

	/**
	 * Is one mapped evidence object satisfied for this learner?
	 * quiz → a passed attempt; checklist_item → a 'competent' sign-off;
	 * rubric → a signed record with outcome pass; rubric_criterion → in any
	 * signed record, scored at that criterion's top-marks level;
	 * rotation → approved hours meet the rotation's required hours.
	 */
	public static function evidence_satisfied( string $type, int $object_id, int $user_id ): bool {
		global $wpdb;
		switch ( $type ) {
			case 'quiz':
				$t = SSLMS_DB::table( 'quiz_attempts' );
				return (bool) $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$t} WHERE quiz_id = %d AND user_id = %d AND passed = 1 LIMIT 1", $object_id, $user_id
				) );
			case 'checklist_item':
				$so = SSLMS_DB::table( 'checklist_signoffs' );
				$as = SSLMS_DB::table( 'checklist_assignments' );
				return (bool) $wpdb->get_var( $wpdb->prepare(
					"SELECT s.id FROM {$so} s JOIN {$as} a ON a.id = s.assignment_id
					 WHERE s.item_id = %d AND a.user_id = %d AND s.rating = 'competent' LIMIT 1",
					$object_id, $user_id
				) );
			case 'rubric':
				$ar = SSLMS_DB::table( 'assessment_records' );
				return (bool) $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$ar} WHERE rubric_id = %d AND learner_id = %d AND status = 'signed' AND outcome = 'pass' LIMIT 1",
					$object_id, $user_id
				) );
			case 'rubric_criterion':
				$ar = SSLMS_DB::table( 'assessment_records' );
				$sc = SSLMS_DB::table( 'assessment_scores' );
				$rl = SSLMS_DB::table( 'rubric_levels' );
				return (bool) $wpdb->get_var( $wpdb->prepare(
					"SELECT s.id FROM {$sc} s
					 JOIN {$ar} r ON r.id = s.record_id AND r.learner_id = %d AND r.status = 'signed'
					 WHERE s.criterion_id = %d
					 AND s.marks >= (SELECT MAX(marks) FROM {$rl} WHERE criterion_id = %d) LIMIT 1",
					$user_id, $object_id, $object_id
				) );
			case 'rotation':
				$rot = SSLMS_DB::get_row( 'rotations', $object_id );
				if ( ! $rot || (int) $rot->user_id !== $user_id ) {
					return false;
				}
				$hl    = SSLMS_DB::table( 'hour_logs' );
				$hours = (float) $wpdb->get_var( $wpdb->prepare(
					"SELECT COALESCE(SUM(hours),0) FROM {$hl} WHERE rotation_id = %d AND user_id = %d AND status = 'approved'",
					$object_id, $user_id
				) );
				return (float) $rot->required_hours > 0 && $hours >= (float) $rot->required_hours;
		}
		return false;
	}

	/**
	 * One competency's status for one learner: mapped evidence with per-item
	 * satisfied flags, computed state, and the latest signed attainment.
	 */
	public static function status_for_user( int $competency_id, int $user_id ): object {
		$mappings  = self::get_mappings( $competency_id );
		$satisfied = 0;
		$evidence  = array();
		foreach ( $mappings as $m ) {
			$done = self::evidence_satisfied( $m->object_type, (int) $m->object_id, $user_id );
			if ( $done ) {
				$satisfied++;
			}
			$evidence[] = (object) array(
				'object_type'  => $m->object_type,
				'object_id'    => (int) $m->object_id,
				'object_label' => $m->object_label,
				'satisfied'    => $done,
			);
		}
		$total = count( $mappings );
		if ( 0 === $total || 0 === $satisfied ) {
			$state = 'not_yet';
		} elseif ( $satisfied < $total ) {
			$state = 'in_progress';
		} else {
			$state = 'evidence_complete';
		}
		return (object) array(
			'competency_id'   => $competency_id,
			'evidence'        => $evidence,
			'satisfied'       => $satisfied,
			'total'           => $total,
			'state'           => $state,
			'attained'        => self::latest_attainment( $competency_id, $user_id ),
		);
	}

	/** Latest signed attainment row for a learner+competency (null if none). */
	public static function latest_attainment( int $competency_id, int $user_id ): ?object {
		global $wpdb;
		$t = SSLMS_DB::table( 'competency_attainments' );
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE competency_id = %d AND user_id = %d ORDER BY signed_at DESC, id DESC LIMIT 1",
			$competency_id, $user_id
		) ) ?: null;
	}

	/**
	 * Sign a final attainment level. sslms_manage only — attainment is a
	 * human decision, never automatic. Append-only: history is kept.
	 */
	public static function sign_attainment( int $competency_id, int $user_id, string $level, string $note, int $signer ) {
		if ( ! user_can( $signer, 'sslms_manage' ) ) {
			return new WP_Error( 'sslms_forbidden', 'Only LMS administrators/educators may sign attainment.', array( 'status' => 403 ) );
		}
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			return new WP_Error( 'sslms_invalid_level', 'Invalid attainment level.' );
		}
		if ( ! self::get_competency( $competency_id ) ) {
			return new WP_Error( 'sslms_not_found', 'Competency not found.' );
		}
		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'sslms_not_found', 'Learner not found.' );
		}
		$id = SSLMS_DB::insert( 'competency_attainments', array(
			'competency_id' => $competency_id,
			'user_id'       => $user_id,
			'level'         => $level,
			'note'          => $note,
			'signed_by'     => $signer,
			'signed_at'     => SSLMS_DB::now(),
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_db_error', 'Could not record attainment.' );
		}
		SSLMS_Audit::log( 'competency_attained', 'competency', $competency_id,
			'Level ' . $level . ' signed for learner ' . $user_id . ' by ' . $signer );
		return $id;
	}

	/* ---------------------------------------------------------------
	 * Passport + cohort views
	 * ------------------------------------------------------------- */

	/**
	 * Learner passport: domains → competencies, each with evidence status and
	 * signed level. $discipline '' = all disciplines with mapped data.
	 */
	public static function passport( int $user_id, string $discipline = '' ): array {
		$out = array();
		foreach ( self::tree( $discipline ) as $domain ) {
			$rows = array();
			foreach ( $domain->children as $comp ) {
				$status = self::status_for_user( (int) $comp->id, $user_id );
				$rows[] = (object) array(
					'competency' => $comp,
					'status'     => $status,
				);
			}
			// A domain with no children is itself assessable.
			if ( ! $rows ) {
				$rows[] = (object) array(
					'competency' => $domain,
					'status'     => self::status_for_user( (int) $domain->id, $user_id ),
				);
			}
			$out[] = (object) array( 'domain' => $domain, 'rows' => $rows );
		}
		return $out;
	}

	/**
	 * Cohort matrix for educators: learners × competencies of one discipline.
	 * Returns ['competencies' => [...], 'learners' => [ [user, cells...] ]].
	 */
	public static function cohort_matrix( string $discipline, array $user_ids ): array {
		$comps = array();
		foreach ( self::tree( $discipline ) as $domain ) {
			foreach ( $domain->children ?: array( $domain ) as $comp ) {
				$comps[] = $comp;
			}
		}
		$learners = array();
		foreach ( $user_ids as $uid ) {
			$uid   = (int) $uid;
			$user  = get_userdata( $uid );
			$cells = array();
			foreach ( $comps as $comp ) {
				$status  = self::status_for_user( (int) $comp->id, $uid );
				$cells[] = (object) array(
					'competency_id' => (int) $comp->id,
					'state'         => $status->state,
					'level'         => $status->attained ? $status->attained->level : '',
					'satisfied'     => $status->satisfied,
					'total'         => $status->total,
				);
			}
			$learners[] = (object) array(
				'user_id'   => $uid,
				'user_name' => $user ? $user->display_name : ( 'User ' . $uid ),
				'cells'     => $cells,
			);
		}
		return array( 'competencies' => $comps, 'learners' => $learners );
	}

	/* ---------------------------------------------------------------
	 * Starter dictionary (editable after import; skips non-empty disciplines)
	 * ------------------------------------------------------------- */

	public static function seed_starter_set( int $by ) {
		$starter = array(
			'Nursing' => array(
				'Clinical care & safety'        => array( 'Medication administration', 'Infection prevention & control', 'Vital signs & early warning', 'Safe patient handling' ),
				'Communication & teamwork'      => array( 'Patient & family communication', 'Handover (SBAR)', 'Interdisciplinary teamwork' ),
				'Professionalism & ethics'      => array( 'Accountability & documentation', 'Dignity, privacy & consent' ),
				'Emergency response'            => array( 'Basic life support', 'Recognising deterioration' ),
			),
			'Allied Health' => array(
				'Technical proficiency'         => array( 'Equipment operation & QC', 'Specimen/procedure protocols' ),
				'Patient interaction'           => array( 'Positioning & preparation', 'Explaining procedures' ),
				'Quality & safety'              => array( 'Radiation/biosafety practice', 'Reporting & escalation' ),
			),
			'Chaplaincy' => array(
				'Spiritual care'                => array( 'Presence & listening', 'Prayer & ritual appropriateness' ),
				'Formation & reflection'        => array( 'Reflective practice', 'Self-care & supervision' ),
				'Institutional integration'     => array( 'Interfaith sensitivity', 'Care-team collaboration' ),
			),
		);
		global $wpdb;
		$t       = SSLMS_DB::table( 'competencies' );
		$created = 0;
		foreach ( $starter as $discipline => $domains ) {
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE discipline = %s", $discipline ) );
			if ( $existing > 0 ) {
				continue; // Never overwrite an edited dictionary.
			}
			$d = 0;
			foreach ( $domains as $domain_title => $children ) {
				$d += 10;
				$domain_id = SSLMS_DB::insert( 'competencies', array(
					'parent_id' => 0, 'discipline' => $discipline, 'code' => '',
					'title' => $domain_title, 'description' => '', 'sort_order' => $d, 'is_active' => 1,
				) );
				$s = 0;
				foreach ( $children as $child_title ) {
					$s += 10;
					SSLMS_DB::insert( 'competencies', array(
						'parent_id' => $domain_id, 'discipline' => $discipline, 'code' => '',
						'title' => $child_title, 'description' => '', 'sort_order' => $s, 'is_active' => 1,
					) );
					$created++;
				}
				$created++;
			}
		}
		if ( $created ) {
			SSLMS_Audit::log( 'competency_starter_seeded', 'competency', 0, $created . ' starter competencies created by user ' . $by );
		}
		return $created;
	}
}
