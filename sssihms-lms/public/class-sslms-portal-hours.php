<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [sslms_my_hours] — learner rotation progress + log-hours form, plus (for
 * preceptors) a pending-approvals worklist.
 */
class SSLMS_Portal_Hours {

	public static function init(): void {
		add_shortcode( 'sslms_my_hours', array( __CLASS__, 'shortcode' ) );
		add_filter( 'sslms_dashboard_cards', array( __CLASS__, 'dashboard_cards' ) );
	}

	public static function shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return SSLMS_Portal::wrap( 'My Hours', '' );
		}
		$uid  = get_current_user_id();
		$html = '';

		if ( current_user_can( 'sslms_approve_hours' ) ) {
			$html .= self::render_pending_approvals( $uid );
		}

		if ( current_user_can( 'sslms_learn' ) ) {
			$html .= self::render_my_rotations( $uid );
		}

		if ( '' === $html ) {
			$html = '<p>Nothing to show.</p>';
		}
		$html .= self::inline_script();
		return SSLMS_Portal::wrap( 'My Hours', $html );
	}

	/** Glue script for the one action the generic form-wiring doesn't cover: deleting a pending log. */
	private static function inline_script(): string {
		return '<script>(function(){document.addEventListener("click",function(ev){'
			. 'var del=ev.target.closest("[data-sslms-delete-log]");if(!del){return;}'
			. 'if(!confirm("Delete this log entry?")){return;}'
			. 'SSLMS.api("hours/logs/"+del.getAttribute("data-sslms-delete-log"),{method:"DELETE"})'
			. '.then(function(){window.location.reload();}).catch(function(e){alert(e.message);});'
			. '});})();</script>';
	}

	private static function status_badge( string $status ): string {
		$class = 'muted';
		if ( 'approved' === $status ) {
			$class = 'ok';
		} elseif ( 'rejected' === $status ) {
			$class = 'bad';
		} elseif ( 'pending' === $status ) {
			$class = 'warn';
		}
		return '<span class="sslms-badge sslms-badge--' . esc_attr( $class ) . '">' . esc_html( $status ) . '</span>';
	}

	private static function render_my_rotations( int $uid ): string {
		$rotations = SSLMS_Hours::rotations_for_user( $uid );
		$html = '<section><h2>My rotations</h2>';
		if ( ! $rotations ) {
			$html .= '<p>No rotations assigned yet.</p></section>';
			return $html;
		}
		foreach ( $rotations as $r ) {
			$totals = $r->totals;
			$pct    = $totals['required'] > 0 ? min( 100, round( 100 * $totals['approved'] / $totals['required'] ) ) : 0;
			$html  .= '<div class="sslms-card">';
			$html  .= '<h3>' . esc_html( $r->department ) . '</h3>';
			$html  .= '<p>' . esc_html( SSLMS_DB::fmt_date( $r->start_date ) . ' – ' . SSLMS_DB::fmt_date( $r->end_date ) ) . '</p>';
			$html  .= '<p>' . esc_html( number_format( $totals['approved'], 2 ) ) . ' / ' . esc_html( number_format( $totals['required'], 2 ) ) . ' hours approved'
				. ( $totals['pending'] > 0 ? ' (' . esc_html( number_format( $totals['pending'], 2 ) ) . ' pending)' : '' ) . '</p>';
			$html  .= '<div class="sslms-progressbar"><span style="width:' . (int) $pct . '%"></span></div>';

			$html  .= '<h4>Log hours</h4>';
			$html  .= '<form data-sslms-endpoint="hours/logs" data-sslms-method="POST">';
			$html  .= '<input type="hidden" name="rotation_id" value="' . (int) $r->id . '">';
			$html  .= '<label>Date<input type="date" name="date" required></label>';
			$html  .= '<label>Hours<input type="number" step="0.25" min="0.25" max="24" name="hours" required></label>';
			$html  .= '<label>Activity<input type="text" name="activity"></label>';
			$html  .= '<button type="submit" class="sslms-btn">Log hours</button>';
			$html  .= '</form>';

			if ( $r->logs ) {
				$html .= '<div class="sslms-table-scroll"><table><thead><tr><th>Date</th><th>Hours</th><th>Activity</th><th>Status</th><th></th></tr></thead><tbody>';
				foreach ( $r->logs as $log ) {
					$html .= '<tr><td>' . esc_html( SSLMS_DB::fmt_date( $log->log_date ) ) . '</td>'
						. '<td>' . esc_html( $log->hours ) . '</td>'
						. '<td>' . esc_html( $log->activity ) . '</td>'
						. '<td>' . self::status_badge( $log->status ) . ( 'rejected' === $log->status && $log->review_note ? ' <small>' . esc_html( $log->review_note ) . '</small>' : '' ) . '</td>'
						. '<td>';
					if ( 'pending' === $log->status ) {
						$html .= '<button type="button" class="sslms-btn sslms-btn--danger" data-sslms-delete-log="' . (int) $log->id . '">Delete</button>';
					}
					$html .= '</td></tr>';
				}
				$html .= '</tbody></table></div>';
			}
			$html .= '<p><a class="sslms-btn sslms-btn--ghost" href="' . esc_url( rest_url( 'sslms/v1/hours/export/rotation/' . $r->id ) ) . '">Export my hours (CSV)</a></p>';
			$html .= '</div>';
		}
		$html .= '</section>';
		return $html;
	}

	private static function render_pending_approvals( int $uid ): string {
		$logs = SSLMS_Hours::pending_approvals( $uid );
		$html = '<section><h2>Pending approvals</h2>';
		if ( ! $logs ) {
			$html .= '<p>No hour logs awaiting your approval.</p></section>';
			return $html;
		}
		foreach ( $logs as $log ) {
			$learner = get_userdata( (int) $log->learner_id );
			$html   .= '<div class="sslms-card">';
			$html   .= '<h3>' . esc_html( $learner ? $learner->display_name : ( 'User ' . $log->learner_id ) ) . '</h3>';
			$html   .= '<p>' . esc_html( $log->department ) . ' &middot; ' . esc_html( SSLMS_DB::fmt_date( $log->log_date ) ) . ' &middot; ' . esc_html( $log->hours ) . ' h</p>';
			$html   .= '<p>' . esc_html( $log->activity ) . '</p>';
			$html   .= '<form data-sslms-endpoint="hours/logs/' . (int) $log->id . '/review" data-sslms-method="POST">';
			$html   .= '<label>Note<textarea name="note" rows="2"></textarea></label>';
			$html   .= '<input type="hidden" name="status" value="approve">';
			$html   .= '<button type="submit" class="sslms-btn">Approve</button>';
			$html   .= '</form>';
			$html   .= '<form data-sslms-endpoint="hours/logs/' . (int) $log->id . '/review" data-sslms-method="POST">';
			$html   .= '<label>Reject reason<textarea name="note" rows="2"></textarea></label>';
			$html   .= '<input type="hidden" name="status" value="reject">';
			$html   .= '<button type="submit" class="sslms-btn sslms-btn--danger">Reject</button>';
			$html   .= '</form>';
			$html   .= '</div>';
		}
		$html .= '</section>';
		return $html;
	}

	/** Dashboard cards: pending approvals count (preceptor) / rejected-logs alert (learner). */
	public static function dashboard_cards( array $cards ): array {
		if ( ! is_user_logged_in() ) {
			return $cards;
		}
		$uid = get_current_user_id();
		if ( current_user_can( 'sslms_approve_hours' ) ) {
			$count = count( SSLMS_Hours::pending_approvals( $uid ) );
			$cards[] = array(
				'title' => 'Pending hour approvals',
				'html'  => '<p style="font-size:1.8rem;font-weight:700;margin:0">' . (int) $count . '</p><p><a href="' . esc_url( SSLMS_Portal::page_url( 'my_hours' ) ) . '">Review approvals</a></p>',
			);
		}
		if ( current_user_can( 'sslms_learn' ) ) {
			$rejected = 0;
			foreach ( SSLMS_Hours::logs_for_user( $uid ) as $log ) {
				if ( 'rejected' === $log->status ) {
					$rejected++;
				}
			}
			if ( $rejected > 0 ) {
				$cards[] = array(
					'title' => 'Rejected hour logs',
					'html'  => '<p style="font-size:1.8rem;font-weight:700;margin:0">' . (int) $rejected . '</p><p><a href="' . esc_url( SSLMS_Portal::page_url( 'my_hours' ) ) . '">See details</a></p>',
				);
			}
		}
		return $cards;
	}
}
SSLMS_Portal_Hours::init();
