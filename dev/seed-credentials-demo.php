<?php
/**
 * Seeds synthetic credential documents (real, viewable one-page PDFs marked
 * SPECIMEN) for every demo learner, plus course-completion certificates, so
 * the Documents / My Certificates pages, compliance KPI, and expiry forecast
 * all show data. Idempotent per (user, doc_type). Run:
 *   wp --path=/srv/www/wordpress eval-file seed-credentials-demo.php --url=demo.sssihms.org
 */

global $wpdb;
$admin_user = get_user_by( 'login', 'demo' ) ?: get_user_by( 'login', 'admin' );
$admin      = $admin_user ? (int) $admin_user->ID : 0;
wp_set_current_user( $admin );

/** Minimal valid single-page PDF with two lines of text. */
function seedpdf( string $title, string $subtitle ): string {
	$esc = static function ( $s ) {
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\(', '\)' ), $s );
	};
	$stream  = "BT /F1 20 Tf 60 720 Td (" . $esc( $title ) . ") Tj ET\n";
	$stream .= "BT /F1 12 Tf 60 690 Td (" . $esc( $subtitle ) . ") Tj ET\n";
	$stream .= "BT /F1 10 Tf 60 660 Td (SPECIMEN - synthetic demo document, not a real credential.) Tj ET";
	$objs    = array(
		"<< /Type /Catalog /Pages 2 0 R >>",
		"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
		"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
		"<< /Length " . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream",
		"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
	);
	$pdf     = "%PDF-1.4\n";
	$offsets = array();
	foreach ( $objs as $i => $body ) {
		$offsets[] = strlen( $pdf );
		$pdf      .= ( $i + 1 ) . " 0 obj\n" . $body . "\nendobj\n";
	}
	$xref = strlen( $pdf );
	$pdf .= "xref\n0 " . ( count( $objs ) + 1 ) . "\n0000000000 65535 f \n";
	foreach ( $offsets as $off ) {
		$pdf .= sprintf( "%010d 00000 n \n", $off );
	}
	$pdf .= "trailer\n<< /Size " . ( count( $objs ) + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
	return $pdf;
}

$doc_defs = array(
	// type => [display name, issue offset, expiry offset (null = no expiry)]
	'license'      => array( 'Professional Registration Certificate', '-14 months', '+22 months' ),
	'bls'          => array( 'Basic Life Support (BLS) Certificate', '-23 months', '+25 days' ),   // feeds the ≤30-day forecast
	'acls'         => array( 'Advanced Cardiovascular Life Support (ACLS)', '-16 months', '+75 days' ), // feeds the ≤90-day forecast
	'immunization' => array( 'Immunization Record (Hep B / Influenza)', '-4 months', '+14 months' ),
	'background'   => array( 'Background Verification Letter', '-30 months', null ),
	'other'        => array( 'Fire & Safety Training Certificate', '-5 months', '+7 months' ),
);

$learners = get_users( array( 'capability' => 'sslms_learn', 'orderby' => 'user_login' ) );
$docs_t   = SSLMS_DB::table( 'documents' );
$base     = trailingslashit( wp_upload_dir()['basedir'] ) . 'sslms-private';
$created  = 0;

foreach ( $learners as $u ) {
	$uid = (int) $u->ID;
	foreach ( $doc_defs as $type => $def ) {
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$docs_t} WHERE user_id = %d AND doc_type = %s", $uid, $type
		) );
		if ( $exists ) {
			continue;
		}
		// demostudent2 gets an expired immunization record — keeps the
		// "Expired documents" card non-zero and the demo honest.
		$expiry = $def[2];
		if ( 'immunization' === $type && 'demostudent2' === $u->user_login ) {
			$expiry = '-20 days';
		}
		$dir = $base . '/' . $uid;
		wp_mkdir_p( $dir );
		$filename = wp_generate_password( 20, false ) . '.pdf';
		file_put_contents( $dir . '/' . $filename, seedpdf( $def[0], 'Holder: ' . $u->display_name . ' (fictional) - SSSIHMS LMS demo' ) );
		$wpdb->insert( $docs_t, array(
			'user_id'       => $uid,
			'doc_type'      => $type,
			'file_path'     => 'sslms-private/' . $uid . '/' . $filename,
			'original_name' => sanitize_file_name( strtolower( str_replace( ' ', '-', $def[0] ) ) . '.pdf' ),
			'mime'          => 'application/pdf',
			'issue_date'    => gmdate( 'Y-m-d', strtotime( $def[1] ) ),
			'expiry_date'   => $expiry ? gmdate( 'Y-m-d', strtotime( $expiry ) ) : null,
			'verified_by'   => $admin,
			'verified_at'   => SSLMS_DB::now(),
			'uploaded_at'   => SSLMS_DB::now(),
		) );
		$created++;
	}
}
echo "Documents created: {$created} (for " . count( $learners ) . " learners)\n";

/* ------------------------------------------------------------------
 * Course-completion certificates: ensure each demo student has one.
 * ---------------------------------------------------------------- */
$enr_t  = SSLMS_DB::table( 'enrollments' );
$cert_t = SSLMS_DB::table( 'certificates' );
foreach ( array( 'demostudent1', 'demostudent2' ) as $login ) {
	$u = get_user_by( 'login', $login );
	if ( ! $u ) {
		continue;
	}
	$has = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$cert_t} c JOIN {$enr_t} e ON e.id = c.enrollment_id WHERE e.user_id = %d", $u->ID
	) );
	if ( $has ) {
		echo "SKIP certificates: {$login} already has {$has}\n";
		continue;
	}
	$enrollment = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$enr_t} WHERE user_id = %d ORDER BY ( completed_at IS NULL ), enrolled_at ASC LIMIT 1", $u->ID
	) );
	if ( ! $enrollment ) {
		echo "SKIP certificates: {$login} has no enrollments\n";
		continue;
	}
	if ( ! $enrollment->completed_at ) {
		$wpdb->update( $enr_t, array( 'status' => 'completed', 'completed_at' => SSLMS_DB::now() ), array( 'id' => (int) $enrollment->id ) );
		$enrollment = SSLMS_DB::get_row( 'enrollments', (int) $enrollment->id );
	}
	SSLMS_Certificates::issue( $enrollment );
	echo "CREATED certificate for {$login}\n";
}

$vals = SSLMS_KPI::org_wide_values();
echo 'Compliance now: ' . var_export( $vals['compliance_pct'], true ) . "% | expiring<=30: " . (int) $vals['expiring_30'] . "\n";
echo "Credential seed done.\n";
