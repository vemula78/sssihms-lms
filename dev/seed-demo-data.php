<?php
/**
 * Seeds realistic, fully fictional demo content into the SSSIHMS LMS demo site.
 * Idempotent: checks for existing titles before creating. Run via:
 *   wp --path=/srv/www/wordpress eval-file seed-demo-data.php --url=demo.sssihms.org
 */

function seed_user_id( string $login ): int {
	$u = get_user_by( 'login', $login );
	return $u ? $u->ID : 0;
}

$instructor = seed_user_id( 'demoinstructor' );
$student1   = seed_user_id( 'demostudent1' ); // clinical track
$student2   = seed_user_id( 'demostudent2' ); // chaplaincy + allied
$preceptor  = seed_user_id( 'demopreceptor' );
$mentor     = seed_user_id( 'demomentor' );
$evaluator  = seed_user_id( 'demoevaluator' );
$admin      = seed_user_id( 'demo' );

foreach ( array( 'instructor' => $instructor, 'student1' => $student1, 'student2' => $student2, 'preceptor' => $preceptor, 'mentor' => $mentor, 'evaluator' => $evaluator ) as $k => $v ) {
	if ( ! $v ) {
		echo "MISSING USER: $k — aborting.\n";
		exit( 1 );
	}
}

function seed_find_course( string $title ) {
	global $wpdb;
	$t = SSLMS_DB::table( 'courses' );
	return $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE title = %s", $title ) );
}

// ---------------------------------------------------------------------
// Profiles + relationships
// ---------------------------------------------------------------------
global $wpdb;
$profiles_t = SSLMS_DB::table( 'profiles' );
$profile_rows = array(
	array( $student1, 'STF-2201', 'Nursing', 'clinical' ),
	array( $student2, 'STF-2214', 'Chaplaincy', 'chaplaincy,allied' ),
	array( $instructor, 'STF-1042', 'Cardiology Nursing Education', 'clinical' ),
	array( $preceptor, 'STF-0871', 'Cardiology', 'clinical' ),
	array( $mentor, 'STF-0655', 'Chaplaincy Services', 'chaplaincy' ),
	array( $evaluator, 'STF-1190', 'Laboratory Medicine', 'allied' ),
);
foreach ( $profile_rows as $r ) {
	$exists = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$profiles_t} WHERE user_id = %d", $r[0] ) );
	if ( ! $exists ) {
		$wpdb->insert( $profiles_t, array(
			'user_id' => $r[0], 'staff_id' => $r[1], 'discipline' => $r[2], 'tracks' => $r[3],
			'consent_at' => SSLMS_DB::now(),
		) );
	}
}
$rel_t = SSLMS_DB::table( 'relationships' );
$rels = array(
	array( $student1, $preceptor, 'preceptor' ),
	array( $student2, $mentor, 'mentor' ),
	array( $student2, $evaluator, 'evaluator' ),
);
foreach ( $rels as $r ) {
	$exists = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$rel_t} WHERE user_id=%d AND related_user_id=%d AND rel_type=%s", $r[0], $r[1], $r[2]
	) );
	if ( ! $exists ) {
		$wpdb->insert( $rel_t, array(
			'user_id' => $r[0], 'related_user_id' => $r[1], 'rel_type' => $r[2],
			'created_by' => $admin, 'created_at' => SSLMS_DB::now(),
		) );
	}
}
echo "Profiles + relationships seeded.\n";

