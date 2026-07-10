<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module C — certificates (SPEC F5). Auto-issued on course completion via
 * the sslms_course_completed hook; one certificate per enrollment.
 */
class SSLMS_Certificates {

	public static function init(): void {
		add_action( 'sslms_course_completed', array( __CLASS__, 'issue' ) );
	}

	/** Issues a certificate for a completed enrollment. Idempotent. */
	public static function issue( $enrollment ): void {
		if ( ! is_object( $enrollment ) || empty( $enrollment->id ) ) {
			return;
		}
		$enrollment_id = (int) $enrollment->id;

		if ( SSLMS_DB::get_row_by( 'certificates', 'enrollment_id', $enrollment_id ) ) {
			return; // Already issued.
		}

		global $wpdb;
		$t    = SSLMS_DB::table( 'certificates' );
		$code = '';
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$candidate = strtoupper( wp_generate_password( 12, false ) );
			$clash     = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE cert_code = %s", $candidate ) );
			if ( ! $clash ) {
				$code = $candidate;
				break;
			}
		}
		if ( '' === $code ) {
			return; // Exhausted retries — extremely unlikely with 12 alnum chars.
		}

		$id = SSLMS_DB::insert(
			'certificates',
			array(
				'enrollment_id' => $enrollment_id,
				'cert_code'     => $code,
				'issued_at'     => SSLMS_DB::now(),
			)
		);

		if ( $id ) {
			SSLMS_Audit::log(
				'certificate_issued',
				'certificate',
				$id,
				sprintf( 'Certificate %s issued for enrollment #%d', $code, $enrollment_id )
			);
		}
	}

	/** Certificate row for a given enrollment, or null. */
	public static function by_enrollment( int $enrollment_id ): ?object {
		return SSLMS_DB::get_row_by( 'certificates', 'enrollment_id', $enrollment_id );
	}

	/** All certificates for a user, most recent first, joined to their course. */
	public static function by_user( int $user_id ): array {
		global $wpdb;
		$cert_t   = SSLMS_DB::table( 'certificates' );
		$enr_t    = SSLMS_DB::table( 'enrollments' );
		$course_t = SSLMS_DB::table( 'courses' );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.cert_code, c.issued_at, e.id AS enrollment_id, e.completed_at,
			        co.id AS course_id, co.title AS course_title
			 FROM {$cert_t} c
			 INNER JOIN {$enr_t} e ON e.id = c.enrollment_id
			 INNER JOIN {$course_t} co ON co.id = e.course_id
			 WHERE e.user_id = %d
			 ORDER BY c.issued_at DESC",
			$user_id
		) );
	}

	/** Certificate + enrollment + course + learner, by verification code. Null if not found. */
	public static function by_code( string $code ): ?object {
		global $wpdb;
		$code     = strtoupper( sanitize_text_field( $code ) );
		$cert_t   = SSLMS_DB::table( 'certificates' );
		$enr_t    = SSLMS_DB::table( 'enrollments' );
		$course_t = SSLMS_DB::table( 'courses' );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.id, c.cert_code, c.issued_at, e.id AS enrollment_id, e.completed_at,
			        co.id AS course_id, co.title AS course_title,
			        u.ID AS user_id, u.display_name
			 FROM {$cert_t} c
			 INNER JOIN {$enr_t} e ON e.id = c.enrollment_id
			 INNER JOIN {$course_t} co ON co.id = e.course_id
			 INNER JOIN {$wpdb->users} u ON u.ID = e.user_id
			 WHERE c.cert_code = %s",
			$code
		) );

		return $row ?: null;
	}
}
SSLMS_Certificates::init();
