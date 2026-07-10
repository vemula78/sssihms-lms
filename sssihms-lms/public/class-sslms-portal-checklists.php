<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [sslms_my_checklists] — learner's own checklist progress, plus (for
 * preceptors/evaluators) a bedside-friendly "Learners to sign" worklist.
 * Mobile-first: this is used on phones at the bedside.
 */
class SSLMS_Portal_Checklists {

	const RATING_LABELS = array(
		'needs_practice'   => 'Needs practice',
		'with_supervision' => 'With supervision',
		'competent'        => 'Competent',
	);

	public static function init(): void {
		add_shortcode( 'sslms_my_checklists', array( __CLASS__, 'shortcode' ) );
		add_filter( 'sslms_dashboard_cards', array( __CLASS__, 'dashboard_cards' ) );
	}

	public static function shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return SSLMS_Portal::wrap( 'My Checklists', '' );
		}
		$uid  = get_current_user_id();
		$html = '';

		if ( current_user_can( 'sslms_learn' ) ) {
			$html .= self::render_my_assignments( $uid );
		}

		if ( current_user_can( 'sslms_signoff_checklists' ) ) {
			$assignment_id = isset( $_GET['assignment'] ) ? absint( $_GET['assignment'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $assignment_id ) {
				$html .= self::render_sign_detail( $assignment_id, $uid );
			} else {
				$html .= self::render_learners_to_sign( $uid );
			}
		}

		if ( '' === $html ) {
			$html = '<p>No checklists to show.</p>';
		}
		return SSLMS_Portal::wrap( 'My Checklists', $html );
	}

	private static function render_my_assignments( int $uid ): string {
		$assignments = SSLMS_Checklists::assignments_for_user( $uid );
		$html = '<section><h2>My checklists</h2>';
		if ( ! $assignments ) {
			$html .= '<p>No checklists assigned yet.</p>';
		}
		foreach ( $assignments as $a ) {
			$summary = SSLMS_Checklists::assignment_summary( (int) $a->id );
			$pct     = $summary['total'] ? round( 100 * $summary['competent'] / $summary['total'] ) : 0;
			$html   .= '<div class="sslms-card">';
			$html   .= '<h3>' . esc_html( $a->title ) . '</h3>';
			$html   .= '<p>' . esc_html( $a->discipline ) . ' &middot; ' . (int) $summary['competent'] . ' / ' . (int) $summary['total'] . ' competent</p>';
			$html   .= '<div class="sslms-progressbar"><span style="width:' . (int) $pct . '%"></span></div>';
			$html   .= $a->locked
				? '<p><span class="sslms-badge sslms-badge--ok">Completed &amp; locked</span></p>'
				: '<p><span class="sslms-badge sslms-badge--warn">In progress</span></p>';
			$html   .= '<ul>';
			foreach ( SSLMS_Checklists::items_status( (int) $a->id ) as $item ) {
				$html .= '<li>' . esc_html( $item->item_text ) . ' — ' . self::rating_badge( $item->rating ) . '</li>';
			}
			$html   .= '</ul></div>';
		}
		$html .= '</section>';
		return $html;
	}

	private static function render_learners_to_sign( int $signer_id ): string {
		$rows = SSLMS_Checklists::learners_to_sign( $signer_id );
		$html = '<section><h2>Learners to sign</h2>';
		if ( ! $rows ) {
			$html .= '<p>No open checklists assigned to your learners.</p>';
			$html .= '</section>';
			return $html;
		}
		foreach ( $rows as $row ) {
			$user    = get_userdata( (int) $row->user_id );
			$summary = SSLMS_Checklists::assignment_summary( (int) $row->id );
			$url     = esc_url( SSLMS_Portal::page_url( 'my_checklists', array( 'assignment' => $row->id ) ) );
			$html   .= '<div class="sslms-card">';
			$html   .= '<h3>' . esc_html( $user ? $user->display_name : ( 'User ' . $row->user_id ) ) . '</h3>';
			$html   .= '<p>' . esc_html( $row->title ) . ' &middot; ' . (int) $summary['competent'] . ' / ' . (int) $summary['total'] . ' competent</p>';
			$html   .= '<a class="sslms-btn" href="' . $url . '">Rate items</a>';
			$html   .= '</div>';
		}
		$html .= '</section>';
		return $html;
	}

	private static function render_sign_detail( int $assignment_id, int $signer_id ): string {
		$assignment = SSLMS_Checklists::get_assignment( $assignment_id );
		$back = esc_url( SSLMS_Portal::page_url( 'my_checklists' ) );
		if ( ! $assignment || ! SSLMS_Checklists::signer_in_scope( (int) $assignment->user_id, $signer_id ) ) {
			return '<section><p><a href="' . $back . '">&larr; Back</a></p><p>You are not permitted to sign this checklist.</p></section>';
		}
		$user      = get_userdata( (int) $assignment->user_id );
		$checklist = SSLMS_Checklists::get_checklist( (int) $assignment->checklist_id );
		$html  = '<section><p><a href="' . $back . '">&larr; Back to learners to sign</a></p>';
		$html .= '<h2>' . esc_html( $checklist ? $checklist->title : '' ) . '</h2>';
		$html .= '<p>Learner: <strong>' . esc_html( $user ? $user->display_name : ( 'User ' . $assignment->user_id ) ) . '</strong></p>';

		if ( $assignment->locked ) {
			$html .= '<p><span class="sslms-badge sslms-badge--ok">Completed &amp; locked</span> — no further edits.</p></section>';
			return $html;
		}

		foreach ( SSLMS_Checklists::items_status( $assignment_id ) as $item ) {
			$html .= '<fieldset><legend>' . esc_html( $item->item_text ) . '</legend>';
			$html .= '<p>Current: ' . self::rating_badge( $item->rating ) . '</p>';
			$html .= '<form data-sslms-endpoint="checklists/assignments/' . (int) $assignment_id . '/sign" data-sslms-method="POST">';
			$html .= '<input type="hidden" name="item_id" value="' . (int) $item->item_id . '">';
			$html .= '<label>Rating<select name="rating" required>';
			foreach ( self::RATING_LABELS as $val => $label ) {
				$html .= '<option value="' . esc_attr( $val ) . '" ' . selected( $item->rating, $val, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$html .= '</select></label>';
			$html .= '<label>Note<textarea name="note" rows="2">' . esc_textarea( $item->note ) . '</textarea></label>';
			$html .= '<button type="submit" class="sslms-btn">Sign</button>';
			$html .= '</form></fieldset>';
		}
		$html .= '</section>';
		return $html;
	}

	private static function rating_badge( ?string $rating ): string {
		if ( ! $rating ) {
			return '<span class="sslms-badge sslms-badge--muted">unrated</span>';
		}
		$class = 'competent' === $rating ? 'ok' : ( 'with_supervision' === $rating ? 'warn' : 'bad' );
		return '<span class="sslms-badge sslms-badge--' . esc_attr( $class ) . '">' . esc_html( self::RATING_LABELS[ $rating ] ?? $rating ) . '</span>';
	}

	/** Dashboard cards (SPEC F6.4 / cross-cutting F12): pending sign-offs / own open checklists. */
	public static function dashboard_cards( array $cards ): array {
		if ( ! is_user_logged_in() ) {
			return $cards;
		}
		$uid = get_current_user_id();
		if ( current_user_can( 'sslms_signoff_checklists' ) ) {
			$count = count( SSLMS_Checklists::learners_to_sign( $uid ) );
			$cards[] = array(
				'title' => 'Pending sign-offs',
				'html'  => '<p style="font-size:1.8rem;font-weight:700;margin:0">' . (int) $count . '</p><p><a href="' . esc_url( SSLMS_Portal::page_url( 'my_checklists' ) ) . '">Review learners to sign</a></p>',
			);
		}
		if ( current_user_can( 'sslms_learn' ) ) {
			$open = 0;
			foreach ( SSLMS_Checklists::assignments_for_user( $uid ) as $a ) {
				if ( ! $a->locked ) {
					$open++;
				}
			}
			$cards[] = array(
				'title' => 'My open checklists',
				'html'  => '<p style="font-size:1.8rem;font-weight:700;margin:0">' . (int) $open . '</p><p><a href="' . esc_url( SSLMS_Portal::page_url( 'my_checklists' ) ) . '">View my checklists</a></p>',
			);
		}
		return $cards;
	}
}
SSLMS_Portal_Checklists::init();