// ---------------------------------------------------------------------
// Course 1 — Clinical: BLS Provider Course (student1 completes fully)
// ---------------------------------------------------------------------
$bls_id = seed_find_course( 'Basic Life Support (BLS) Provider Course' );
if ( ! $bls_id ) {
	$bls_id = SSLMS_Courses::create( array(
		'title' => 'Basic Life Support (BLS) Provider Course',
		'description' => 'Adult BLS competency for clinical staff: recognition of cardiac arrest, high-quality CPR, and AED use, aligned with hospital resuscitation policy.',
		'track' => 'clinical', 'status' => 'published', 'open_enrollment' => 1,
	), $instructor );
	$m1 = SSLMS_Courses::create_module( $bls_id, 'Adult BLS' );
	$l1 = SSLMS_Courses::create_lesson( $m1, array( 'title' => 'The Chain of Survival', 'content' => '<p>Early recognition, early CPR, early defibrillation, and early advanced care — the four links that determine outcome in cardiac arrest.</p>', 'video_url' => '', 'est_minutes' => 12 ) );
	$l2 = SSLMS_Courses::create_lesson( $m1, array( 'title' => 'High-Quality Chest Compressions', 'content' => '<p>Rate 100–120/min, depth 5–6cm, full recoil, minimal interruptions. Practice on the ward manikins before your next shift.</p>', 'video_url' => '', 'est_minutes' => 15 ) );
	$m2 = SSLMS_Courses::create_module( $bls_id, 'Team Dynamics & AED' );
	$l3 = SSLMS_Courses::create_lesson( $m2, array( 'title' => 'AED Use and Team Roles', 'content' => '<p>Clear role assignment, closed-loop communication, and safe AED operation during a code.</p>', 'video_url' => '', 'est_minutes' => 10 ) );

	$bank_id = SSLMS_DB::insert( 'question_banks', array( 'title' => 'BLS Assessment Bank', 'created_by' => $instructor ) );
	$qs = array(
		array( 'mcq', 'What is the recommended adult chest compression rate?', array( array( 'id' => 1, 'text' => '60–80/min' ), array( 'id' => 2, 'text' => '100–120/min' ), array( 'id' => 3, 'text' => '140+/min' ) ), array( 2 ), 1 ),
		array( 'tf', 'Full chest recoil between compressions is recommended.', array( array( 'id' => 1, 'text' => 'True' ), array( 'id' => 2, 'text' => 'False' ) ), array( 1 ), 1 ),
		array( 'mcq', 'Recommended adult compression depth?', array( array( 'id' => 1, 'text' => '2–3 cm' ), array( 'id' => 2, 'text' => '5–6 cm' ), array( 'id' => 3, 'text' => '8–10 cm' ) ), array( 2 ), 1 ),
		array( 'multi', 'Which are links in the Chain of Survival?', array( array( 'id' => 1, 'text' => 'Early recognition' ), array( 'id' => 2, 'text' => 'Early defibrillation' ), array( 'id' => 3, 'text' => 'Delayed transport' ) ), array( 1, 2 ), 2 ),
		array( 'tf', 'An AED should be attached before starting compressions in all cases.', array( array( 'id' => 1, 'text' => 'True' ), array( 'id' => 2, 'text' => 'False' ) ), array( 2 ), 1 ),
	);
	$qids = array();
	foreach ( $qs as $q ) {
		$qids[] = SSLMS_DB::insert( 'questions', array(
			'bank_id' => $bank_id, 'qtype' => $q[0], 'prompt' => $q[1],
			'options' => wp_json_encode( $q[2] ), 'correct' => wp_json_encode( $q[3] ),
			'points' => $q[4], 'is_active' => 1,
		) );
	}
	$quiz_id = SSLMS_DB::insert( 'quizzes', array(
		'course_id' => $bls_id, 'lesson_id' => null, 'bank_id' => $bank_id,
		'title' => 'BLS Provider Exam', 'num_questions' => 5, 'pass_pct' => 80,
		'max_attempts' => 3, 'time_limit_min' => 20, 'is_required' => 1,
	) );

	// Enrol + complete for student1
	SSLMS_Enrollments::enroll( $bls_id, $student1, $instructor );
	foreach ( array( $l1, $l2, $l3 ) as $lid ) {
		SSLMS_Progress::mark_lesson_complete( $lid, $student1 );
	}
	$attempt = SSLMS_Quizzes::start_attempt( $quiz_id, $student1 );
	if ( ! is_wp_error( $attempt ) ) {
		$attempt_id = $attempt['attempt_id'] ?? ( $attempt['id'] ?? 0 );
		$answers = array( $qids[0] => array( 2 ), $qids[1] => array( 1 ), $qids[2] => array( 2 ), $qids[3] => array( 1, 2 ), $qids[4] => array( 2 ) );
		SSLMS_Quizzes::submit_attempt( $attempt_id, $student1, $answers );
	}
	// second learner enrolled, in progress only
	SSLMS_Enrollments::enroll( $bls_id, $student2, $instructor );
	SSLMS_Progress::mark_lesson_complete( $l1, $student2 );

	echo "Seeded: BLS Provider Course (student1 completed + certificate, student2 in progress).\n";
} else {
	echo "BLS course already exists, skipped.\n";
}

