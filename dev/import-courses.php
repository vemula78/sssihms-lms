<?php
/**
 * Imports courses from a courses.json produced by the docx parser.
 * Idempotent: skips any course whose title already exists.
 * Run: wp eval-file import-courses.php <path-to-courses.json> [--url=site]
 */

$json_path = $args[0] ?? '/tmp/courses.json';
if ( ! file_exists( $json_path ) ) {
	echo "courses.json not found at $json_path\n";
	exit( 1 );
}
$courses = json_decode( file_get_contents( $json_path ), true );
if ( ! is_array( $courses ) ) {
	echo "Could not parse courses.json\n";
	exit( 1 );
}

$creator = get_user_by( 'login', 'demoinstructor' ) ?: get_user_by( 'login', 'admin' ) ?: get_user_by( 'login', 'demo' );
if ( ! $creator ) {
	echo "No instructor/admin user found to own the courses.\n";
	exit( 1 );
}
$creator_id = $creator->ID;

global $wpdb;
$courses_t = SSLMS_DB::table( 'courses' );

foreach ( $courses as $c ) {
	$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$courses_t} WHERE title = %s", $c['title'] ) );
	if ( $exists ) {
		echo "SKIP (exists): {$c['title']}\n";
		continue;
	}
	$course_id = SSLMS_Courses::create( array(
		'title'           => $c['title'],
		'description'     => 'Google AI for Professionals series. Videos stream from Google Drive (read-only).',
		'track'           => 'allied',
		'status'          => 'published',
		'open_enrollment' => 1,
	), $creator_id );
	if ( is_wp_error( $course_id ) ) {
		echo "FAIL course {$c['title']}: " . $course_id->get_error_message() . "\n";
		continue;
	}
	$n_lessons = 0;
	foreach ( $c['modules'] as $m ) {
		$mod_id = SSLMS_Courses::create_module( $course_id, $m['title'] );
		if ( is_wp_error( $mod_id ) ) {
			echo "  FAIL module {$m['title']}\n";
			continue;
		}
		foreach ( $m['lessons'] as $l ) {
			$res = SSLMS_Courses::create_lesson( $mod_id, array(
				'title'       => $l['title'],
				'content'     => $l['content'],
				'video_url'   => $l['video'],
				'est_minutes' => $l['video'] ? 8 : 5,
			) );
			if ( is_wp_error( $res ) ) {
				echo "  FAIL lesson {$l['title']}: " . $res->get_error_message() . "\n";
			} else {
				$n_lessons++;
			}
		}
	}
	echo "CREATED: {$c['title']} (" . count( $c['modules'] ) . " modules, $n_lessons lessons)\n";
}
echo "Import done.\n";
