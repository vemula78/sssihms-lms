<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen — Courses (SPEC F2): course list, course editor (details +
 * modules/lessons management + Enrol panel), and a lesson editor (rich
 * text via wp_editor, video URL, media-library attachments).
 *
 * All mutations go through the sslms/v1 REST API — most via the shared JS
 * (assets/js/sslms.js) form-wiring, the lesson editor via a small custom
 * script (it needs to flush TinyMCE content and assemble the attachments
 * array before posting).
 */
class SSLMS_Admin_Courses {

	const CAP = 'sslms_author_courses';

	private static $hook = '';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function register(): void {
		self::$hook = add_submenu_page(
			'sslms',
			'Courses',
			'Courses',
			self::CAP,
			'sslms-courses',
			array( __CLASS__, 'render' )
		);
	}

	/** Media library JS/CSS only on this screen (lesson attachments picker). */
	public static function assets( string $hook ): void {
		if ( $hook === self::$hook ) {
			wp_enqueue_media();
		}
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not permitted.', 'sssihms-lms' ) );
		}

		$course_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap"><h1>Courses</h1><div class="sslms">';

		if ( $course_id ) {
			if ( ! SSLMS_Courses::can_edit( $course_id, get_current_user_id() ) ) {
				echo '<p>' . esc_html__( 'You may only manage courses you created.', 'sssihms-lms' ) . '</p></div></div>';
				return;
			}
			$lesson_param = isset( $_GET['lesson'] ) ? sanitize_text_field( wp_unslash( $_GET['lesson'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' !== $lesson_param ) {
				self::render_lesson_editor( $course_id, $lesson_param );
			} else {
				self::render_course_editor( $course_id );
			}
		} else {
			self::render_list();
		}

		echo '</div></div>';
		self::inline_script();
	}

	/* -----------------------------------------------------------------
	 * Course list
	 * ------------------------------------------------------------- */

	private static function render_list(): void {
		$scope   = current_user_can( 'sslms_manage' ) ? array() : array( 'created_by' => get_current_user_id() );
		$courses = SSLMS_Courses::list_courses( $scope );

		echo '<h2>All courses</h2>';
		if ( $courses ) {
			echo '<div class="sslms-table-scroll"><table class="widefat striped"><thead><tr>'
				. '<th>Title</th><th>Track</th><th>Status</th><th>Enrolled</th><th></th>'
				. '</tr></thead><tbody>';
			foreach ( $courses as $c ) {
				$edit_url = esc_url( add_query_arg( array( 'page' => 'sslms-courses', 'id' => $c->id ), admin_url( 'admin.php' ) ) );
				echo '<tr>';
				echo '<td><a href="' . $edit_url . '">' . esc_html( $c->title ) . '</a></td>';
				echo '<td>' . esc_html( ucfirst( $c->track ) ) . '</td>';
				echo '<td>' . self::status_badge( $c->status ) . '</td>';
				echo '<td>' . (int) $c->enrolled_count . '</td>';
				echo '<td><a class="sslms-btn sslms-btn--ghost" href="' . $edit_url . '">Open</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No courses yet.</p>';
		}

		echo '<h2>Add new course</h2>';
		echo '<form data-sslms-endpoint="courses" data-sslms-method="POST">';
		echo '<label>Title<input type="text" name="title" required></label>';
		echo '<label>Track<select name="track">';
		foreach ( SSLMS_Courses::TRACKS as $t ) {
			echo '<option value="' . esc_attr( $t ) . '">' . esc_html( ucfirst( $t ) ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Description<textarea name="description" rows="3"></textarea></label>';
		echo '<button type="submit" class="sslms-btn">Create course</button>';
		echo '</form>';
	}

	private static function status_badge( string $status ): string {
		$class = 'muted';
		if ( 'published' === $status ) {
			$class = 'ok';
		} elseif ( 'draft' === $status ) {
			$class = 'warn';
		}
		return '<span class="sslms-badge sslms-badge--' . esc_attr( $class ) . '">' . esc_html( $status ) . '</span>';
	}

	/* -----------------------------------------------------------------
	 * Course editor
	 * ------------------------------------------------------------- */

	private static function render_course_editor( int $course_id ): void {
		$course = SSLMS_Courses::get( $course_id );
		if ( ! $course ) {
			echo '<p>Course not found.</p>';
			return;
		}
		$back = esc_url( add_query_arg( array( 'page' => 'sslms-courses' ), admin_url( 'admin.php' ) ) );
		echo '<p><a href="' . $back . '">&larr; All courses</a></p>';
		echo '<h2>' . esc_html( $course->title ) . ' ' . self::status_badge( $course->status ) . '</h2>';

		echo '<fieldset><legend>Course details</legend>';
		echo '<form data-sslms-endpoint="courses/' . (int) $course_id . '" data-sslms-method="PUT">';
		echo '<label>Title<input type="text" name="title" value="' . esc_attr( $course->title ) . '" required></label>';
		echo '<label>Description<textarea name="description" rows="4">' . esc_textarea( $course->description ) . '</textarea></label>';
		echo '<label>Track<select name="track">';
		foreach ( SSLMS_Courses::TRACKS as $t ) {
			echo '<option value="' . esc_attr( $t ) . '" ' . selected( $course->track, $t, false ) . '>' . esc_html( ucfirst( $t ) ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>Status<select name="status">';
		foreach ( SSLMS_Courses::STATUSES as $s ) {
			echo '<option value="' . esc_attr( $s ) . '" ' . selected( $course->status, $s, false ) . '>' . esc_html( ucfirst( $s ) ) . '</option>';
		}
		echo '</select></label>';
		echo '<label><input type="checkbox" name="open_enrollment" value="1" style="width:auto" ' . checked( $course->open_enrollment, 1, false ) . '> Open enrolment (eligible learners can self-enrol)</label>';
		echo '<button type="submit" class="sslms-btn">Save</button>';
		echo '</form></fieldset>';

		echo '<fieldset><legend>Danger zone</legend>';
		$enrolled = SSLMS_Courses::enrolled_count( $course_id );
		if ( $enrolled > 0 ) {
			echo '<p>This course has ' . (int) $enrolled . ' enrolment(s) and cannot be deleted — set its status to <strong>archived</strong> instead.</p>';
		} else {
			echo '<form data-sslms-endpoint="courses/' . (int) $course_id . '" data-sslms-method="DELETE" onsubmit="if(!confirm(\'Delete this course? This cannot be undone.\')){event.preventDefault();return false;}">'
				. '<button type="submit" class="sslms-btn sslms-btn--danger">Delete course</button></form>';
		}
		echo '</fieldset>';

		self::render_modules( $course_id );
		self::render_enrol_panel( $course_id );
	}

	private static function render_modules( int $course_id ): void {
		echo '<fieldset><legend>Modules &amp; lessons</legend>';
		$modules = SSLMS_Courses::modules( $course_id );

		if ( $modules ) {
			echo '<table class="widefat" data-sslms-reorder-endpoint="courses/' . (int) $course_id . '/modules/reorder">';
			echo '<thead><tr><th>Module</th><th></th></tr></thead><tbody>';
			$n = count( $modules );
			foreach ( $modules as $i => $module ) {
				echo '<tr data-sslms-row-id="' . (int) $module->id . '">';
				echo '<td>';
				echo '<form data-sslms-endpoint="modules/' . (int) $module->id . '" data-sslms-method="PUT" data-sslms-noreload style="margin:0 0 8px">'
					. '<input type="text" name="title" value="' . esc_attr( $module->title ) . '" style="display:inline-block;width:60%">'
					. ' <button type="submit" class="sslms-btn sslms-btn--ghost">Save</button></form>';
				self::render_lessons( $course_id, (int) $module->id );
				echo '</td>';
				echo '<td class="sslms-flex" style="vertical-align:top">';
				echo '<button type="button" class="sslms-btn sslms-btn--ghost" data-sslms-move="up" ' . ( 0 === $i ? 'disabled' : '' ) . '>&uarr;</button>';
				echo '<button type="button" class="sslms-btn sslms-btn--ghost" data-sslms-move="down" ' . ( $i === $n - 1 ? 'disabled' : '' ) . '>&darr;</button>';
				echo '<button type="button" class="sslms-btn sslms-btn--danger" data-sslms-delete-endpoint="modules/' . (int) $module->id . '" data-sslms-confirm="Delete this module and all its lessons?">Delete</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>No modules yet.</p>';
		}

		echo '<h3>Add module</h3>';
		echo '<form data-sslms-endpoint="courses/' . (int) $course_id . '/modules" data-sslms-method="POST">';
		echo '<label>Title<input type="text" name="title" required></label>';
		echo '<button type="submit" class="sslms-btn">Add module</button>';
		echo '</form></fieldset>';
	}

	private static function render_lessons( int $course_id, int $module_id ): void {
		$lessons = SSLMS_Courses::lessons( $module_id );
		if ( $lessons ) {
			echo '<table class="widefat" style="margin:6px 0" data-sslms-reorder-endpoint="modules/' . (int) $module_id . '/lessons/reorder">';
			echo '<thead><tr><th>Lesson</th><th>Video</th><th>Files</th><th></th></tr></thead><tbody>';
			$n = count( $lessons );
			foreach ( $lessons as $i => $lesson ) {
				$edit_url = esc_url( add_query_arg(
					array( 'page' => 'sslms-courses', 'id' => $course_id, 'lesson' => $lesson->id ),
					admin_url( 'admin.php' )
				) );
				echo '<tr data-sslms-row-id="' . (int) $lesson->id . '">';
				echo '<td><a href="' . $edit_url . '">' . esc_html( $lesson->title ) . '</a></td>';
				echo '<td>' . ( $lesson->video_url ? '<span class="sslms-badge sslms-badge--ok">yes</span>' : '<span class="sslms-badge sslms-badge--muted">no</span>' ) . '</td>';
				echo '<td>' . count( SSLMS_Courses::lesson_attachments( $lesson ) ) . '</td>';
				echo '<td class="sslms-flex">';
				echo '<button type="button" class="sslms-btn sslms-btn--ghost" data-sslms-move="up" ' . ( 0 === $i ? 'disabled' : '' ) . '>&uarr;</button>';
				echo '<button type="button" class="sslms-btn sslms-btn--ghost" data-sslms-move="down" ' . ( $i === $n - 1 ? 'disabled' : '' ) . '>&darr;</button>';
				echo '<a class="sslms-btn sslms-btn--ghost" href="' . $edit_url . '">Edit</a>';
				echo '<button type="button" class="sslms-btn sslms-btn--danger" data-sslms-delete-endpoint="lessons/' . (int) $lesson->id . '" data-sslms-confirm="Delete this lesson?">Delete</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$new_url = esc_url( add_query_arg(
			array( 'page' => 'sslms-courses', 'id' => $course_id, 'lesson' => 'new', 'module' => $module_id ),
			admin_url( 'admin.php' )
		) );
		echo '<p><a class="sslms-btn sslms-btn--ghost" href="' . $new_url . '">+ Add lesson</a></p>';
	}

	/* -----------------------------------------------------------------
	 * Enrol panel
	 * ------------------------------------------------------------- */

	private static function render_enrol_panel( int $course_id ): void {
		echo '<fieldset><legend>Enrolment</legend>';
		$roster = SSLMS_Enrollments::for_course( $course_id );
		if ( $roster ) {
			echo '<div class="sslms-table-scroll"><table class="widefat"><thead><tr><th>Learner</th><th>Status</th><th>Enrolled</th><th></th></tr></thead><tbody>';
			foreach ( $roster as $r ) {
				echo '<tr>';
				echo '<td>' . esc_html( $r->display_name ) . '</td>';
				echo '<td>' . self::status_badge( $r->status ) . '</td>';
				echo '<td>' . esc_html( SSLMS_DB::fmt_date( $r->enrolled_at ) ) . '</td>';
				echo '<td>';
				if ( 'withdrawn' !== $r->status ) {
					echo '<form data-sslms-endpoint="courses/' . (int) $course_id . '/enrollments/' . (int) $r->user_id . '" data-sslms-method="DELETE" onsubmit="if(!confirm(\'Withdraw this learner?\')){event.preventDefault();return false;}">'
						. '<button type="submit" class="sslms-btn sslms-btn--danger">Withdraw</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No one is enrolled yet.</p>';
		}

		$learners = get_users( array( 'capability' => array( 'sslms_learn' ), 'orderby' => 'display_name' ) );
		echo '<h3>Enrol a learner</h3>';
		echo '<form data-sslms-endpoint="courses/' . (int) $course_id . '/enrollments" data-sslms-method="POST">';
		echo '<label>Learner<select name="user_id" required><option value="">— choose —</option>';
		foreach ( $learners as $u ) {
			echo '<option value="' . (int) $u->ID . '">' . esc_html( $u->display_name ) . '</option>';
		}
		echo '</select></label>';
		echo '<button type="submit" class="sslms-btn">Enrol</button>';
		echo '</form></fieldset>';
	}

	/* -----------------------------------------------------------------
	 * Lesson editor
	 * ------------------------------------------------------------- */

	private static function render_lesson_editor( int $course_id, string $lesson_param ): void {
		$is_new = ( 'new' === $lesson_param );
		$lesson = null;
		if ( $is_new ) {
			$module_id = isset( $_GET['module'] ) ? absint( wp_unslash( $_GET['module'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! $module_id || SSLMS_Courses::course_id_for_module( $module_id ) !== $course_id ) {
				echo '<p>Module not found.</p>';
				return;
			}
		} else {
			$lesson_id = absint( $lesson_param );
			$lesson    = SSLMS_Courses::get_lesson( $lesson_id );
			if ( ! $lesson || SSLMS_Courses::course_id_for_lesson( $lesson_id ) !== $course_id ) {
				echo '<p>Lesson not found.</p>';
				return;
			}
			$module_id = (int) $lesson->module_id;
		}

		$back = esc_url( add_query_arg( array( 'page' => 'sslms-courses', 'id' => $course_id ), admin_url( 'admin.php' ) ) );
		echo '<p><a href="' . $back . '">&larr; Back to course</a></p>';
		echo '<h2>' . ( $is_new ? 'New lesson' : esc_html( $lesson->title ) ) . '</h2>';

		$title       = $lesson ? $lesson->title : '';
		$content     = $lesson ? $lesson->content : '';
		$video_url   = $lesson ? $lesson->video_url : '';
		$est_minutes = $lesson ? (int) $lesson->est_minutes : 0;
		$attachments = $lesson ? SSLMS_Courses::lesson_attachments( $lesson ) : array();

		echo '<form id="sslms-lesson-form" data-sslms-course="' . (int) $course_id . '" data-sslms-module="' . (int) $module_id . '"'
			. ( $lesson ? ' data-sslms-lesson="' . (int) $lesson->id . '"' : '' ) . '>';

		echo '<label>Title<input type="text" id="sslms-lesson-title" value="' . esc_attr( $title ) . '" required></label>';

		echo '<label>Content</label>';
		wp_editor( $content, 'sslms_lesson_content', array(
			'textarea_name' => 'sslms_lesson_content',
			'media_buttons' => false,
			'textarea_rows' => 14,
		) );

		echo '<label>Video URL (YouTube, Vimeo, or a direct video file link)<input type="text" id="sslms-lesson-video" value="' . esc_attr( $video_url ) . '" placeholder="https://…"></label>';
		echo '<label>Estimated minutes<input type="number" id="sslms-lesson-minutes" min="0" value="' . (int) $est_minutes . '" style="max-width:120px"></label>';

		echo '<label>Attachments</label>';
		echo '<ul id="sslms-attachment-list" style="list-style:none;padding:0">';
		foreach ( $attachments as $att_id ) {
			$name = get_the_title( $att_id ) ?: ( 'Attachment #' . $att_id );
			echo '<li data-id="' . (int) $att_id . '" style="display:flex;align-items:center;gap:8px;padding:4px 0">'
				. '<span>' . esc_html( $name ) . '</span>'
				. '<button type="button" class="sslms-btn sslms-btn--ghost sslms-attachment-remove">Remove</button></li>';
		}
		echo '</ul>';
		echo '<button type="button" id="sslms-attachment-add" class="sslms-btn sslms-btn--ghost">Add attachment</button>';

		echo '<p style="margin-top:16px"><button type="submit" class="sslms-btn">Save lesson</button></p>';
		echo '</form>';
	}

	/* -----------------------------------------------------------------
	 * Glue JS
	 * ------------------------------------------------------------- */

	private static function inline_script(): void {
		?>
		<script>
		(function () {
			document.addEventListener('click', function (ev) {
				var del = ev.target.closest('[data-sslms-delete-endpoint]');
				if (del) {
					var msg = del.getAttribute('data-sslms-confirm') || 'Delete this?';
					if (!confirm(msg)) { return; }
					SSLMS.api(del.getAttribute('data-sslms-delete-endpoint'), { method: 'DELETE' })
						.then(function () { window.location.reload(); })
						.catch(function (e) { alert(e.message); });
					return;
				}
				var move = ev.target.closest('[data-sslms-move]');
				if (move) {
					var row   = move.closest('tr');
					var table = move.closest('table[data-sslms-reorder-endpoint]');
					if (!row || !table) { return; }
					var dir = move.getAttribute('data-sslms-move');
					var sib = dir === 'up' ? row.previousElementSibling : row.nextElementSibling;
					if (!sib) { return; }
					dir === 'up' ? row.parentNode.insertBefore(row, sib) : row.parentNode.insertBefore(sib, row);
					var ids = Array.prototype.map.call(table.querySelectorAll('[data-sslms-row-id]'), function (r) {
						return r.getAttribute('data-sslms-row-id');
					});
					SSLMS.api(table.getAttribute('data-sslms-reorder-endpoint'), { method: 'POST', body: { order: ids } })
						.then(function () { window.location.reload(); })
						.catch(function (e) { alert(e.message); });
					return;
				}
				if (ev.target.id === 'sslms-attachment-add') {
					if (typeof wp === 'undefined' || !wp.media) { return; }
					var frame = wp.media({ title: 'Select attachment(s)', multiple: true, library: {} });
					frame.on('select', function () {
						var list = document.getElementById('sslms-attachment-list');
						frame.state().get('selection').each(function (att) {
							var data = att.toJSON();
							if (list.querySelector('li[data-id="' + data.id + '"]')) { return; }
							var li = document.createElement('li');
							li.setAttribute('data-id', data.id);
							li.style.cssText = 'display:flex;align-items:center;gap:8px;padding:4px 0';
							li.innerHTML = '<span>' + (data.title || data.filename || ('Attachment #' + data.id)) + '</span>'
								+ '<button type="button" class="sslms-btn sslms-btn--ghost sslms-attachment-remove">Remove</button>';
							list.appendChild(li);
						});
					});
					frame.open();
					return;
				}
				var rm = ev.target.closest('.sslms-attachment-remove');
				if (rm) {
					rm.closest('li').remove();
				}
			});

			var form = document.getElementById('sslms-lesson-form');
			if (form) {
				form.addEventListener('submit', function (ev) {
					ev.preventDefault();
					if (typeof tinymce !== 'undefined') {
						var ed = tinymce.get('sslms_lesson_content');
						if (ed) { ed.save(); }
					}
					var contentEl = document.getElementById('sslms_lesson_content');
					var attachments = Array.prototype.map.call(
						document.querySelectorAll('#sslms-attachment-list li'),
						function (li) { return parseInt(li.getAttribute('data-id'), 10); }
					);
					var body = {
						title: document.getElementById('sslms-lesson-title').value,
						content: contentEl ? contentEl.value : '',
						video_url: document.getElementById('sslms-lesson-video').value,
						est_minutes: parseInt(document.getElementById('sslms-lesson-minutes').value, 10) || 0,
						attachments: attachments
					};
					var lessonId = form.getAttribute('data-sslms-lesson');
					var endpoint = lessonId ? ('lessons/' + lessonId) : ('modules/' + form.getAttribute('data-sslms-module') + '/lessons');
					var method   = lessonId ? 'PUT' : 'POST';
					var btn = form.querySelector('[type=submit]');
					if (btn) { btn.disabled = true; }
					SSLMS.api(endpoint, { method: method, body: body }).then(function (data) {
						var course = form.getAttribute('data-sslms-course');
						var lid = lessonId || (data && data.id);
						window.location.href = window.location.pathname
							+ '?page=sslms-courses&id=' + course + (lid ? ('&lesson=' + lid) : '');
					}).catch(function (e) {
						alert(e.message);
						if (btn) { btn.disabled = false; }
					});
				});
			}
		})();
		</script>
		<?php
	}
}
SSLMS_Admin_Courses::init();