// ---------------------------------------------------------------------
// Course 2 — Allied health: Phlebotomy Fundamentals + checklist
// ---------------------------------------------------------------------
$phleb_id = seed_find_course( 'Phlebotomy Fundamentals' );
if ( ! $phleb_id ) {
	$phleb_id = SSLMS_Courses::create( array(
		'title' => 'Phlebotomy Fundamentals',
		'description' => 'Core venipuncture technique, specimen handling and labeling for laboratory technician trainees.',
		'track' => 'allied', 'status' => 'published', 'open_enrollment' => 1,
	), $instructor );
	$m = SSLMS_Courses::create_module( $phleb_id, 'Core Skills' );
	SSLMS_Courses::create_lesson( $m, array( 'title' => 'Venipuncture Technique', 'content' => '<p>Site selection, patient identification, aseptic technique, and safe needle handling.</p>', 'video_url' => '', 'est_minutes' => 18 ) );
	SSLMS_Courses::create_lesson( $m, array( 'title' => 'Specimen Handling & Labeling', 'content' => '<p>Bedside labeling, correct order of draw, and chain-of-custody for laboratory samples.</p>', 'video_url' => '', 'est_minutes' => 10 ) );
	SSLMS_Enrollments::enroll( $phleb_id, $student2, $instructor );
	echo "Seeded: Phlebotomy Fundamentals course.\n";
} else {
	echo "Phlebotomy course already exists, skipped.\n";
}

$checklist_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . SSLMS_DB::table( 'checklists' ) . " WHERE title = %s", 'Venipuncture Competency Checklist' ) );
if ( ! $checklist_id ) {
	$checklist_id = SSLMS_Checklists::create_checklist( 'Venipuncture Competency Checklist', 'Laboratory Technician', 'Bedside competency assessment for peripheral venipuncture.' );
	$item1 = SSLMS_Checklists::add_item( $checklist_id, 'Confirms patient identity using two identifiers' );
	$item2 = SSLMS_Checklists::add_item( $checklist_id, 'Selects an appropriate venipuncture site' );
	$item3 = SSLMS_Checklists::add_item( $checklist_id, 'Maintains aseptic technique throughout' );
	$item4 = SSLMS_Checklists::add_item( $checklist_id, 'Labels specimen at the bedside before leaving the patient' );
	$asg = SSLMS_Checklists::assign( $checklist_id, $student2, $instructor );
	$asg_id = is_object( $asg ) ? $asg->id : $asg;
	if ( ! is_wp_error( $asg ) ) {
		SSLMS_Checklists::sign_item( $asg_id, $item1, 'competent', 'Confirmed name + DOB consistently.', $evaluator );
		SSLMS_Checklists::sign_item( $asg_id, $item2, 'competent', '', $evaluator );
		SSLMS_Checklists::sign_item( $asg_id, $item3, 'with_supervision', 'Needs a second attempt without prompting on glove change.', $evaluator );
	}
	echo "Seeded: Venipuncture checklist, assigned + partially signed (3 of 4 items).\n";
} else {
	echo "Checklist already exists, skipped.\n";
}

// ---------------------------------------------------------------------
// Course 3 — Chaplaincy: Foundations of Pastoral Care + journals
// ---------------------------------------------------------------------
$pc_id = seed_find_course( 'Foundations of Pastoral Care' );
if ( ! $pc_id ) {
	$pc_id = SSLMS_Courses::create( array(
		'title' => 'Foundations of Pastoral Care',
		'description' => 'Presence, active listening, and confidentiality in bedside ministry for chaplaincy trainees.',
		'track' => 'chaplaincy', 'status' => 'published', 'open_enrollment' => 1,
	), $instructor );
	$m = SSLMS_Courses::create_module( $pc_id, 'Presence & Listening' );
	SSLMS_Courses::create_lesson( $m, array( 'title' => 'Active Listening in Bedside Ministry', 'content' => '<p>Attending fully, reflecting feeling, and resisting the urge to fix — before offering counsel.</p>', 'video_url' => '', 'est_minutes' => 14 ) );
	SSLMS_Courses::create_lesson( $m, array( 'title' => 'Confidentiality & Trust', 'content' => '<p>What a patient shares in a pastoral visit stays within the pastoral relationship, except where safety requires escalation.</p>', 'video_url' => '', 'est_minutes' => 9 ) );
	SSLMS_Enrollments::enroll( $pc_id, $student2, $instructor );
	echo "Seeded: Foundations of Pastoral Care course.\n";
} else {
	echo "Pastoral Care course already exists, skipped.\n";
}

