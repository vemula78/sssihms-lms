<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module A — learner-facing course shortcodes (SPEC F12): [sslms_my_courses]
 * (enrolled courses + courses available to join) and [sslms_course]
 * (?course=ID player, &lesson=ID single-lesson view). Mobile-first, reuses
 * assets/css/sslms.css classes only.
 */
class SSLMS_Portal_Courses {

	public static function init(): void {
		add_shortcode( 'sslms_my_courses', array( __CLASS__, 'my_courses_shortcode' ) );
		add_shortcode( 'sslms_course', array( __CLASS__, 'course_shortcode' ) );
		add_filter( 'sslms_dashboard_cards', array( __CLASS__, 'dashboard_card' ) );
	}

	/* -----------------------------------------------------------------
	 * [sslms_my_courses]
	 * ------------------------------------------------------------- */

	public static function my_courses_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return SSLMS_Portal::wrap( 'My Courses', '' );
		}
		$uid = get_current_user_id();
		$html = self::render_enrolled_courses( $uid );

		if ( current_user_can( 'sslms_learn' ) ) {
			$html .= self::render_available_courses( $uid );
		}

		return SSLMS_Portal::wrap( 'My Courses', $html );
	}

	private static function render_enrolled_courses( int $uid ): string {
		$rows = SSLMS_Enrollments::for_user( $uid );
		$html = '<section><h2>Enrolled</h2>';
		if ( ! $rows ) {
			$html .= '<p>You are not enrolled in any course yet.</p></section>';
			return $html;
		}
		foreach ( $rows as $row ) {
			$course_id = (int) $row->course_id;
			$url       = SSLMS_Portal::page_url( 'course', array( 'course' => $course_id ) );

			$html .= '<div class="sslms-card">';
			$html .= '<h3><a href="' . esc_url( $url ) . '">' . esc_html( $row->title ) . '</a></h3>';
			$html .= '<p><span class="sslms-badge sslms-badge--muted">' . esc_html( ucfirst( $row->track ) ) . '</span> ';
			if ( 'completed' === $row->enrollment_status ) {
				$html .= '<span class="sslms-badge sslms-badge--ok">Completed ' . esc_html( SSLMS_DB::fmt_date( $row->completed_at ) ) . '</span>';
			} else {
				$html .= '<span class="sslms-badge sslms-badge--warn">In progress</span>';
			}
			$html .= '</p>';

			if ( class_exists( 'SSLMS_Progress' ) ) {
				$pct   = SSLMS_Progress::course_pct( $course_id, $uid );
				$html .= '<div class="sslms-progressbar"><span style="width:' . (int) $pct . '%"></span></div>';
				$html .= '<p>' . (int) $pct . '% complete</p>';
			} else {
				$fallback = self::lesson_progress_fallback( $course_id, $uid );
				$html    .= '<p>' . (int) $fallback['done'] . ' of ' . (int) $fallback['total'] . ' lessons complete</p>';
			}

			$html .= '<p><a class="sslms-btn" href="' . esc_url( $url ) . '">'
				. ( 'completed' === $row->enrollment_status ? 'Review course' : 'Continue' ) . '</a></p>';
			$html .= '</div>';
		}
		$html .= '</section>';
		return $html;
	}

	private static function render_available_courses( int $uid ): string {
		$rows = SSLMS_Enrollments::available_open_courses( $uid );
		if ( ! $rows ) {
			return '';
		}
		$html = '<section><h2>Available to join</h2>';
		foreach ( $rows as $c ) {
			$html .= '<div class="sslms-card">';
			$html .= '<h3>' . esc_html( $c->title ) . '</h3>';
			$html .= '<p><span class="sslms-badge sslms-badge--muted">' . esc_html( ucfirst( $c->track ) ) . '</span></p>';
			if ( $c->description ) {
				$html .= '<p>' . nl2br( esc_html( wp_trim_words( $c->description, 30 ) ) ) . '</p>';
			}
			$html .= '<form data-sslms-endpoint="' . esc_attr( 'courses/' . $c->id . '/self-enroll' ) . '" data-sslms-method="POST">'
				. '<button type="submit" class="sslms-btn">Join course</button></form>';
			$html .= '</div>';
		}
		$html .= '</section>';
		return $html;
	}

	/** Lesson-count fallback used only when Module C (SSLMS_Progress) is not loaded. */
	private static function lesson_progress_fallback( int $course_id, int $user_id ): array {
		$lesson_ids = SSLMS_Courses::lesson_ids( $course_id );
		$total      = count( $lesson_ids );
		if ( 0 === $total ) {
			return array( 'done' => 0, 'total' => 0 );
		}
		global $wpdb;
		$t            = SSLMS_DB::table( 'lesson_progress' );
		$placeholders = implode( ',', array_fill( 0, $total, '%d' ) );
		$done         = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND lesson_id IN ({$placeholders})",
			array_merge( array( $user_id ), array_map( 'intval', $lesson_ids ) )
		) );
		return array( 'done' => $done, 'total' => $total );
	}

	/* -----------------------------------------------------------------
	 * [sslms_course] — ?course=ID[&lesson=ID]
	 * ------------------------------------------------------------- */

	public static function course_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return SSLMS_Portal::wrap( 'Course', '' );
		}

		$course_id = isset( $_GET['course'] ) ? absint( wp_unslash( $_GET['course'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $course_id ) {
			return SSLMS_Portal::wrap( 'Course', '<p>No course specified.</p>' );
		}

		$course = SSLMS_Courses::get( $course_id );
		if ( ! $course ) {
			return SSLMS_Portal::wrap( 'Course', '<p>Course not found.</p>' );
		}

		$uid      = get_current_user_id();
		$can_edit = SSLMS_Courses::can_edit( $course_id, $uid );

		// Students must never see draft/archived courses, under any circumstance.
		if ( 'published' !== $course->status && ! $can_edit ) {
			return SSLMS_Portal::wrap( 'Course', '<p>This course is not currently available.</p>' );
		}

		$enrolled = SSLMS_Enrollments::is_enrolled( $course_id, $uid );
		if ( ! $enrolled && ! $can_edit ) {
			return SSLMS_Portal::wrap( esc_html( $course->title ), '<p>You are not enrolled in this course.</p>' );
		}

		$lesson_id = isset( $_GET['lesson'] ) ? absint( wp_unslash( $_GET['lesson'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $lesson_id ) {
			$html = self::render_lesson( $course, $lesson_id );
		} else {
			$html = self::render_overview( $course, $uid );
		}

		return SSLMS_Portal::wrap( esc_html( $course->title ), $html );
	}

	private static function render_overview( object $course, int $uid ): string {
		$html = '';
		if ( $course->description ) {
			$html .= '<p>' . nl2br( esc_html( $course->description ) ) . '</p>';
		}
		$html .= '<p><span class="sslms-badge sslms-badge--muted">' . esc_html( ucfirst( $course->track ) ) . '</span></p>';

		$enrollment = SSLMS_Enrollments::find( (int) $course->id, $uid );
		if ( $enrollment && 'completed' === $enrollment->status ) {
			$html .= '<p><span class="sslms-badge sslms-badge--ok">Completed ' . esc_html( SSLMS_DB::fmt_date( $enrollment->completed_at ) ) . '</span></p>';
		}

		$modules = SSLMS_Courses::modules( (int) $course->id );
		if ( ! $modules ) {
			$html .= '<p>No content has been added to this course yet.</p>';
		}
		foreach ( $modules as $module ) {
			$html .= '<details class="sslms-card" open><summary><strong>' . esc_html( $module->title ) . '</strong></summary>';
			$html .= '<ul style="list-style:none;padding:0;margin:10px 0 0">';
			$lessons = SSLMS_Courses::lessons( (int) $module->id );
			if ( ! $lessons ) {
				$html .= '<li>No lessons yet.</li>';
			}
			foreach ( $lessons as $lesson ) {
				$done = class_exists( 'SSLMS_Progress' ) && SSLMS_Progress::lesson_done( (int) $lesson->id, $uid );
				$tick = $done
					? '<span class="sslms-badge sslms-badge--ok">&#10003; Done</span>'
					: '<span class="sslms-badge sslms-badge--muted">Not started</span>';
				$lesson_url = SSLMS_Portal::page_url( 'course', array( 'course' => $course->id, 'lesson' => $lesson->id ) );
				$html      .= '<li style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--ss-line)">'
					. '<a href="' . esc_url( $lesson_url ) . '">' . esc_html( $lesson->title ) . '</a>' . $tick . '</li>';
			}
			$html .= '</ul></details>';
		}

		// Beyond the CONVENTIONS.md contract hook (sslms_lesson_view), also fire a
		// course-level hook so course-final quizzes (Module B) can render here.
		ob_start();
		do_action( 'sslms_course_view', $course );
		$html .= ob_get_clean();

		return $html;
	}

	private static function render_lesson( object $course, int $lesson_id ): string {
		if ( SSLMS_Courses::course_id_for_lesson( $lesson_id ) !== (int) $course->id ) {
			return '<p>Lesson not found in this course.</p>';
		}
		$lesson = SSLMS_Courses::get_lesson( $lesson_id );
		if ( ! $lesson ) {
			return '<p>Lesson not found.</p>';
		}

		$back_url = SSLMS_Portal::page_url( 'course', array( 'course' => $course->id ) );
		$html     = '<p><a href="' . esc_url( $back_url ) . '">&larr; Back to ' . esc_html( $course->title ) . '</a></p>';
		$html    .= '<h2>' . esc_html( $lesson->title ) . '</h2>';

		if ( $lesson->video_url ) {
			$html .= self::render_video_embed( $lesson->video_url );
		}

		$html .= '<div class="sslms-lesson-content">' . wp_kses_post( $lesson->content ) . '</div>';

		$attachments = SSLMS_Courses::lesson_attachments( $lesson );
		if ( $attachments ) {
			$html .= '<h3>Attachments</h3><ul>';
			foreach ( $attachments as $att_id ) {
				$url = wp_get_attachment_url( $att_id );
				if ( ! $url ) {
					continue;
				}
				$name  = get_the_title( $att_id );
				$label = $name ? $name : basename( $url );
				$html .= '<li><a href="' . esc_url( $url ) . '" download>' . esc_html( $label ) . '</a></li>';
			}
			$html .= '</ul>';
		}

		// CONVENTIONS.md contract: fired after lesson content so Module B (quiz
		// launcher) and Module C (mark-complete button) can append their UI.
		ob_start();
		do_action( 'sslms_lesson_view', $lesson, $course );
		$html .= ob_get_clean();

		return $html;
	}

	/** Responsive embed for YouTube/Vimeo URLs; a plain <video> tag for direct file URLs. */
	private static function render_video_embed( string $url ): string {
		if ( preg_match( '#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{6,})#i', $url, $m ) ) {
			return self::responsive_iframe( 'https://www.youtube.com/embed/' . rawurlencode( $m[1] ) );
		}
		if ( preg_match( '#vimeo\.com/(\d+)#i', $url, $m ) ) {
			return self::responsive_iframe( 'https://player.vimeo.com/video/' . rawurlencode( $m[1] ) );
		}
		return '<video controls style="width:100%;max-width:100%;border-radius:8px" src="' . esc_url( $url ) . '">'
			. 'Your browser does not support embedded video. <a href="' . esc_url( $url ) . '">Download the video</a>.</video>';
	}

	private static function responsive_iframe( string $src ): string {
		return '<div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:8px">'
			. '<iframe src="' . esc_url( $src ) . '" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" allowfullscreen loading="lazy"></iframe></div>';
	}

	/* -----------------------------------------------------------------
	 * Dashboard card
	 * ------------------------------------------------------------- */

	/** sslms_dashboard_cards filter — active course count + up to 3 in-progress courses. */
	public static function dashboard_card( array $cards ): array {
		if ( ! is_user_logged_in() || ! current_user_can( 'sslms_learn' ) ) {
			return $cards;
		}
		$uid  = get_current_user_id();
		$rows = SSLMS_Enrollments::for_user( $uid );

		$active_count = 0;
		$in_progress  = array();
		foreach ( $rows as $row ) {
			if ( 'active' === $row->enrollment_status ) {
				$active_count++;
				if ( count( $in_progress ) < 3 ) {
					$in_progress[] = $row;
				}
			}
		}

		$html = '<p style="font-size:1.8rem;font-weight:700;margin:0">' . (int) $active_count . '</p>';
		if ( $in_progress ) {
			$html .= '<ul style="list-style:none;padding:0;margin:8px 0">';
			foreach ( $in_progress as $row ) {
				$url   = SSLMS_Portal::page_url( 'course', array( 'course' => $row->course_id ) );
				$html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $row->title ) . '</a></li>';
			}
			$html .= '</ul>';
		}
		$html .= '<p><a href="' . esc_url( SSLMS_Portal::page_url( 'my_courses' ) ) . '">View all courses</a></p>';

		$cards[] = array( 'title' => 'My Courses', 'html' => $html );
		return $cards;
	}
}
SSLMS_Portal_Courses::init();
