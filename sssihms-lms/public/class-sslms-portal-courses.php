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
		if ( ! $rows ) {
			return '<section><h2>Enrolled</h2><p>You are not enrolled in any course yet.</p></section>';
		}

		$active    = array();
		$completed = array();
		foreach ( $rows as $row ) {
			if ( 'completed' === $row->enrollment_status ) {
				$completed[] = $row;
			} else {
				$active[] = $row;
			}
		}

		$html = '';
		if ( $active ) {
			$html .= '<section><h2>In progress</h2>';
			foreach ( $active as $row ) {
				$html .= self::course_card( $row, $uid, false );
			}
			$html .= '</section>';
		}
		if ( $completed ) {
			$html .= '<section><h2>Completed</h2>';
			foreach ( $completed as $row ) {
				$html .= self::course_card( $row, $uid, true );
			}
			$html .= '</section>';
		}
		return $html;
	}

	/** One enrolled-course card: badges, progress breakdown, resume deep link. */
	private static function course_card( object $row, int $uid, bool $is_completed ): string {
		$course_id = (int) $row->course_id;
		$url       = SSLMS_Portal::page_url( 'course', array( 'course' => $course_id ) );
		$data      = self::course_view_data( $course_id, $uid );

		$html  = '<div class="sslms-card">';
		$html .= '<h3><a href="' . esc_url( $url ) . '">' . esc_html( $row->title ) . '</a></h3>';
		$html .= '<p><span class="sslms-badge sslms-badge--muted">' . esc_html( ucfirst( $row->track ) ) . '</span> ';
		if ( $is_completed ) {
			$html .= '<span class="sslms-badge sslms-badge--ok">Completed ' . esc_html( SSLMS_DB::fmt_date( $row->completed_at ) ) . '</span>';
		} else {
			$html .= '<span class="sslms-badge sslms-badge--warn">In progress</span>';
		}
		$html .= '</p>';

		$html .= '<div class="sslms-progressbar"><span style="width:' . (int) $data['course_pct'] . '%"></span></div>';
		$html .= '<p>' . self::counts_line( $data ) . '</p>';

		if ( $is_completed ) {
			$html .= '<p><a class="sslms-btn sslms-btn--ghost" href="' . esc_url( $url ) . '">Review course</a></p>';
		} else {
			$next = self::next_lesson( $data );
			if ( $next ) {
				$resume_url = SSLMS_Portal::page_url( 'course', array( 'course' => $course_id, 'lesson' => $next->id ) );
				$html      .= '<p><a class="sslms-btn" href="' . esc_url( $resume_url ) . '">Resume: ' . esc_html( $next->title ) . '</a></p>';
			} else {
				$html .= '<p><a class="sslms-btn" href="' . esc_url( $url ) . '">Continue</a></p>';
			}
		}
		$html .= '</div>';
		return $html;
	}

	/** "n of m lessons · q of r assessments passed" summary line. */
	private static function counts_line( array $data ): string {
		$line = (int) $data['lesson_done'] . ' / ' . (int) $data['lesson_total'] . ' lessons';
		if ( $data['required_total'] > 0 ) {
			$line .= ' &middot; ' . (int) $data['required_passed'] . ' / ' . (int) $data['required_total'] . ' required assessments passed';
		}
		return $line;
	}

	/**
	 * Quick-access summary cards for an in-progress course: outstanding
	 * assessments and a marks summary, pinned above the module list so a
	 * learner can see "what's outstanding" at a glance rather than scanning
	 * every module (Great Learning-inspired pattern).
	 */
	private static function quick_access_row( array $data ): string {
		if ( 0 === $data['required_total'] && 0 === $data['best_count'] ) {
			return '';
		}

		$html = '<div class="sslms-quick-access">';
		if ( $data['required_total'] > 0 ) {
			$html .= '<div class="sslms-quick-card"><span class="sslms-quick-label">Required assessments</span>'
				. '<span class="sslms-quick-value">' . ( $data['outstanding_required'] > 0
					? esc_html( $data['outstanding_required'] . ' to do' )
					: '<span class="sslms-badge sslms-badge--ok">All done</span>' ) . '</span></div>';
		}
		if ( $data['best_count'] > 0 ) {
			$avg_marks = $data['best_sum'] / $data['best_count'];
			$html     .= '<div class="sslms-quick-card"><span class="sslms-quick-label">Marks</span>'
				. '<span class="sslms-quick-value">' . esc_html( number_format( $avg_marks, 0 ) . '%' ) . '</span></div>';
		}
		$html .= '</div>';
		return $html;
	}

	/** First incomplete lesson of a course, in module/lesson order (resume target). */
	private static function next_lesson( array $data ): ?object {
		return $data['next_lesson'];
	}

	/**
	 * Batched, request-cached learner state for one course. This replaces the
	 * per-lesson/per-quiz lookups that made portal query counts grow with every
	 * item rendered.
	 */
	private static function course_view_data( int $course_id, int $uid ): array {
		static $cache = array();
		$key = $course_id . ':' . $uid;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		global $wpdb;
		$modules          = SSLMS_Courses::modules( $course_id );
		$lessons_t        = SSLMS_DB::table( 'lessons' );
		$modules_t        = SSLMS_DB::table( 'modules' );
		$progress_t       = SSLMS_DB::table( 'lesson_progress' );
		$lessons          = $wpdb->get_results( $wpdb->prepare(
			"SELECT l.*, CASE WHEN lp.id IS NULL THEN 0 ELSE 1 END AS sslms_done
			 FROM {$lessons_t} l
			 INNER JOIN {$modules_t} m ON m.id = l.module_id
			 LEFT JOIN {$progress_t} lp ON lp.lesson_id = l.id AND lp.user_id = %d
			 WHERE m.course_id = %d
			 ORDER BY m.sort_order ASC, m.id ASC, l.sort_order ASC, l.id ASC",
			$uid,
			$course_id
		) ) ?: array();

		$lessons_by_module = array();
		foreach ( $modules as $module ) {
			$lessons_by_module[ (int) $module->id ] = array();
		}
		$lesson_done = 0;
		$next_lesson = null;
		foreach ( $lessons as $lesson ) {
			$lessons_by_module[ (int) $lesson->module_id ][] = $lesson;
			if ( ! empty( $lesson->sslms_done ) ) {
				$lesson_done++;
			} elseif ( null === $next_lesson ) {
				$next_lesson = $lesson;
			}
		}

		$quizzes = array();
		if ( class_exists( 'SSLMS_Quizzes' ) ) {
			$quizzes_t = SSLMS_DB::table( 'quizzes' );
			$attempts_t = SSLMS_DB::table( 'quiz_attempts' );
			$quizzes    = $wpdb->get_results( $wpdb->prepare(
				"SELECT q.*, COALESCE(a.user_passed, 0) AS sslms_passed, a.best_score AS sslms_best
				 FROM {$quizzes_t} q
				 LEFT JOIN (
					SELECT quiz_id, MAX(passed) AS user_passed, MAX(score_pct) AS best_score
					FROM {$attempts_t}
					WHERE user_id = %d AND submitted_at IS NOT NULL
					GROUP BY quiz_id
				 ) a ON a.quiz_id = q.id
				 WHERE q.course_id = %d
				 ORDER BY (q.lesson_id IS NULL) ASC, q.lesson_id ASC, q.id ASC",
				$uid,
				$course_id
			) ) ?: array();
		}

		$required_total  = 0;
		$required_passed = 0;
		$best_sum        = 0.0;
		$best_count      = 0;
		foreach ( $quizzes as $quiz ) {
			if ( ! empty( $quiz->is_required ) ) {
				$required_total++;
				if ( ! empty( $quiz->sslms_passed ) ) {
					$required_passed++;
				}
			}
			if ( null !== $quiz->sslms_best ) {
				$best_sum += (float) $quiz->sslms_best;
				$best_count++;
			}
		}

		$lesson_total = count( $lessons );
		$unit_total   = $lesson_total + $required_total;
		$course_pct   = 0 === $unit_total ? 100 : (int) round( ( ( $lesson_done + $required_passed ) / $unit_total ) * 100 );

		$cache[ $key ] = array(
			'modules'              => $modules,
			'lessons_by_module'     => $lessons_by_module,
			'lesson_total'          => $lesson_total,
			'lesson_done'           => $lesson_done,
			'next_lesson'           => $next_lesson,
			'quizzes'               => $quizzes,
			'required_total'        => $required_total,
			'required_passed'       => $required_passed,
			'outstanding_required'  => $required_total - $required_passed,
			'best_sum'              => $best_sum,
			'best_count'            => $best_count,
			'course_pct'            => $course_pct,
		);
		return $cache[ $key ];
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
		$data = self::course_view_data( (int) $course->id, $uid );
		if ( $course->description ) {
			$html .= '<p>' . nl2br( esc_html( $course->description ) ) . '</p>';
		}
		$html .= '<p><span class="sslms-badge sslms-badge--muted">' . esc_html( ucfirst( $course->track ) ) . '</span></p>';

		$enrollment = SSLMS_Enrollments::find( (int) $course->id, $uid );
		if ( $enrollment && 'completed' === $enrollment->status ) {
			$html .= '<p><span class="sslms-badge sslms-badge--ok">Completed ' . esc_html( SSLMS_DB::fmt_date( $enrollment->completed_at ) ) . '</span></p>';
			$html .= self::quick_access_row( $data );
		} elseif ( $enrollment ) {
			// Progress summary + resume deep link, so a learner on shift lands
			// straight on their next lesson instead of hunting for it.
			$html .= '<p>' . self::counts_line( $data ) . '</p>';
			$html .= '<div class="sslms-progressbar"><span style="width:' . (int) $data['course_pct'] . '%"></span></div>';
			$next = self::next_lesson( $data );
			if ( $next ) {
				$resume_url = SSLMS_Portal::page_url( 'course', array( 'course' => $course->id, 'lesson' => $next->id ) );
				$html      .= '<p style="margin-top:10px"><a class="sslms-btn" href="' . esc_url( $resume_url ) . '">Resume: ' . esc_html( $next->title ) . '</a></p>';
			}
			$html .= self::quick_access_row( $data );
		}

		$modules = $data['modules'];
		if ( ! $modules ) {
			$html .= '<p>No content has been added to this course yet.</p>';
		}
		foreach ( $modules as $module ) {
			$lessons = $data['lessons_by_module'][ (int) $module->id ] ?? array();
			$done_ct = 0;
			foreach ( $lessons as $lesson ) {
				if ( ! empty( $lesson->sslms_done ) ) {
					$done_ct++;
				}
			}
			$mod_badge = $lessons
				? ' <span class="sslms-badge ' . ( count( $lessons ) === $done_ct ? 'sslms-badge--ok' : 'sslms-badge--muted' ) . '">'
					. (int) $done_ct . ' / ' . count( $lessons ) . '</span>'
				: '';
			$html .= '<details class="sslms-card" open><summary><strong>' . esc_html( $module->title ) . '</strong>' . $mod_badge . '</summary>';
			$html .= '<ul style="list-style:none;padding:0;margin:10px 0 0">';
			if ( ! $lessons ) {
				$html .= '<li>No lessons yet.</li>';
			}
			foreach ( $lessons as $lesson ) {
				$done = ! empty( $lesson->sslms_done );
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

	/** Responsive embed for YouTube/Vimeo/Google Drive URLs; a plain <video> tag for direct file URLs. */
	private static function render_video_embed( string $url ): string {
		if ( preg_match( '#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{6,})#i', $url, $m ) ) {
			return self::responsive_iframe( 'https://www.youtube.com/embed/' . rawurlencode( $m[1] ) );
		}
		if ( preg_match( '#vimeo\.com/(\d+)#i', $url, $m ) ) {
			return self::responsive_iframe( 'https://player.vimeo.com/video/' . rawurlencode( $m[1] ) );
		}
		// Google Drive: stream via Drive's own preview player — the file stays on
		// Drive and its sharing permissions still apply to each viewer.
		$drive_id = self::google_drive_file_id( $url );
		if ( $drive_id ) {
			return self::responsive_iframe( 'https://drive.google.com/file/d/' . rawurlencode( $drive_id ) . '/preview' );
		}
		return '<video controls style="width:100%;max-width:100%;border-radius:8px" src="' . esc_url( $url ) . '">'
			. 'Your browser does not support embedded video. <a href="' . esc_url( $url ) . '">Download the video</a>.</video>';
	}

	/** File id from either /file/d/ID links or older open/uc?id=ID links. */
	private static function google_drive_file_id( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || 'drive.google.com' !== strtolower( $parts['host'] ) ) {
			return '';
		}
		if ( ! empty( $parts['path'] ) && preg_match( '#/file/d/([A-Za-z0-9_-]{10,})#', $parts['path'], $matches ) ) {
			return $matches[1];
		}
		if ( empty( $parts['query'] ) ) {
			return '';
		}
		parse_str( $parts['query'], $query );
		$id = isset( $query['id'] ) && is_string( $query['id'] ) ? $query['id'] : '';
		return preg_match( '/^[A-Za-z0-9_-]{10,}$/', $id ) ? $id : '';
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
				$data = self::course_view_data( (int) $row->course_id, $uid );
				$next = self::next_lesson( $data );
				if ( $next ) {
					$url   = SSLMS_Portal::page_url( 'course', array( 'course' => $row->course_id, 'lesson' => $next->id ) );
					$html .= '<li style="padding:4px 0"><a href="' . esc_url( $url ) . '">' . esc_html( $row->title ) . '</a>'
						. '<br><small>Next: ' . esc_html( $next->title ) . '</small></li>';
				} else {
					$url   = SSLMS_Portal::page_url( 'course', array( 'course' => $row->course_id ) );
					$html .= '<li style="padding:4px 0"><a href="' . esc_url( $url ) . '">' . esc_html( $row->title ) . '</a></li>';
				}
			}
			$html .= '</ul>';
		}
		$html .= '<p><a href="' . esc_url( SSLMS_Portal::page_url( 'my_courses' ) ) . '">View all courses</a></p>';

		$cards[] = array( 'title' => 'Continue learning', 'html' => $html );
		return $cards;
	}
}
SSLMS_Portal_Courses::init();
