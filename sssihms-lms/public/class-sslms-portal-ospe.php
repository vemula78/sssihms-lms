<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [sslms_my_ospe] — Phase 3c portal page. Examiners get the exam-day
 * mobile scoring view: only their station, one candidate at a time,
 * per-station saves (a dropped connection loses nothing). Learners see
 * their published results.
 */
class SSLMS_Portal_OSPE {

	public static function init(): void {
		add_shortcode( 'sslms_my_ospe', array( __CLASS__, 'shortcode' ) );
		add_filter( 'sslms_dashboard_cards', array( __CLASS__, 'dashboard_cards' ) );
	}

	public static function shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return SSLMS_Portal::wrap( 'OSPE', '' );
		}
		$uid        = get_current_user_id();
		$station_id = isset( $_GET['station'] ) ? absint( $_GET['station'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html       = '';

		if ( $station_id ) {
			$html = self::render_station_scoring( $station_id, $uid );
		} else {
			if ( current_user_can( 'sslms_signoff_checklists' ) || current_user_can( 'sslms_manage' ) ) {
				$html .= self::render_examiner_worklist( $uid );
			}
			if ( current_user_can( 'sslms_learn' ) ) {
				$html .= self::render_my_results( $uid );
			}
			if ( '' === $html ) {
				$html = '<p>No OSPE activity to show.</p>';
			}
		}
		return SSLMS_Portal::wrap( 'OSPE', $html );
	}

	private static function render_examiner_worklist( int $uid ): string {
		$stations = SSLMS_OSPE::stations_for_examiner( $uid );
		if ( ! $stations ) {
			return '';
		}
		$html = '<section><h2>My stations</h2>';
		foreach ( $stations as $s ) {
			$url   = esc_url( SSLMS_Portal::page_url( 'my_ospe', array( 'station' => $s->id ) ) );
			$html .= '<div class="sslms-card">';
			$html .= '<h3>Station ' . (int) $s->station_no . ': ' . esc_html( $s->title ) . '</h3>';
			$html .= '<p>' . esc_html( $s->exam_title ) . ' &middot; ' . esc_html( SSLMS_DB::fmt_date( $s->exam_date ) )
				. ' &middot; max ' . esc_html( (float) $s->max_marks ) . ' marks &middot; ' . (int) $s->duration_min . ' min</p>';
			$html .= '<a class="sslms-btn" href="' . $url . '">Score candidates</a>';
			$html .= '</div>';
		}
		$html .= '</section>';
		return $html;
	}

	private static function render_station_scoring( int $station_id, int $uid ): string {
		$back    = '<p><a href="' . esc_url( SSLMS_Portal::page_url( 'my_ospe' ) ) . '">&larr; Back to OSPE</a></p>';
		$station = SSLMS_OSPE::get_station( $station_id );
		if ( ! $station || ! SSLMS_OSPE::can_score_station( $station, $uid ) ) {
			return '<section>' . $back . '<p>You are not the examiner for this station.</p></section>';
		}
		$exam = SSLMS_OSPE::get_exam( (int) $station->exam_id );
		if ( ! $exam || 'published' === $exam->status ) {
			return '<section>' . $back . '<p>This exam is published; scores are final.</p></section>';
		}
		$candidates = SSLMS_OSPE::get_candidates( (int) $exam->id );
		$matrix     = SSLMS_OSPE::scores_matrix( (int) $exam->id );
		$rubric     = $station->rubric_id ? SSLMS_Rubrics::get_full( (int) $station->rubric_id ) : null;

		$html  = '<section>' . $back;
		$html .= '<h2>Station ' . (int) $station->station_no . ': ' . esc_html( $station->title ) . '</h2>';
		$html .= '<p>' . esc_html( $exam->title ) . ' &middot; max <strong>' . esc_html( (float) $station->max_marks ) . '</strong> marks</p>';

		if ( $rubric ) {
			$html .= '<details><summary>Scoresheet reference: ' . esc_html( $rubric->title ) . '</summary><ul>';
			foreach ( $rubric->criteria as $criterion ) {
				$html .= '<li>' . esc_html( $criterion->criterion_text );
				if ( $criterion->levels ) {
					$parts = array();
					foreach ( $criterion->levels as $level ) {
						$parts[] = $level->label . ' (' . (float) $level->marks . ')';
					}
					$html .= ' — ' . esc_html( implode( ' / ', $parts ) );
				}
				$html .= '</li>';
			}
			$html .= '</ul></details>';
		}

		foreach ( $candidates as $cand ) {
			if ( 'absent' === $cand->status ) {
				continue;
			}
			$user  = get_userdata( (int) $cand->user_id );
			$score = $matrix[ (int) $station->id ][ (int) $cand->id ] ?? null;
			$html .= '<fieldset><legend>#' . (int) $cand->candidate_no . ' — ' . esc_html( $user ? $user->display_name : ( 'User ' . $cand->user_id ) ) . '</legend>';
			$html .= '<p>' . ( $score
				? '<span class="sslms-badge sslms-badge--ok">scored: ' . esc_html( (float) $score->marks ) . '</span>'
				: '<span class="sslms-badge sslms-badge--muted">not scored</span>' ) . '</p>';
			$html .= '<form data-sslms-endpoint="ospe/stations/' . (int) $station->id . '/score" data-sslms-method="POST">';
			$html .= '<input type="hidden" name="candidate_id" value="' . (int) $cand->id . '">';
			$html .= '<label>Marks (0–' . esc_attr( (float) $station->max_marks ) . ')<input type="number" name="marks" min="0" max="' . esc_attr( (float) $station->max_marks ) . '" step="0.5" value="' . esc_attr( $score ? (float) $score->marks : '' ) . '" required></label>';
			$html .= '<label>Note<textarea name="note" rows="2">' . esc_textarea( $score ? (string) $score->note : '' ) . '</textarea></label>';
			$html .= '<button type="submit" class="sslms-btn">Save score</button>';
			$html .= '</form></fieldset>';
		}
		$html .= '</section>';
		return $html;
	}

	private static function render_my_results( int $uid ): string {
		$results = SSLMS_OSPE::results_for_user( $uid );
		$html    = '<section><h2>My OSPE results</h2>';
		if ( ! $results ) {
			$html .= '<p>No published OSPE results yet.</p>';
		}
		foreach ( $results as $r ) {
			$html .= '<div class="sslms-card">';
			$html .= '<h3>' . esc_html( $r->exam_title ) . '</h3>';
			$html .= '<p>' . esc_html( SSLMS_DB::fmt_date( $r->exam_date ) ) . '</p>';
			if ( 'absent' === $r->status ) {
				$html .= '<p><span class="sslms-badge sslms-badge--muted">absent</span></p>';
			} else {
				$class = 'pass' === $r->outcome ? 'ok' : 'bad';
				$html .= '<p><span class="sslms-badge sslms-badge--' . $class . '">' . esc_html( $r->outcome )
					. ' &middot; ' . esc_html( round( (float) $r->total_pct, 1 ) . '%' ) . '</span>'
					. ' <small>(pass mark ' . esc_html( round( (float) $r->pass_pct, 1 ) . '%' ) . ')</small></p>';
			}
			$html .= '</div>';
		}
		$html .= '</section>';
		return $html;
	}

	public static function dashboard_cards( array $cards ): array {
		if ( ! is_user_logged_in() ) {
			return $cards;
		}
		$uid = get_current_user_id();
		if ( current_user_can( 'sslms_signoff_checklists' ) || current_user_can( 'sslms_manage' ) ) {
			$stations = count( SSLMS_OSPE::stations_for_examiner( $uid ) );
			if ( $stations ) {
				$cards[] = array(
					'title' => 'OSPE stations to examine',
					'html'  => '<p style="font-size:1.8rem;font-weight:700;margin:0">' . (int) $stations . '</p><p><a href="' . esc_url( SSLMS_Portal::page_url( 'my_ospe' ) ) . '">Open scoring</a></p>',
				);
			}
		}
		return $cards;
	}
}
SSLMS_Portal_OSPE::init();
