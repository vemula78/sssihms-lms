<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [sslms_scenarios] — Phase 3e learner portal: list published scenarios,
 * play through decision by decision (server-rendered, one POST per choice),
 * structured debrief with per-decision feedback at the end.
 */
class SSLMS_Portal_Scenarios {

	public static function init(): void {
		add_shortcode( 'sslms_scenarios', array( __CLASS__, 'shortcode' ) );
		add_filter( 'sslms_dashboard_cards', array( __CLASS__, 'dashboard_cards' ) );
	}

	public static function shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return SSLMS_Portal::wrap( 'Practice Scenarios', '' );
		}
		if ( ! current_user_can( 'sslms_learn' ) ) {
			return SSLMS_Portal::wrap( 'Practice Scenarios', '<p>Scenarios are available to learners.</p>' );
		}
		$uid        = get_current_user_id();
		$attempt_id = isset( $_GET['attempt'] ) ? absint( $_GET['attempt'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html       = $attempt_id ? self::render_attempt( $attempt_id, $uid ) : self::render_list( $uid );
		return SSLMS_Portal::wrap( 'Practice Scenarios', $html );
	}

	private static function render_list( int $uid ): string {
		$scenarios = SSLMS_Scenarios::list_scenarios( true );
		$attempts  = SSLMS_Scenarios::attempts_for_user( $uid );
		$open_by_scenario = array();
		foreach ( $attempts as $a ) {
			if ( ! $a->completed_at ) {
				$open_by_scenario[ (int) $a->scenario_id ] = (int) $a->id;
			}
		}

		$html = '<section><h2>Available scenarios</h2>';
		if ( ! $scenarios ) {
			$html .= '<p>No scenarios published yet.</p>';
		}
		foreach ( $scenarios as $s ) {
			$html .= '<div class="sslms-card">';
			$html .= '<h3>' . esc_html( $s->title ) . '</h3>';
			if ( $s->description ) {
				$html .= '<p>' . esc_html( $s->description ) . '</p>';
			}
			$html .= '<p><small>Fictional case for practice &middot; pass mark ' . esc_html( round( (float) $s->pass_pct, 1 ) . '%' ) . '</small></p>';
			if ( isset( $open_by_scenario[ (int) $s->id ] ) ) {
				$url   = esc_url( SSLMS_Portal::page_url( 'scenarios', array( 'attempt' => $open_by_scenario[ (int) $s->id ] ) ) );
				$html .= '<a class="sslms-btn" href="' . $url . '">Resume</a>';
			} else {
				$html .= '<form data-sslms-endpoint="scenarios/' . (int) $s->id . '/start" data-sslms-method="POST" style="margin:0">';
				$html .= '<button type="submit" class="sslms-btn">Start</button>';
				$html .= '</form>';
			}
			$html .= '</div>';
		}
		$html .= '</section>';

		$done = array_filter( $attempts, static function ( $a ) { return (bool) $a->completed_at; } );
		if ( $done ) {
			$html .= '<section><h2>My completed attempts</h2>';
			foreach ( $done as $a ) {
				$url   = esc_url( SSLMS_Portal::page_url( 'scenarios', array( 'attempt' => $a->id ) ) );
				$class = $a->passed ? 'ok' : 'bad';
				$html .= '<div class="sslms-card">';
				$html .= '<h3>' . esc_html( $a->scenario_title ) . '</h3>';
				$html .= '<p><span class="sslms-badge sslms-badge--' . $class . '">' . ( $a->passed ? 'pass' : 'fail' ) . ' &middot; ' . esc_html( round( (float) $a->score_pct, 1 ) . '%' ) . '</span> ' . esc_html( SSLMS_DB::fmt_date( $a->completed_at ) ) . '</p>';
				$html .= '<a class="sslms-btn sslms-btn--ghost" href="' . $url . '">Review debrief</a>';
				$html .= '</div>';
			}
			$html .= '</section>';
		}
		return $html;
	}

	private static function render_attempt( int $attempt_id, int $uid ): string {
		$back    = '<p><a href="' . esc_url( SSLMS_Portal::page_url( 'scenarios' ) ) . '">&larr; All scenarios</a></p>';
		$attempt = SSLMS_Scenarios::get_attempt( $attempt_id );
		if ( ! $attempt || (int) $attempt->user_id !== $uid ) {
			return '<section>' . $back . '<p>Attempt not found.</p></section>';
		}
		$scenario = SSLMS_Scenarios::get_scenario( (int) $attempt->scenario_id );
		$node     = SSLMS_Scenarios::get_node( (int) $attempt->current_node_id );
		$html     = '<section>' . $back . '<h2>' . esc_html( $scenario ? $scenario->title : 'Scenario' ) . '</h2>';

		if ( $attempt->completed_at || ( $node && 'end' === $node->node_type ) ) {
			$class = $attempt->passed ? 'ok' : 'bad';
			$html .= '<p><span class="sslms-badge sslms-badge--' . $class . '">' . ( $attempt->passed ? 'pass' : 'fail' )
				. ' &middot; ' . esc_html( round( (float) $attempt->score_pct, 1 ) . '%' ) . '</span></p>';
			if ( $node && 'end' === $node->node_type ) {
				if ( $node->body ) {
					$html .= wp_kses_post( wpautop( $node->body ) );
				}
				if ( $node->debrief ) {
					$html .= '<fieldset><legend>Debrief</legend><p>' . esc_html( $node->debrief ) . '</p></fieldset>';
				}
			}
			$html .= '<fieldset><legend>Your decisions</legend><ol>';
			foreach ( $attempt->path as $step ) {
				$full  = (float) $step['marks'] >= (float) $step['best'];
				$html .= '<li><strong>' . esc_html( $step['node'] ?: 'Decision' ) . ':</strong> ' . esc_html( $step['choice'] )
					. ' <span class="sslms-badge sslms-badge--' . ( $full ? 'ok' : 'warn' ) . '">' . esc_html( (float) $step['marks'] . ' / ' . (float) $step['best'] ) . '</span>'
					. ( $step['feedback'] ? '<br><em>' . esc_html( $step['feedback'] ) . '</em>' : '' ) . '</li>';
			}
			$html .= '</ol></fieldset></section>';
			return $html;
		}

		if ( ! $node ) {
			return $html . '<p>This scenario appears to be broken — please tell your educator.</p></section>';
		}
		if ( $node->title ) {
			$html .= '<h3>' . esc_html( $node->title ) . '</h3>';
		}
		if ( $node->body ) {
			$html .= wp_kses_post( wpautop( $node->body ) );
		}
		$html .= '<fieldset><legend>' . ( 'info' === $node->node_type ? 'Continue' : 'What do you do?' ) . '</legend>';
		foreach ( $node->options as $i => $option ) {
			$html .= '<form data-sslms-endpoint="scenarios/attempts/' . (int) $attempt_id . '/choose" data-sslms-method="POST" style="margin:0 0 .5em">';
			$html .= '<input type="hidden" name="option_index" value="' . (int) $i . '">';
			$html .= '<button type="submit" class="sslms-btn" style="width:100%;text-align:left">' . esc_html( $option['label'] ) . '</button>';
			$html .= '</form>';
		}
		$html .= '</fieldset></section>';
		return $html;
	}

	public static function dashboard_cards( array $cards ): array {
		if ( ! is_user_logged_in() || ! current_user_can( 'sslms_learn' ) ) {
			return $cards;
		}
		$n = count( SSLMS_Scenarios::list_scenarios( true ) );
		if ( $n ) {
			$cards[] = array(
				'title' => 'Practice scenarios',
				'html'  => '<p style="font-size:1.8rem;font-weight:700;margin:0">' . (int) $n . '</p><p><a href="' . esc_url( SSLMS_Portal::page_url( 'scenarios' ) ) . '">Practise a case</a></p>',
			);
		}
		return $cards;
	}
}
SSLMS_Portal_Scenarios::init();
