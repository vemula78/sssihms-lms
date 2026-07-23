<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [sslms_my_assessments] — Phase 3a portal page. Learners see their own
 * assessment records (signed feedback is the point); assessors get a
 * phone-friendly workflow: open a new assessment on a linked learner,
 * score each criterion against its levels, then sign.
 */
class SSLMS_Portal_Assessments {

	const ROLE_LABELS = array(
		'self'      => 'Self-assessment',
		'peer'      => 'Peer',
		'preceptor' => 'Preceptor',
		'evaluator' => 'Evaluator',
		'mentor'    => 'Mentor',
		'educator'  => 'Educator',
	);

	public static function init(): void {
		add_shortcode( 'sslms_my_assessments', array( __CLASS__, 'shortcode' ) );
		add_filter( 'sslms_dashboard_cards', array( __CLASS__, 'dashboard_cards' ) );
	}

	public static function shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return SSLMS_Portal::wrap( 'My Assessments', '' );
		}
		$uid       = get_current_user_id();
		$record_id = isset( $_GET['record'] ) ? absint( $_GET['record'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html      = '';

		if ( $record_id ) {
			$html = self::render_record( $record_id, $uid );
		} else {
			if ( current_user_can( 'sslms_learn' ) ) {
				$html .= self::render_mine( $uid );
			}
			$html .= self::render_assessor_panel( $uid );
			if ( '' === $html ) {
				$html = '<p>No assessments to show.</p>';
			}
		}
		return SSLMS_Portal::wrap( 'My Assessments', $html );
	}

	/* Learner's own evidence list. */
	private static function render_mine( int $uid ): string {
		$records = SSLMS_Rubrics::records_for_learner( $uid );
		$html    = '<section><h2>My assessment records</h2>';
		if ( ! $records ) {
			$html .= '<p>No assessments recorded for you yet.</p>';
		}
		foreach ( $records as $r ) {
			if ( 'draft' === $r->status && (int) $r->assessor_id !== $uid ) {
				continue; // Drafts by other assessors stay theirs until signed.
			}
			$url   = esc_url( SSLMS_Portal::page_url( 'my_assessments', array( 'record' => $r->id ) ) );
			$html .= '<div class="sslms-card">';
			$html .= '<h3>' . esc_html( $r->rubric_title ) . '</h3>';
			$html .= '<p>' . esc_html( self::ROLE_LABELS[ $r->assessor_role ] ?? $r->assessor_role )
				. ( $r->context ? ' &middot; ' . esc_html( $r->context ) : '' )
				. ' &middot; ' . esc_html( SSLMS_DB::fmt_date( $r->created_at ) ) . '</p>';
			$html .= '<p>' . self::outcome_badge( $r ) . ' <a class="sslms-btn sslms-btn--ghost" href="' . $url . '">View</a></p>';
			$html .= '</div>';
		}
		$html .= '</section>';
		return $html;
	}

	/* Assessor tools: drafts to finish + open a new assessment. */
	private static function render_assessor_panel( int $uid ): string {
		$learner_ids = SSLMS_Rubrics::learners_in_scope( $uid );
		$can_self    = current_user_can( 'sslms_learn' );
		if ( ! $learner_ids && ! current_user_can( 'sslms_manage' ) && ! $can_self ) {
			return '';
		}

		$html   = '';
		$drafts = SSLMS_Rubrics::records_by_assessor( $uid, 'draft' );
		if ( $drafts ) {
			$html .= '<section><h2>Assessments in progress</h2>';
			foreach ( $drafts as $r ) {
				$learner = get_userdata( (int) $r->learner_id );
				$url     = esc_url( SSLMS_Portal::page_url( 'my_assessments', array( 'record' => $r->id ) ) );
				$html   .= '<div class="sslms-card">';
				$html   .= '<h3>' . esc_html( $r->rubric_title ) . '</h3>';
				$html   .= '<p>' . esc_html( $learner ? $learner->display_name : ( 'User ' . $r->learner_id ) )
					. ' &middot; ' . esc_html( SSLMS_DB::fmt_date( $r->created_at ) ) . '</p>';
				$html   .= '<a class="sslms-btn" href="' . $url . '">Continue scoring</a>';
				$html   .= '</div>';
			}
			$html .= '</section>';
		}

		$rubrics = SSLMS_Rubrics::list_rubrics( true );
		if ( ! $rubrics ) {
			return $html;
		}
		$html .= '<section><h2>Start a new assessment</h2>';
		$html .= '<form data-sslms-endpoint="assessments" data-sslms-method="POST">';
		$html .= '<label>Assessment form<select name="rubric_id" required><option value="">— choose —</option>';
		foreach ( $rubrics as $r ) {
			$html .= '<option value="' . (int) $r->id . '">' . esc_html( $r->title ) . '</option>';
		}
		$html .= '</select></label>';
		$html .= '<label>Learner<select name="learner_id" required><option value="">— choose —</option>';
		if ( $can_self ) {
			$html .= '<option value="' . (int) $uid . '">Myself (self-assessment)</option>';
		}
		if ( current_user_can( 'sslms_manage' ) ) {
			$learner_ids = array();
			foreach ( get_users( array( 'capability' => array( 'sslms_learn' ), 'orderby' => 'display_name' ) ) as $u ) {
				$learner_ids[] = (int) $u->ID;
			}
		}
		foreach ( $learner_ids as $lid ) {
			$lid = (int) $lid;
			if ( $lid === $uid ) {
				continue;
			}
			$user  = get_userdata( $lid );
			$html .= '<option value="' . $lid . '">' . esc_html( $user ? $user->display_name : ( 'User ' . $lid ) ) . '</option>';
		}
		$html .= '</select></label>';
		$html .= '<label>Context (rotation, encounter, ward…)<input type="text" name="context"></label>';
		$html .= '<button type="submit" class="sslms-btn">Open assessment</button>';
		$html .= '</form></section>';
		return $html;
	}

	/* One record: read view, or scoring view for its assessor while draft. */
	private static function render_record( int $record_id, int $uid ): string {
		$back   = '<p><a href="' . esc_url( SSLMS_Portal::page_url( 'my_assessments' ) ) . '">&larr; Back to assessments</a></p>';
		$record = SSLMS_Rubrics::get_record( $record_id );
		if ( ! $record || ! SSLMS_Rubrics::user_can_view_record( $record, $uid ) ) {
			return '<section>' . $back . '<p>Assessment not found.</p></section>';
		}
		$detail   = SSLMS_Rubrics::record_detail( $record_id );
		$learner  = get_userdata( (int) $detail->learner_id );
		$assessor = get_userdata( (int) $detail->assessor_id );
		$editable = 'draft' === $detail->status && (int) $detail->assessor_id === $uid;

		$html  = '<section>' . $back;
		$html .= '<h2>' . esc_html( $detail->rubric_title ) . '</h2>';
		$html .= '<p>Learner: <strong>' . esc_html( $learner ? $learner->display_name : ( 'User ' . $detail->learner_id ) ) . '</strong>'
			. ' &middot; Assessor: ' . esc_html( $assessor ? $assessor->display_name : ( 'User ' . $detail->assessor_id ) )
			. ' (' . esc_html( self::ROLE_LABELS[ $detail->assessor_role ] ?? $detail->assessor_role ) . ')'
			. ( $detail->context ? ' &middot; ' . esc_html( $detail->context ) : '' ) . '</p>';
		$html .= '<p>' . self::outcome_badge( $detail )
			. ( 'signed' === $detail->status
				? ' <span class="sslms-badge sslms-badge--ok">signed ' . esc_html( SSLMS_DB::fmt_date( $detail->signed_at ) ) . '</span>'
				: ' <span class="sslms-badge sslms-badge--warn">draft</span>' ) . '</p>';

		foreach ( $detail->criteria as $c ) {
			$html .= '<fieldset><legend>' . esc_html( $c->criterion_text )
				. ( $c->is_critical ? ' <span class="sslms-badge sslms-badge--bad">critical</span>' : '' ) . '</legend>';
			if ( $editable ) {
				$html .= '<form data-sslms-endpoint="assessments/' . (int) $record_id . '/score" data-sslms-method="POST">';
				$html .= '<input type="hidden" name="criterion_id" value="' . (int) $c->criterion_id . '">';
				$html .= '<label>Level<select name="level_id" required><option value="">— choose —</option>';
				foreach ( $c->levels as $level ) {
					$html .= '<option value="' . (int) $level->id . '" ' . selected( $c->level_id, (int) $level->id, false ) . '>'
						. esc_html( $level->label . ( '' !== trim( (string) $level->descriptor ) ? ' — ' . $level->descriptor : '' ) . ' (' . (float) $level->marks . ')' )
						. '</option>';
				}
				$html .= '</select></label>';
				$html .= '<label>Note<textarea name="note" rows="2">' . esc_textarea( $c->note ) . '</textarea></label>';
				$html .= '<button type="submit" class="sslms-btn">Save score</button>';
				$html .= '</form>';
			} else {
				$label = '';
				foreach ( $c->levels as $level ) {
					if ( (int) $level->id === $c->level_id ) {
						$label = $level->label;
					}
				}
				$html .= '<p>' . ( $label
					? '<span class="sslms-badge sslms-badge--ok">' . esc_html( $label ) . '</span> (' . esc_html( (float) $c->marks ) . ' marks)'
					: '<span class="sslms-badge sslms-badge--muted">unscored</span>' ) . '</p>';
				if ( $c->note ) {
					$html .= '<p>' . esc_html( $c->note ) . '</p>';
				}
			}
			$html .= '</fieldset>';
		}

		if ( $editable ) {
			$html .= '<fieldset><legend>Sign &amp; finish</legend>';
			$html .= '<p>Signing locks this record permanently (NABH). Score every criterion first.</p>';
			$html .= '<form data-sslms-endpoint="assessments/' . (int) $record_id . '/sign" data-sslms-method="POST">';
			$html .= '<label>Overall feedback to the learner<textarea name="feedback" rows="3">' . esc_textarea( (string) $detail->feedback ) . '</textarea></label>';
			$html .= '<button type="submit" class="sslms-btn">Sign assessment</button>';
			$html .= '</form></fieldset>';
		} elseif ( $detail->feedback ) {
			$html .= '<fieldset><legend>Feedback</legend><p>' . esc_html( $detail->feedback ) . '</p></fieldset>';
		}
		$html .= '</section>';
		return $html;
	}

	private static function outcome_badge( object $r ): string {
		if ( 'pass' === $r->outcome ) {
			return '<span class="sslms-badge sslms-badge--ok">pass &middot; ' . esc_html( round( (float) $r->score_pct, 1 ) . '%' ) . '</span>';
		}
		if ( 'fail' === $r->outcome ) {
			return '<span class="sslms-badge sslms-badge--bad">fail'
				. ( ! empty( $r->critical_failed ) ? ' (critical criterion)' : '' )
				. ( null !== $r->score_pct ? ' &middot; ' . esc_html( round( (float) $r->score_pct, 1 ) . '%' ) : '' ) . '</span>';
		}
		return '<span class="sslms-badge sslms-badge--muted">not yet scored</span>';
	}

	public static function dashboard_cards( array $cards ): array {
		if ( ! is_user_logged_in() ) {
			return $cards;
		}
		$uid    = get_current_user_id();
		$drafts = count( SSLMS_Rubrics::records_by_assessor( $uid, 'draft' ) );
		if ( $drafts ) {
			$cards[] = array(
				'title' => 'Assessments to finish',
				'html'  => '<p style="font-size:1.8rem;font-weight:700;margin:0">' . (int) $drafts . '</p><p><a href="' . esc_url( SSLMS_Portal::page_url( 'my_assessments' ) ) . '">Continue scoring</a></p>',
			);
		}
		if ( current_user_can( 'sslms_learn' ) ) {
			$signed = count( SSLMS_Rubrics::records_for_learner( $uid, true ) );
			$cards[] = array(
				'title' => 'My assessments',
				'html'  => '<p style="font-size:1.8rem;font-weight:700;margin:0">' . (int) $signed . '</p><p><a href="' . esc_url( SSLMS_Portal::page_url( 'my_assessments' ) ) . '">View assessment records</a></p>',
			);
		}
		return $cards;
	}
}
SSLMS_Portal_Assessments::init();
