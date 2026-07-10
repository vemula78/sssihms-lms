<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LMS roles and capabilities.
 *
 * Capabilities:
 *  sslms_manage             — LMS administration (people, settings, doc verification, unlock checklists)
 *  sslms_author_courses     — create/edit courses, lessons, quizzes, question banks, checklist templates
 *  sslms_enroll_learners    — enrol users, assign checklists/rotations
 *  sslms_view_reports       — reports & analytics dashboard (instructor: own courses; sslms_manage: all)
 *  sslms_learn              — learner portal (courses, quizzes, hours, journal, documents, certificates)
 *  sslms_signoff_checklists — rate/sign checklist items for assigned learners
 *  sslms_approve_hours      — approve/reject hour logs for assigned learners
 *  sslms_mentor_journals    — read & comment on mentor-visible journal entries of assigned mentees
 *  sslms_view_audit         — audit-log viewer (admin only by default)
 */
class SSLMS_Roles {

	const ALL_CAPS = array(
		'sslms_manage',
		'sslms_author_courses',
		'sslms_enroll_learners',
		'sslms_view_reports',
		'sslms_learn',
		'sslms_signoff_checklists',
		'sslms_approve_hours',
		'sslms_mentor_journals',
		'sslms_view_audit',
	);

	public static function roles(): array {
		return array(
			'sslms_student'    => array(
				'name' => 'LMS Student',
				'caps' => array( 'read', 'sslms_learn' ),
			),
			'sslms_instructor' => array(
				'name' => 'LMS Instructor',
				'caps' => array( 'read', 'sslms_learn', 'sslms_author_courses', 'sslms_enroll_learners', 'sslms_view_reports' ),
			),
			'sslms_preceptor'  => array(
				'name' => 'LMS Supervisor/Preceptor',
				'caps' => array( 'read', 'sslms_learn', 'sslms_signoff_checklists', 'sslms_approve_hours' ),
			),
			'sslms_evaluator'  => array(
				'name' => 'LMS Evaluator',
				'caps' => array( 'read', 'sslms_learn', 'sslms_signoff_checklists' ),
			),
			'sslms_mentor'     => array(
				'name' => 'LMS Mentor/Spiritual Director',
				'caps' => array( 'read', 'sslms_learn', 'sslms_mentor_journals' ),
			),
		);
	}

	public static function install(): void {
		foreach ( self::roles() as $slug => $def ) {
			remove_role( $slug );
			add_role( $slug, $def['name'], array_fill_keys( $def['caps'], true ) );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::ALL_CAPS as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	public static function uninstall_roles(): void {
		foreach ( array_keys( self::roles() ) as $slug ) {
			remove_role( $slug );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::ALL_CAPS as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}
}
