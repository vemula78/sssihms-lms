<?php
/**
 * Imports courses from the JSON format documented in COURSE_IMPORT_FORMAT.md.
 * Idempotent: skips any course whose title already exists.
 * Run: wp eval-file import-courses.php <path-to-courses.json> [--url=site]
 *
 * Every payload is validated before writes begin. Each course is then created
 * as a draft inside a transaction and published only after all of its modules
 * and lessons have been created successfully.
 */

/** Validate and normalize a decoded course-import payload. */
function sslms_normalize_course_import( $payload ) {
	if ( ! is_array( $payload ) || array_values( $payload ) !== $payload ) {
		return new WP_Error( 'sslms_import_invalid', 'The top-level JSON value must be an array of courses.' );
	}

	$normalized = array();
	foreach ( $payload as $course_index => $course ) {
		$course_path = 'courses[' . $course_index . ']';
		if ( ! is_array( $course ) ) {
			return new WP_Error( 'sslms_import_invalid', $course_path . ' must be an object.' );
		}
		$title = isset( $course['title'] ) && is_string( $course['title'] ) ? trim( $course['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'sslms_import_invalid', $course_path . '.title is required.' );
		}
		if ( ! isset( $course['modules'] ) || ! is_array( $course['modules'] ) || array_values( $course['modules'] ) !== $course['modules'] || ! $course['modules'] ) {
			return new WP_Error( 'sslms_import_invalid', $course_path . '.modules must be a non-empty array.' );
		}
		foreach ( array( 'description', 'track' ) as $field ) {
			if ( isset( $course[ $field ] ) && ! is_string( $course[ $field ] ) ) {
				return new WP_Error( 'sslms_import_invalid', $course_path . '.' . $field . ' must be a string.' );
			}
		}
		if ( isset( $course['track'] ) && $course['track'] && ! in_array( $course['track'], array( 'clinical', 'allied', 'chaplaincy' ), true ) ) {
			return new WP_Error( 'sslms_import_invalid', $course_path . '.track must be one of clinical, allied, chaplaincy.' );
		}

		$modules = array();
		foreach ( $course['modules'] as $module_index => $module ) {
			$module_path = $course_path . '.modules[' . $module_index . ']';
			if ( ! is_array( $module ) ) {
				return new WP_Error( 'sslms_import_invalid', $module_path . ' must be an object.' );
			}
			$module_title = isset( $module['title'] ) && is_string( $module['title'] ) ? trim( $module['title'] ) : '';
			if ( '' === $module_title ) {
				return new WP_Error( 'sslms_import_invalid', $module_path . '.title is required.' );
			}
			if ( ! isset( $module['lessons'] ) || ! is_array( $module['lessons'] ) || array_values( $module['lessons'] ) !== $module['lessons'] || ! $module['lessons'] ) {
				return new WP_Error( 'sslms_import_invalid', $module_path . '.lessons must be a non-empty array.' );
			}

			$lessons = array();
			foreach ( $module['lessons'] as $lesson_index => $lesson ) {
				$lesson_path = $module_path . '.lessons[' . $lesson_index . ']';
				if ( ! is_array( $lesson ) ) {
					return new WP_Error( 'sslms_import_invalid', $lesson_path . ' must be an object.' );
				}
				$lesson_title = isset( $lesson['title'] ) && is_string( $lesson['title'] ) ? trim( $lesson['title'] ) : '';
				if ( '' === $lesson_title ) {
					return new WP_Error( 'sslms_import_invalid', $lesson_path . '.title is required.' );
				}
				foreach ( array( 'content', 'video' ) as $field ) {
					if ( isset( $lesson[ $field ] ) && ! is_string( $lesson[ $field ] ) ) {
						return new WP_Error( 'sslms_import_invalid', $lesson_path . '.' . $field . ' must be a string.' );
					}
				}
				$lessons[] = array(
					'title'   => $lesson_title,
					'content' => $lesson['content'] ?? '',
					'video'   => $lesson['video'] ?? '',
				);
			}
			$modules[] = array( 'title' => $module_title, 'lessons' => $lessons );
		}
		$normalized[] = array(
			'title'       => $title,
			'description' => isset( $course['description'] ) ? trim( $course['description'] ) : '',
			'track'       => $course['track'] ?? '',
			'modules'     => $modules,
		);
	}

	return $normalized;
}

$json_path = $args[0] ?? '/tmp/courses.json';
if ( ! is_readable( $json_path ) ) {
	echo "courses.json not found or unreadable at $json_path\n";
	exit( 1 );
}

$decoded = json_decode( file_get_contents( $json_path ), true );
if ( JSON_ERROR_NONE !== json_last_error() ) {
	echo 'Could not parse courses.json: ' . json_last_error_msg() . "\n";
	exit( 1 );
}
$courses = sslms_normalize_course_import( $decoded );
if ( is_wp_error( $courses ) ) {
	echo 'Invalid courses.json: ' . $courses->get_error_message() . "\n";
	exit( 1 );
}

$creator = get_user_by( 'login', 'demoinstructor' ) ?: get_user_by( 'login', 'admin' ) ?: get_user_by( 'login', 'demo' );
if ( ! $creator || ( ! user_can( $creator, 'sslms_author_courses' ) && ! user_can( $creator, 'sslms_manage' ) ) ) {
	echo "No instructor/admin user with course-authoring permission found.\n";
	exit( 1 );
}
$creator_id = (int) $creator->ID;
wp_set_current_user( $creator_id );

global $wpdb;
$courses_t = SSLMS_DB::table( 'courses' );

foreach ( $courses as $course ) {
	$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$courses_t} WHERE title = %s", $course['title'] ) );
	if ( $exists ) {
		echo "SKIP (exists): {$course['title']}\n";
		continue;
	}

	if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
		echo "FAIL course {$course['title']}: database transactions are unavailable\n";
		continue;
	}
	try {
		$course_id = SSLMS_Courses::create( array(
			'title'           => $course['title'],
			'description'     => $course['description'] ?: 'Videos stream from Google Drive (read-only).',
			'track'           => $course['track'] ?: 'allied',
			'status'          => 'draft',
			'open_enrollment' => 1,
		), $creator_id );
		if ( is_wp_error( $course_id ) ) {
			throw new RuntimeException( $course_id->get_error_message() );
		}

		$n_lessons = 0;
		foreach ( $course['modules'] as $module ) {
			$module_id = SSLMS_Courses::create_module( $course_id, $module['title'] );
			if ( is_wp_error( $module_id ) ) {
				throw new RuntimeException( 'Module "' . $module['title'] . '": ' . $module_id->get_error_message() );
			}
			foreach ( $module['lessons'] as $lesson ) {
				$result = SSLMS_Courses::create_lesson( $module_id, array(
					'title'       => $lesson['title'],
					'content'     => $lesson['content'],
					'video_url'   => $lesson['video'],
					'est_minutes' => $lesson['video'] ? 8 : 5,
				) );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( 'Lesson "' . $lesson['title'] . '": ' . $result->get_error_message() );
				}
				$n_lessons++;
			}
		}

		$published = SSLMS_Courses::update( $course_id, array( 'status' => 'published' ) );
		if ( is_wp_error( $published ) ) {
			throw new RuntimeException( $published->get_error_message() );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new RuntimeException( 'Could not commit the course import.' );
		}
		echo "CREATED: {$course['title']} (" . count( $course['modules'] ) . " modules, $n_lessons lessons)\n";
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' );
		echo "FAIL course {$course['title']}: " . $error->getMessage() . " (rolled back)\n";
	}
}
echo "Import done.\n";