$je_t = SSLMS_DB::table( 'journal_entries' );
$existing_journal = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$je_t} WHERE user_id=%d AND title=%s", $student2, 'First ward visit' ) );
if ( ! $existing_journal ) {
	SSLMS_Journals::create_entry( $student2, 'First ward visit', 'I sat with a patient today who was anxious before surgery. I said very little and just stayed present. It felt like enough, though I keep wondering if it really was.', 'private' );
	$e2 = SSLMS_Journals::create_entry( $student2, 'A hard conversation about fear', 'A patient asked me directly whether she was going to die. I did not have an answer, and I told her so, gently. Afterwards I felt shaken and unsure I responded rightly.', 'mentor' );
	if ( ! is_wp_error( $e2 ) ) {
		$entry_id = is_object( $e2 ) ? $e2->id : $e2;
		SSLMS_Journals::add_comment( $entry_id, $mentor, 'What you did — staying honest instead of reassuring falsely — is exactly the formation this course is building. We can talk through the after-feelings on Thursday.' );
	}
	SSLMS_Journals::create_entry( $student2, 'Reflection for module assessment', 'This week\'s readings on active listening reshaped how I think about silence: it is not empty, it is where the other person finds room to speak.', 'instructor' );
	echo "Seeded: 3 journal entries for student2 (private / mentor-visible + comment / instructor-visible).\n";
} else {
	echo "Journal entries already exist, skipped.\n";
}

// ---------------------------------------------------------------------
// Rotation + hours (clinical) — student1, preceptor
// ---------------------------------------------------------------------
$rot_t = SSLMS_DB::table( 'rotations' );
$existing_rot = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$rot_t} WHERE user_id=%d AND department=%s", $student1, 'Cardiology Ward' ) );
if ( ! $existing_rot ) {
	$rot = SSLMS_Hours::create_rotation( $student1, 'Cardiology Ward', date( 'Y-m-d', strtotime( '-20 days' ) ), date( 'Y-m-d', strtotime( '+40 days' ) ), $preceptor, 80 );
	$rot_id = is_object( $rot ) ? $rot->id : $rot;
	if ( ! is_wp_error( $rot ) ) {
		$log1 = SSLMS_Hours::log_hours( $rot_id, $student1, date( 'Y-m-d', strtotime( '-14 days' ) ), 7.5, 'Morning rounds, ECG interpretation practice, patient monitoring.' );
		$log2 = SSLMS_Hours::log_hours( $rot_id, $student1, date( 'Y-m-d', strtotime( '-7 days' ) ), 8, 'Assisted with admissions, vitals monitoring, medication administration under supervision.' );
		$log3 = SSLMS_Hours::log_hours( $rot_id, $student1, date( 'Y-m-d', strtotime( '-2 days' ) ), 6, 'Cath lab observation, pre- and post-procedure care.' );
		if ( ! is_wp_error( $log1 ) ) SSLMS_Hours::review( is_object( $log1 ) ? $log1->id : $log1, $preceptor, 'approve', 'Solid clinical reasoning during rounds.' );
		if ( ! is_wp_error( $log2 ) ) SSLMS_Hours::review( is_object( $log2 ) ? $log2->id : $log2, $preceptor, 'approve', '' );
		// log3 left pending, to show the preceptor approval queue
	}
	echo "Seeded: Cardiology rotation for student1, 2 of 3 hour logs approved.\n";
} else {
	echo "Rotation already exists, skipped.\n";
}

// ---------------------------------------------------------------------
// Credential documents — student1
// ---------------------------------------------------------------------
$doc_t = SSLMS_DB::table( 'documents' );
$existing_doc = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$doc_t} WHERE user_id=%d AND doc_type=%s", $student1, 'bls' ) );
if ( ! $existing_doc ) {
	$tmp = tempnam( sys_get_temp_dir(), 'seed' );
	file_put_contents( $tmp, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n" );
	$file = array( 'tmp_name' => $tmp, 'name' => 'bls-provider-card.pdf', 'size' => filesize( $tmp ), 'error' => 0 );
	$doc = SSLMS_Documents::handle_upload( $student1, $file, 'bls', date( 'Y-m-d', strtotime( '-11 months' ) ), date( 'Y-m-d', strtotime( '+18 days' ) ) );
	if ( ! is_wp_error( $doc ) ) {
		SSLMS_Documents::verify( $doc, $admin );
	}
	$tmp2 = tempnam( sys_get_temp_dir(), 'seed' );
	file_put_contents( $tmp2, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n" );
	$file2 = array( 'tmp_name' => $tmp2, 'name' => 'immunization-record.pdf', 'size' => filesize( $tmp2 ), 'error' => 0 );
	$doc2 = SSLMS_Documents::handle_upload( $student1, $file2, 'immunization', date( 'Y-m-d', strtotime( '-2 years' ) ), date( 'Y-m-d', strtotime( '-10 days' ) ) );
	if ( ! is_wp_error( $doc2 ) ) {
		SSLMS_Documents::verify( $doc2, $admin );
	}
	echo "Seeded: 2 credential documents for student1 (BLS card expiring in 18 days, immunization record expired 10 days ago).\n";
} else {
	echo "Documents already exist, skipped.\n";
}

echo "\nSeeding complete.\n";
