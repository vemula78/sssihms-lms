<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 3e — Branching clinical scenarios (Simulation Tier A).
 *
 * A scenario is a decision tree of nodes: info (continue), decision
 * (options carrying marks + feedback + next node) and end (debrief).
 * Fully deterministic and on-prem; no runtime AI cost. AI participates at
 * AUTHORING time only: educators paste a Claude-drafted scenario as JSON
 * into the importer, review it in the editor, then publish. Fictional
 * patients only (DPDP) — the importer/editor is the human review gate.
 *
 * Scoring: at each visited decision the learner earns the chosen option's
 * marks; the denominator is the best available option at each visited
 * decision, so every path is scored fairly. Completed attempts are
 * immutable; a passed attempt on a published scenario can serve as 3b
 * competency evidence (object type 'scenario').
 */
class SSLMS_Scenarios {

	const NODE_TYPES = array( 'info', 'decision', 'end' );

	/* ---------------------------------------------------------------
	 * Scenario CRUD
	 * ------------------------------------------------------------- */

	public static function get_scenario( int $id ): ?object {
		return SSLMS_DB::get_row( 'scenarios', $id );
	}

	public static function list_scenarios( bool $published_only = false ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'scenarios' );
		if ( $published_only ) {
			return $wpdb->get_results( "SELECT * FROM {$t} WHERE status = 'published' ORDER BY title ASC" );
		}
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY title ASC" );
	}

	public static function create_scenario( array $args, int $by ) {
		$id = SSLMS_DB::insert( 'scenarios', array(
			'title'       => $args['title'],
			'discipline'  => $args['discipline'] ?? '',
			'description' => $args['description'] ?? '',
			'status'      => 'draft',
			'pass_pct'    => isset( $args['pass_pct'] ) ? (float) $args['pass_pct'] : 70,
			'created_by'  => $by,
			'created_at'  => SSLMS_DB::now(),
		) );
		if ( ! $id ) {
			return new WP_Error( 'sslms_db_error', 'Could not create the scenario.' );
		}
		SSLMS_Audit::log( 'scenario_created', 'scenario', $id, 'Scenario "' . $args['title'] . '" created' );
		return $id;
	}

	public static function update_scenario( int $id, array $args ) {
		$scenario = self::get_scenario( $id );
		if ( ! $scenario ) {
			return new WP_Error( 'sslms_not_found', 'Scenario not found.' );
		}
		$data = array();
		foreach ( array( 'title', 'discipline', 'description' ) as $f ) {
			if ( array_key_exists( $f, $args ) ) {
				$data[ $f ] = $args[ $f ];
			}
		}
		if ( array_key_exists( 'pass_pct', $args ) ) {
			$data['pass_pct'] = (float) $args['pass_pct'];
		}
		if ( array_key_exists( 'status', $args ) && in_array( $args['status'], array( 'draft', 'published' ), true ) ) {
			if ( 'published' === $args['status'] ) {
				$problems = self::validate_graph( $id );
				if ( $problems ) {
					return new WP_Error( 'sslms_invalid_graph', 'Cannot publish: ' . implode( ' ', $problems ) );
				}
			}
			$data['status'] = $args['status'];
		}
		if ( ! $data ) {
			return false;
		}
		$ok = SSLMS_DB::update( 'scenarios', $data, array( 'id' => $id ) );
		if ( $ok ) {
			SSLMS_Audit::log( 'scenario_updated', 'scenario', $id, 'Fields: ' . implode( ',', array_keys( $data ) ) );
		}
		return $ok;
	}

	/** Refuses once attempts exist (retention); unpublish instead. */
	public static function delete_scenario( int $id ) {
		global $wpdb;
		$at = SSLMS_DB::table( 'scenario_attempts' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$at} WHERE scenario_id = %d", $id ) ) ) {
			return new WP_Error( 'sslms_in_use', 'This scenario has attempts and cannot be deleted; set it back to draft instead.' );
		}
		$wpdb->delete( SSLMS_DB::table( 'scenario_nodes' ), array( 'scenario_id' => $id ) );
		$ok = SSLMS_DB::delete( 'scenarios', array( 'id' => $id ) );
		if ( $ok ) {
			SSLMS_Audit::log( 'scenario_deleted', 'scenario', $id, 'Unused scenario deleted' );
		}
		return $ok;
	}

	/* ---------------------------------------------------------------
	 * Nodes
	 * ------------------------------------------------------------- */

	public static function get_node( int $id ): ?object {
		$node = SSLMS_DB::get_row( 'scenario_nodes', $id );
		if ( $node ) {
			$node->options = $node->options ? ( json_decode( $node->options, true ) ?: array() ) : array();
		}
		return $node;
	}

	public static function get_nodes( int $scenario_id ): array {
		global $wpdb;
		$t    = SSLMS_DB::table( 'scenario_nodes' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE scenario_id = %d ORDER BY is_start DESC, id ASC", $scenario_id
		) );
		foreach ( $rows as $row ) {
			$row->options = $row->options ? ( json_decode( $row->options, true ) ?: array() ) : array();
		}
		return $rows;
	}

	public static function start_node( int $scenario_id ): ?object {
		global $wpdb;
		$t  = SSLMS_DB::table( 'scenario_nodes' );
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE scenario_id = %d AND is_start = 1 LIMIT 1", $scenario_id
		) );
		return $id ? self::get_node( (int) $id ) : null;
	}

	/**
	 * Create/update a node. $options = array of
	 * ['label' => string, 'next' => node id (0 while wiring up),
	 *  'marks' => float, 'feedback' => string] for decision nodes;
	 * info nodes use a single option as their "continue" edge.
	 */
	public static function save_node( int $scenario_id, array $args, int $node_id = 0 ) {
		if ( ! self::get_scenario( $scenario_id ) ) {
			return new WP_Error( 'sslms_not_found', 'Scenario not found.' );
		}
		$type = $args['node_type'] ?? 'decision';
		if ( ! in_array( $type, self::NODE_TYPES, true ) ) {
			return new WP_Error( 'sslms_invalid_type', 'Invalid node type.' );
		}
		$options = array();
		foreach ( (array) ( $args['options'] ?? array() ) as $option ) {
			if ( ! is_array( $option ) || '' === trim( (string) ( $option['label'] ?? '' ) ) ) {
				continue;
			}
			$options[] = array(
				'label'    => sanitize_text_field( $option['label'] ),
				'next'     => (int) ( $option['next'] ?? 0 ),
				'marks'    => (float) ( $option['marks'] ?? 0 ),
				'feedback' => sanitize_textarea_field( (string) ( $option['feedback'] ?? '' ) ),
			);
		}
		if ( 'end' !== $type && ! $options && $node_id === 0 && isset( $args['options'] ) ) {
			return new WP_Error( 'sslms_invalid_options', 'Non-end nodes need at least one option.' );
		}
		$data = array(
			'scenario_id' => $scenario_id,
			'node_type'   => $type,
			'title'       => $args['title'] ?? '',
			'body'        => $args['body'] ?? '',
			'options'     => wp_json_encode( $options ),
			'debrief'     => $args['debrief'] ?? '',
		);
		if ( $node_id ) {
			$existing = self::get_node( $node_id );
			if ( ! $existing || (int) $existing->scenario_id !== $scenario_id ) {
				return new WP_Error( 'sslms_not_found', 'Node not found in this scenario.' );
			}
			if ( ! isset( $args['options'] ) ) {
				unset( $data['options'] );
			}
			SSLMS_DB::update( 'scenario_nodes', $data, array( 'id' => $node_id ) );
			$id = $node_id;
		} else {
			$data['is_start'] = empty( self::get_nodes( $scenario_id ) ) ? 1 : (int) ! empty( $args['is_start'] );
			$id = SSLMS_DB::insert( 'scenario_nodes', $data );
		}
		if ( ! empty( $args['is_start'] ) && $id ) {
			global $wpdb;
			$t = SSLMS_DB::table( 'scenario_nodes' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET is_start = 0 WHERE scenario_id = %d", $scenario_id ) );
			SSLMS_DB::update( 'scenario_nodes', array( 'is_start' => 1 ), array( 'id' => $id ) );
		}
		return $id;
	}

	public static function delete_node( int $node_id ) {
		$node = self::get_node( $node_id );
		if ( ! $node ) {
			return new WP_Error( 'sslms_not_found', 'Node not found.' );
		}
		$scenario = self::get_scenario( (int) $node->scenario_id );
		if ( $scenario && 'published' === $scenario->status ) {
			return new WP_Error( 'sslms_locked', 'Unpublish the scenario before editing its structure.' );
		}
		return SSLMS_DB::delete( 'scenario_nodes', array( 'id' => $node_id ) );
	}

	/** Structural checks before publishing. Returns list of problems. */
	public static function validate_graph( int $scenario_id ): array {
		$nodes    = self::get_nodes( $scenario_id );
		$problems = array();
		if ( ! $nodes ) {
			return array( 'The scenario has no nodes.' );
		}
		$ids    = array();
		$starts = 0;
		$ends   = 0;
		foreach ( $nodes as $node ) {
			$ids[ (int) $node->id ] = $node;
			if ( $node->is_start ) {
				$starts++;
			}
			if ( 'end' === $node->node_type ) {
				$ends++;
			}
		}
		if ( 1 !== $starts ) {
			$problems[] = 'Exactly one start node is required (found ' . $starts . ').';
		}
		if ( $ends < 1 ) {
			$problems[] = 'At least one end (debrief) node is required.';
		}
		foreach ( $nodes as $node ) {
			if ( 'end' === $node->node_type ) {
				continue;
			}
			if ( ! $node->options ) {
				$problems[] = 'Node "' . ( $node->title ?: ( '#' . $node->id ) ) . '" has no options.';
				continue;
			}
			foreach ( $node->options as $option ) {
				$next = (int) ( $option['next'] ?? 0 );
				if ( ! $next || ! isset( $ids[ $next ] ) ) {
					$problems[] = 'Option "' . $option['label'] . '" on node "' . ( $node->title ?: ( '#' . $node->id ) ) . '" does not lead to a valid node.';
				}
			}
		}
		return $problems;
	}

	/* ---------------------------------------------------------------
	 * JSON import (AI-assisted authoring entry point; creates a DRAFT)
	 * ------------------------------------------------------------- */

	/**
	 * Import a whole scenario from JSON:
	 * { "title", "discipline", "description", "pass_pct",
	 *   "nodes": [ { "key", "type": info|decision|end, "title", "body",
	 *                "debrief", "start": bool,
	 *                "options": [ {"label","next": "<key>","marks","feedback"} ] } ] }
	 * Node cross-references use string keys, resolved to ids after insert.
	 * Always lands as a draft for human clinical review before publishing.
	 */
	public static function import_json( string $json, int $by ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['title'] ) || empty( $data['nodes'] ) || ! is_array( $data['nodes'] ) ) {
			return new WP_Error( 'sslms_invalid_json', 'JSON must contain at least "title" and a non-empty "nodes" array.' );
		}
		$keys = array();
		foreach ( $data['nodes'] as $i => $node ) {
			if ( empty( $node['key'] ) || ! is_string( $node['key'] ) ) {
				return new WP_Error( 'sslms_invalid_json', 'nodes[' . $i . '] is missing a string "key".' );
			}
			if ( isset( $keys[ $node['key'] ] ) ) {
				return new WP_Error( 'sslms_invalid_json', 'Duplicate node key "' . $node['key'] . '".' );
			}
			$keys[ $node['key'] ] = true;
			foreach ( (array) ( $node['options'] ?? array() ) as $j => $option ) {
				if ( empty( $option['next'] ) ) {
					return new WP_Error( 'sslms_invalid_json', 'nodes[' . $i . '].options[' . $j . '] is missing "next".' );
				}
			}
		}
		foreach ( $data['nodes'] as $i => $node ) {
			foreach ( (array) ( $node['options'] ?? array() ) as $j => $option ) {
				if ( ! isset( $keys[ $option['next'] ] ) ) {
					return new WP_Error( 'sslms_invalid_json', 'nodes[' . $i . '].options[' . $j . '] points to unknown key "' . $option['next'] . '".' );
				}
			}
		}

		$scenario_id = self::create_scenario( array(
			'title'       => sanitize_text_field( $data['title'] ),
			'discipline'  => sanitize_text_field( (string) ( $data['discipline'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'pass_pct'    => (float) ( $data['pass_pct'] ?? 70 ),
		), $by );
		if ( is_wp_error( $scenario_id ) ) {
			return $scenario_id;
		}

		// Two passes: insert nodes, then wire options via key → id map.
		$id_by_key = array();
		foreach ( $data['nodes'] as $node ) {
			$id_by_key[ $node['key'] ] = self::save_node( $scenario_id, array(
				'node_type' => in_array( $node['type'] ?? '', self::NODE_TYPES, true ) ? $node['type'] : 'decision',
				'title'     => sanitize_text_field( (string) ( $node['title'] ?? '' ) ),
				'body'      => wp_kses_post( (string) ( $node['body'] ?? '' ) ),
				'debrief'   => sanitize_textarea_field( (string) ( $node['debrief'] ?? '' ) ),
				'is_start'  => ! empty( $node['start'] ),
			) );
		}
		foreach ( $data['nodes'] as $node ) {
			$options = array();
			foreach ( (array) ( $node['options'] ?? array() ) as $option ) {
				$options[] = array(
					'label'    => (string) ( $option['label'] ?? '' ),
					'next'     => (int) ( $id_by_key[ $option['next'] ] ?? 0 ),
					'marks'    => (float) ( $option['marks'] ?? 0 ),
					'feedback' => (string) ( $option['feedback'] ?? '' ),
				);
			}
			if ( $options ) {
				self::save_node( $scenario_id, array(
					'node_type' => in_array( $node['type'] ?? '', self::NODE_TYPES, true ) ? $node['type'] : 'decision',
					'title'     => sanitize_text_field( (string) ( $node['title'] ?? '' ) ),
					'body'      => wp_kses_post( (string) ( $node['body'] ?? '' ) ),
					'debrief'   => sanitize_textarea_field( (string) ( $node['debrief'] ?? '' ) ),
					'options'   => $options,
				), (int) $id_by_key[ $node['key'] ] );
			}
		}
		SSLMS_Audit::log( 'scenario_imported', 'scenario', $scenario_id, count( $data['nodes'] ) . ' nodes imported as draft' );
		return $scenario_id;
	}

	/* ---------------------------------------------------------------
	 * Attempts (learner runtime)
	 * ------------------------------------------------------------- */

	public static function get_attempt( int $id ): ?object {
		$attempt = SSLMS_DB::get_row( 'scenario_attempts', $id );
		if ( $attempt ) {
			$attempt->path = $attempt->path ? ( json_decode( $attempt->path, true ) ?: array() ) : array();
		}
		return $attempt;
	}

	public static function start_attempt( int $scenario_id, int $user_id ) {
		$scenario = self::get_scenario( $scenario_id );
		if ( ! $scenario || 'published' !== $scenario->status ) {
			return new WP_Error( 'sslms_not_found', 'Scenario not available.' );
		}
		// Resume an open attempt rather than stacking new ones.
		global $wpdb;
		$t    = SSLMS_DB::table( 'scenario_attempts' );
		$open = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE scenario_id = %d AND user_id = %d AND completed_at IS NULL LIMIT 1",
			$scenario_id, $user_id
		) );
		if ( $open ) {
			return (int) $open;
		}
		$start = self::start_node( $scenario_id );
		if ( ! $start ) {
			return new WP_Error( 'sslms_invalid_graph', 'Scenario has no start node.' );
		}
		return SSLMS_DB::insert( 'scenario_attempts', array(
			'scenario_id'     => $scenario_id,
			'user_id'         => $user_id,
			'current_node_id' => (int) $start->id,
			'path'            => wp_json_encode( array() ),
			'started_at'      => SSLMS_DB::now(),
		) );
	}

	/**
	 * Record the learner's choice on the current node and advance.
	 * Earned = chosen option's marks; denominator = best option's marks at
	 * each visited decision (info-node edges usually carry 0/0).
	 */
	public static function choose( int $attempt_id, int $option_index, int $user_id ) {
		$attempt = self::get_attempt( $attempt_id );
		if ( ! $attempt || (int) $attempt->user_id !== $user_id ) {
			return new WP_Error( 'sslms_not_found', 'Attempt not found.' );
		}
		if ( $attempt->completed_at ) {
			return new WP_Error( 'sslms_locked', 'This attempt is complete.' );
		}
		$node = self::get_node( (int) $attempt->current_node_id );
		if ( ! $node || 'end' === $node->node_type ) {
			return new WP_Error( 'sslms_invalid_state', 'Nothing to choose here.' );
		}
		if ( ! isset( $node->options[ $option_index ] ) ) {
			return new WP_Error( 'sslms_invalid_option', 'Invalid option.' );
		}
		$option = $node->options[ $option_index ];
		$best   = 0.0;
		foreach ( $node->options as $o ) {
			$best = max( $best, (float) ( $o['marks'] ?? 0 ) );
		}
		$path   = $attempt->path;
		$path[] = array(
			'node_id'  => (int) $node->id,
			'node'     => $node->title,
			'choice'   => $option['label'],
			'marks'    => (float) $option['marks'],
			'best'     => $best,
			'feedback' => $option['feedback'],
		);
		$next_id = (int) $option['next'];
		$next    = self::get_node( $next_id );
		if ( ! $next || (int) $next->scenario_id !== (int) $attempt->scenario_id ) {
			return new WP_Error( 'sslms_invalid_graph', 'This path leads nowhere — report to your educator.' );
		}
		$data = array(
			'current_node_id' => $next_id,
			'path'            => wp_json_encode( $path ),
			'score'           => (float) $attempt->score + (float) $option['marks'],
			'max_score'       => (float) $attempt->max_score + $best,
		);
		if ( 'end' === $next->node_type ) {
			$scenario          = self::get_scenario( (int) $attempt->scenario_id );
			$pct               = $data['max_score'] > 0 ? round( 100 * $data['score'] / $data['max_score'], 2 ) : 100.0;
			$data['score_pct'] = $pct;
			$data['passed']    = (int) ( $pct >= (float) $scenario->pass_pct );
			$data['completed_at'] = SSLMS_DB::now();
			SSLMS_Audit::log( 'scenario_completed', 'scenario', (int) $attempt->scenario_id,
				'Attempt ' . $attempt_id . ' by user ' . $user_id . ': ' . $pct . '% ' . ( $data['passed'] ? 'pass' : 'fail' ) );
		}
		SSLMS_DB::update( 'scenario_attempts', $data, array( 'id' => $attempt_id ) );
		return self::get_attempt( $attempt_id );
	}

	public static function attempts_for_user( int $user_id ): array {
		global $wpdb;
		$t = SSLMS_DB::table( 'scenario_attempts' );
		$s = SSLMS_DB::table( 'scenarios' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT a.*, s.title AS scenario_title FROM {$t} a
			 JOIN {$s} s ON s.id = a.scenario_id
			 WHERE a.user_id = %d ORDER BY a.started_at DESC", $user_id
		) );
	}
}
