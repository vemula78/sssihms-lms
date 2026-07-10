<?php
/**
 * Uninstall: data is preserved by default (NABH retention). Tables and options
 * are removed only when the admin enabled "delete data on uninstall".
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! get_option( 'sslms_delete_on_uninstall' ) ) {
	return;
}

global $wpdb;
$tables = array(
	'courses', 'modules', 'lessons', 'enrollments', 'question_banks', 'questions',
	'quizzes', 'quiz_attempts', 'lesson_progress', 'certificates', 'checklists',
	'checklist_items', 'checklist_assignments', 'checklist_signoffs', 'rotations',
	'hour_logs', 'journal_entries', 'journal_comments', 'relationships', 'documents',
	'profiles', 'audit_log',
);
foreach ( $tables as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sslms_{$t}" );
}
delete_option( 'sslms_db_version' );
delete_option( 'sslms_pages' );
delete_option( 'sslms_delete_on_uninstall' );

require_once __DIR__ . '/includes/class-sslms-roles.php';
SSLMS_Roles::uninstall_roles();
