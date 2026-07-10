<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [sslms_my_journal] — Module E learner portal page. Renders own entries
 * (with a create/edit form and delete), a comments thread under any entry
 * that has comments, and (for mentors/instructors) a "Shared with me"
 * section. All mutations go through the gated REST routes in
 * class-sslms-rest-journals.php; this file only ever reads through
 * SSLMS_Journals' gated helpers, never raw SQL.
 */
class SSLMS_Portal_Journal {

	const VISIBILITY_LABELS = array(
		'private'    => 'Private',
		'mentor'     => 'Mentor-visible',
		'instructor' => 'Instructor-visible',
	);

	const VISIBILITY_HELP = array(
		'private'    => 'Only you can see this entry.',
		'mentor'     => 'You and your assigned mentor(s) can see and comment on this entry.',
		'instructor' => 'You and the instructor(s) of course(s) you are enrolled in can see and comment on this entry.',
	);

	public static function init(): void {
		add_shortcode( 'sslms_my_journal', array( __CLASS__, 'shortcode' ) );
		add_filter( 'sslms_dashboard_cards', array( __CLASS__, 'dashboard_card' ) );
	}

	public static function shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return SSLMS_Portal::wrap( 'My Journal', '' );
		}
		$user_id = get_current_user_id();

		$editing_entry = null;
		$edit_id       = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		if ( $edit_id ) {
			$row = SSLMS_Journals::get_entry_row( $edit_id );
			if ( $row && (int) $row->user_id === $user_id ) {
				$editing_entry = $row;
			}
		}

		$html  = self::render_editor( $editing_entry );
		$html .= self::render_own_entries( $user_id );

		if ( user_can( $user_id, 'sslms_mentor_journals' ) || user_can( $user_id, 'sslms_author_courses' ) ) {
			$html .= self::render_shared_with_me( $user_id );
		}

		return SSLMS_Portal::wrap( 'My Journal', $html );
	}

	private static function render_editor( ?object $entry ): string {
		$is_edit  = null !== $entry;
		$endpoint = $is_edit ? 'journals/' . (int) $entry->id : 'journals';
		$method   = $is_edit ? 'PUT' : 'POST';
		$title    = $is_edit ? esc_attr( $entry->title ) : '';
		$body     = $is_edit ? esc_textarea( $entry->body ) : '';
		$vis      = $is_edit ? $entry->visibility : 'private';

		$out  = '<section class="sslms-journal-editor">';
		$out .= '<h2>' . ( $is_edit ? 'Edit entry' : 'New entry' ) . '</h2>';
		$out .= '<form data-sslms-endpoint="' . esc_attr( $endpoint ) . '" data-sslms-method="' . esc_attr( $method ) . '">';
		$out .= '<label for="sslms-jrn-title">Title</label>';
		$out .= '<input type="text" id="sslms-jrn-title" name="title" maxlength="200" required value="' . $title . '" />';
		$out .= '<label for="sslms-jrn-body">Entry</label>';
		$out .= '<textarea id="sslms-jrn-body" name="body" rows="6" required>' . $body . '</textarea>';
		$out .= '<label for="sslms-jrn-visibility">Who can see this entry?</label>';
		$out .= '<select id="sslms-jrn-visibility" name="visibility">';
		foreach ( self::VISIBILITY_LABELS as $key => $label ) {
			$out .= '<option value="' . esc_attr( $key ) . '"' . selected( $vis, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$out .= '</select>';
		$out .= '<ul class="sslms-journal-help">';
		foreach ( self::VISIBILITY_HELP as $key => $help ) {
			$out .= '<li><strong>' . esc_html( self::VISIBILITY_LABELS[ $key ] ) . ':</strong> ' . esc_html( $help ) . '</li>';
		}
		$out .= '</ul>';
		$out .= '<button type="submit" class="sslms-btn">' . ( $is_edit ? 'Save changes' : 'Save entry' ) . '</button>';
		if ( $is_edit ) {
			$out .= ' <a class="sslms-btn sslms-btn--ghost" href="' . esc_url( SSLMS_Portal::page_url( 'my_journal' ) ) . '">Cancel</a>';
		}
		$out .= '</form></section>';
		return $out;
	}

	private static function render_comment_thread( int $entry_id, int $viewer_id, bool $show_reply_box ): string {
		$comments = SSLMS_Journals::list_comments( $entry_id, $viewer_id );
		if ( is_wp_error( $comments ) ) {
			return '';
		}
		$out = '';
		if ( $comments ) {
			$out .= '<div class="sslms-journal-comments"><h4>Comments</h4><ul>';
			foreach ( $comments as $c ) {
				$author = get_userdata( $c->author_id );
				$name   = $author ? $author->display_name : ( 'User #' . $c->author_id );
				$out   .= '<li><strong>' . esc_html( $name ) . '</strong> — <span class="sslms-comment-date">' . esc_html( SSLMS_DB::fmt_date( $c->created_at ) ) . '</span><div>' . wp_kses_post( $c->body ) . '</div></li>';
			}
			$out .= '</ul></div>';
		}
		if ( $show_reply_box ) {
			$out .= '<form class="sslms-journal-comment-form" data-sslms-endpoint="journals/' . (int) $entry_id . '/comments" data-sslms-method="POST">';
			$out .= '<label for="sslms-comment-' . (int) $entry_id . '" class="screen-reader-text">Add a comment</label>';
			$out .= '<textarea id="sslms-comment-' . (int) $entry_id . '" name="body" rows="2" placeholder="Add a comment…" required></textarea>';
			$out .= '<button type="submit" class="sslms-btn sslms-btn--ghost">Comment</button>';
			$out .= '</form>';
		}
		return $out;
	}

	private static function render_own_entries( int $user_id ): string {
		$entries = SSLMS_Journals::list_own( $user_id );
		$out     = '<section class="sslms-journal-list"><h2>My entries</h2>';
		if ( ! $entries ) {
			$out .= '<p>No journal entries yet.</p>';
		}
		foreach ( $entries as $entry ) {
			$out .= '<article class="sslms-journal-entry">';
			$out .= '<h3>' . esc_html( $entry->title ) . '</h3>';
			$out .= '<p><span class="sslms-badge sslms-badge--muted">' . esc_html( self::VISIBILITY_LABELS[ $entry->visibility ] ?? $entry->visibility ) . '</span> ';
			$out .= '<span class="sslms-entry-date">' . esc_html( SSLMS_DB::fmt_date( $entry->created_at ) ) . '</span></p>';
			$out .= '<div class="sslms-journal-body">' . wp_kses_post( $entry->body ) . '</div>';
			$out .= '<p><a href="' . esc_url( SSLMS_Portal::page_url( 'my_journal', array( 'edit' => $entry->id ) ) ) . '">Edit</a> ';
			$out .= '<form style="display:inline" data-sslms-endpoint="journals/' . (int) $entry->id . '" data-sslms-method="DELETE" onsubmit="return confirm(\'Delete this entry? This cannot be undone.\')"><button type="submit" class="sslms-btn sslms-btn--danger">Delete</button></form></p>';
			$out .= self::render_comment_thread( (int) $entry->id, $user_id, true );
			$out .= '</article>';
		}
		$out .= '</section>';
		return $out;
	}

	private static function render_shared_with_me( int $viewer_id ): string {
		$entries = SSLMS_Journals::list_shared_with( $viewer_id );
		$out     = '<section class="sslms-journal-shared"><h2>Shared with me</h2>';
		if ( ! $entries ) {
			$out .= '<p>No entries have been shared with you.</p>';
		}
		foreach ( $entries as $entry ) {
			$author = get_userdata( $entry->user_id );
			$name   = $author ? $author->display_name : ( 'User #' . $entry->user_id );
			$out   .= '<article class="sslms-journal-entry">';
			$out   .= '<h3>' . esc_html( $entry->title ) . '</h3>';
			$out   .= '<p><strong>' . esc_html( $name ) . '</strong> · <span class="sslms-badge sslms-badge--muted">' . esc_html( self::VISIBILITY_LABELS[ $entry->visibility ] ?? $entry->visibility ) . '</span> ';
			$out   .= '<span class="sslms-entry-date">' . esc_html( SSLMS_DB::fmt_date( $entry->created_at ) ) . '</span></p>';
			$out   .= '<div class="sslms-journal-body">' . wp_kses_post( $entry->body ) . '</div>';
			$out   .= self::render_comment_thread( (int) $entry->id, $viewer_id, true );
			$out   .= '</article>';
		}
		$out .= '</section>';
		return $out;
	}

	/** Dashboard card: comments on my entries in the last 7 days. */
	public static function dashboard_card( array $cards ): array {
		if ( ! is_user_logged_in() ) {
			return $cards;
		}
		$count = SSLMS_Journals::new_comment_count_last7( get_current_user_id() );
		if ( $count > 0 ) {
			$cards[] = array(
				'title' => 'Journal',
				'html'  => '<p>' . esc_html( $count ) . ' new comment(s) on your journal entries in the last 7 days. '
					. '<a href="' . esc_url( SSLMS_Portal::page_url( 'my_journal' ) ) . '">View journal</a></p>',
			);
		}
		return $cards;
	}
}
SSLMS_Portal_Journal::init();
