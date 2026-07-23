<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [sslms_passport] — Phase 3b learner Competency Passport: progress per
 * domain, evidence trail one tap away, printable summary for NABH or
 * appraisals (plain print CSS — the page itself is the printout).
 */
class SSLMS_Portal_Passport {

	const STATE_LABELS = array(
		'not_yet'           => 'Not yet',
		'in_progress'       => 'In progress',
		'evidence_complete' => 'Evidence complete',
	);

	public static function init(): void {
		add_shortcode( 'sslms_passport', array( __CLASS__, 'shortcode' ) );
		add_filter( 'sslms_dashboard_cards', array( __CLASS__, 'dashboard_cards' ) );
	}

	public static function shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return SSLMS_Portal::wrap( 'Competency Passport', '' );
		}
		$uid = get_current_user_id();

		// Educators may view a learner's passport; learners only their own.
		$target = isset( $_GET['learner'] ) ? absint( $_GET['learner'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $target && $target !== $uid && ! current_user_can( 'sslms_manage' ) ) {
			$target = 0;
		}
		$target = $target ?: $uid;

		$passport = SSLMS_Competencies::passport( $target );
		$user     = get_userdata( $target );
		$html     = '';
		if ( $target !== $uid ) {
			$html .= '<p><span class="sslms-badge sslms-badge--warn">Viewing as educator</span> Learner: <strong>' . esc_html( $user ? $user->display_name : ( 'User ' . $target ) ) . '</strong></p>';
		}
		$html .= '<p><button type="button" class="sslms-btn sslms-btn--ghost" onclick="window.print()">Print summary</button></p>';

		if ( ! $passport ) {
			$html .= '<p>No competency framework has been published yet.</p>';
			return SSLMS_Portal::wrap( 'Competency Passport', $html );
		}

		foreach ( $passport as $section ) {
			$html .= '<section><h2>' . esc_html( ( $section->domain->discipline ? $section->domain->discipline . ' — ' : '' ) . $section->domain->title ) . '</h2>';
			foreach ( $section->rows as $row ) {
				$status = $row->status;
				$html  .= '<div class="sslms-card">';
				$html  .= '<h3>' . esc_html( $row->competency->title )
					. ( $row->competency->code ? ' <small>(' . esc_html( $row->competency->code ) . ')</small>' : '' ) . '</h3>';
				$html  .= '<p>' . self::state_badge( $status ) . '</p>';
				if ( $status->total ) {
					$pct   = round( 100 * $status->satisfied / $status->total );
					$html .= '<div class="sslms-progressbar"><span style="width:' . (int) $pct . '%"></span></div>';
					$html .= '<details><summary>Evidence (' . (int) $status->satisfied . ' / ' . (int) $status->total . ')</summary><ul>';
					foreach ( $status->evidence as $ev ) {
						$html .= '<li>' . esc_html( $ev->object_label ) . ' — '
							. ( $ev->satisfied
								? '<span class="sslms-badge sslms-badge--ok">done</span>'
								: '<span class="sslms-badge sslms-badge--muted">pending</span>' )
							. '</li>';
					}
					$html .= '</ul></details>';
				} else {
					$html .= '<p><em>No evidence mapped to this competency yet.</em></p>';
				}
				$html .= '</div>';
			}
			$html .= '</section>';
		}
		return SSLMS_Portal::wrap( 'Competency Passport', $html );
	}

	private static function state_badge( object $status ): string {
		if ( $status->attained ) {
			$signer = get_userdata( (int) $status->attained->signed_by );
			return '<span class="sslms-badge sslms-badge--ok">Attained: ' . esc_html( $status->attained->level ) . '</span> '
				. 'signed ' . esc_html( SSLMS_DB::fmt_date( $status->attained->signed_at ) )
				. ( $signer ? ' by ' . esc_html( $signer->display_name ) : '' );
		}
		$class = 'evidence_complete' === $status->state ? 'warn' : ( 'in_progress' === $status->state ? 'warn' : 'muted' );
		return '<span class="sslms-badge sslms-badge--' . esc_attr( $class ) . '">' . esc_html( self::STATE_LABELS[ $status->state ] ?? $status->state ) . '</span>';
	}

	public static function dashboard_cards( array $cards ): array {
		if ( ! is_user_logged_in() || ! current_user_can( 'sslms_learn' ) ) {
			return $cards;
		}
		$uid      = get_current_user_id();
		$attained = 0;
		$total    = 0;
		foreach ( SSLMS_Competencies::passport( $uid ) as $section ) {
			foreach ( $section->rows as $row ) {
				$total++;
				if ( $row->status->attained ) {
					$attained++;
				}
			}
		}
		if ( $total ) {
			$cards[] = array(
				'title' => 'Competency passport',
				'html'  => '<p style="font-size:1.8rem;font-weight:700;margin:0">' . (int) $attained . ' / ' . (int) $total . '</p><p><a href="' . esc_url( SSLMS_Portal::page_url( 'passport' ) ) . '">View passport</a></p>',
			);
		}
		return $cards;
	}
}
SSLMS_Portal_Passport::init();
