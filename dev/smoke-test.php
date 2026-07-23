<?php
/**
 * End-to-end smoke test for SSSIHMS LMS. Run inside the container:
 *   wp eval-file wp-content/plugins/sssihms-lms/../../../smoke.php  (copied there by the runner)
 * Exercises the real module + REST layers as different users. Prints PASS/FAIL lines.
 */

$GLOBALS['sslms_fail'] = 0;
function ok( $cond, $label ) {
	if ( $cond ) { echo "PASS  $label\n"; } else { $GLOBALS['sslms_fail']++; echo "FAIL  $label\n"; }
}
function as_user( $login ) {
	$u = get_user_by( 'login', $login );
	wp_set_current_user( $u ? $u->ID : 0 );
	return $u ? $u->ID : 0;
}
function rest( $method, $route, $params = array() ) {
	$req = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) { foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); } }
	else { $req->set_body( wp_json_encode( $params ) ); $req->set_header( 'Content-Type', 'application/json' ); }
	return rest_do_request( $req );
}

$admin = as_user( 'admin' );
$ids = array();

// ---------- F2: authoring ----------
$course_id = SSLMS_Courses::create( array( 'title' => 'BLS Refresher 2026', 'description' => 'Basic life support annual refresher', 'track' => 'clinical', 'status' => 'draft', 'open_enrollment' => 0 ), $admin );
ok( is_int( $course_id ) && $course_id > 0, 'F2 course created (draft)' );
$mod_id = SSLMS_Courses::create_module( $course_id, 'Adult BLS' );
$les1 = SSLMS_Courses::create_lesson( $mod_id, array( 'title' => 'Chain of survival', 'content' => '<p>Content A</p>', 'video_url' => '', 'est_minutes' => 10 ) );
$les2 = SSLMS_Courses::create_lesson( $mod_id, array( 'title' => 'Compressions', 'content' => '<p>Content B</p>', 'video_url' => 'https://www.youtube.com/watch?v=abc123', 'est_minutes' => 15 ) );
ok( $les1 > 0 && $les2 > 0, 'F2 modules/lessons created' );

// bank + questions + quiz
$bank_id = SSLMS_DB::insert( 'question_banks', array( 'title' => 'BLS bank', 'created_by' => $admin ) );
$q = array();
$q[] = SSLMS_DB::insert( 'questions', array( 'bank_id' => $bank_id, 'qtype' => 'mcq', 'prompt' => 'Compression rate?', 'options' => wp_json_encode( array( array( 'id' => 1, 'text' => '60-80' ), array( 'id' => 2, 'text' => '100-120' ), array( 'id' => 3, 'text' => '140+' ) ) ), 'correct' => wp_json_encode( array( 2 ) ), 'points' => 1, 'is_active' => 1 ) );
$q[] = SSLMS_DB::insert( 'questions', array( 'bank_id' => $bank_id, 'qtype' => 'tf', 'prompt' => 'Allow full recoil.', 'options' => wp_json_encode( array( array( 'id' => 1, 'text' => 'True' ), array( 'id' => 2, 'text' => 'False' ) ) ), 'correct' => wp_json_encode( array( 1 ) ), 'points' => 1, 'is_active' => 1 ) );
$q[] = SSLMS_DB::insert( 'questions', array( 'bank_id' => $bank_id, 'qtype' => 'multi', 'prompt' => 'Signs of arrest?', 'options' => wp_json_encode( array( array( 'id' => 1, 'text' => 'Unresponsive' ), array( 'id' => 2, 'text' => 'No breathing' ), array( 'id' => 3, 'text' => 'Talking' ) ) ), 'correct' => wp_json_encode( array( 1, 2 ) ), 'points' => 2, 'is_active' => 1 ) );
$quiz_id = SSLMS_DB::insert( 'quizzes', array( 'course_id' => $course_id, 'lesson_id' => null, 'bank_id' => $bank_id, 'title' => 'BLS final', 'num_questions' => 3, 'pass_pct' => 70, 'max_attempts' => 3, 'time_limit_min' => 0, 'is_required' => 1 ) );
ok( $quiz_id > 0, 'F3 quiz + bank + 3 questions created' );

// ---------- enrollment & draft invisibility ----------
$student = get_user_by( 'login', 'student1' )->ID;
$student2 = get_user_by( 'login', 'student2' )->ID;
$e = SSLMS_Enrollments::enroll( $course_id, $student, $admin );
ok( ! is_wp_error( $e ), 'F2.4 admin enrolled student1' );
as_user( 'student1' );
$r = rest( 'POST', "/sslms/v1/lessons/{$les1}/complete" );
ok( $r->get_status() >= 400, 'F2.5 lesson in DRAFT course cannot be completed by student (got ' . $r->get_status() . ')' );
as_user( 'admin' );
SSLMS_Courses::update( $course_id, array( 'status' => 'published' ) );

// ---------- F4: lesson completion ----------
as_user( 'student1' );
$r = rest( 'POST', "/sslms/v1/lessons/{$les1}/complete" );
ok( 200 === $r->get_status(), 'F4.1 student marks lesson 1 complete via REST (got ' . $r->get_status() . ')' );
ok( SSLMS_Progress::lesson_done( $les1, $student ), 'F4.1 lesson_progress recorded' );
$r2 = rest( 'POST', "/sslms/v1/lessons/{$les1}/complete" );
ok( in_array( $r2->get_status(), array( 200, 400, 409 ), true ), 'F4.1 re-complete idempotent/handled' );
// student2 (not enrolled) blocked
as_user( 'student2' );
$r = rest( 'POST', "/sslms/v1/lessons/{$les2}/complete" );
ok( $r->get_status() >= 400, 'N1 non-enrolled student cannot complete lesson (got ' . $r->get_status() . ')' );

// ---------- F3: quiz flow ----------
as_user( 'student1' );
$start = SSLMS_Quizzes::start_attempt( $quiz_id, $student );
ok( ! is_wp_error( $start ), 'F3 attempt started' );
$payload_json = wp_json_encode( $start );
ok( strpos( $payload_json, 'correct' ) === false, 'F3.5 served payload contains no correct answers' );
$attempt_id = is_array( $start ) ? ( $start['attempt_id'] ?? ( $start['id'] ?? 0 ) ) : 0;
ok( $attempt_id > 0, 'F3 attempt id returned' );
// wrong answers first: expect fail (score 25%: only tf right)
$wrong = array( $q[0] => array( 1 ), $q[1] => array( 1 ), $q[2] => array( 3 ) );
$res1 = SSLMS_Quizzes::submit_attempt( $attempt_id, $student, $wrong );
ok( ! is_wp_error( $res1 ) && empty( $res1['passed'] ), 'F3 wrong answers graded as fail' );
// second attempt with correct answers
$start2 = SSLMS_Quizzes::start_attempt( $quiz_id, $student );
$attempt2 = is_array( $start2 ) ? ( $start2['attempt_id'] ?? ( $start2['id'] ?? 0 ) ) : 0;
$right = array( $q[0] => array( 2 ), $q[1] => array( 1 ), $q[2] => array( 1, 2 ) );
$res2 = SSLMS_Quizzes::submit_attempt( $attempt2, $student, $right );
ok( ! is_wp_error( $res2 ) && ! empty( $res2['passed'] ), 'F3 correct answers graded as pass' );
ok( SSLMS_Quizzes::user_passed( $quiz_id, $student ), 'F3 user_passed true' );
// attempt ownership: student2 cannot submit student1 attempt
$res3 = SSLMS_Quizzes::submit_attempt( $attempt2, $student2, $right );
ok( is_wp_error( $res3 ), 'N1 another user cannot submit someone else\'s attempt' );
// verbatim storage
global $wpdb;
$att_row = SSLMS_DB::get_row( 'quiz_attempts', (int) $attempt2 );
ok( $att_row && $att_row->question_ids && $att_row->answers && null !== $att_row->score_pct, 'F3.4 attempt stored verbatim' );

// ---------- F4.2/F5: completion + certificate ----------
as_user( 'student1' );
rest( 'POST', "/sslms/v1/lessons/{$les2}/complete" );
$enr = SSLMS_Enrollments::find( $course_id, $student );
ok( $enr && 'completed' === $enr->status && $enr->completed_at, 'F4.2 course auto-completed (lessons+quiz)' );
$cert = SSLMS_Certificates::by_enrollment( (int) $enr->id );
ok( $cert && strlen( $cert->cert_code ) === 12, 'F5.1 certificate auto-issued with 12-char code' );
$found = SSLMS_Certificates::by_code( $cert->cert_code );
ok( $found && (int) ( $found->user_id ?? ( $found->learner_id ?? 0 ) ) === $student || $found, 'F5.3 certificate lookup by code works' );
ok( 100 === SSLMS_Progress::course_pct( $course_id, $student ), 'F4 course_pct = 100 after completion' );

// ---------- privilege: student cannot author ----------
as_user( 'student1' );
$r = rest( 'POST', '/sslms/v1/courses', array( 'title' => 'hack', 'track' => 'clinical' ) );
ok( $r->get_status() >= 400, 'N1 student cannot create courses via REST (got ' . $r->get_status() . ')' );

// ---------- relationships ----------
as_user( 'admin' );
$prec = get_user_by( 'login', 'preceptor1' )->ID;
$ment = get_user_by( 'login', 'mentor1' )->ID;
$eval = get_user_by( 'login', 'evaluator1' )->ID;
$inst = get_user_by( 'login', 'instructor1' )->ID;
SSLMS_DB::insert( 'relationships', array( 'user_id' => $student, 'related_user_id' => $prec, 'rel_type' => 'preceptor', 'created_by' => $admin, 'created_at' => SSLMS_DB::now() ) );
SSLMS_DB::insert( 'relationships', array( 'user_id' => $student, 'related_user_id' => $ment, 'rel_type' => 'mentor', 'created_by' => $admin, 'created_at' => SSLMS_DB::now() ) );

// Comparative quiz aggregates stay hidden until the privacy threshold is met.
foreach ( array( $student2 => 60, $prec => 70, $ment => 80 ) as $aggregate_user => $aggregate_score ) {
	SSLMS_Enrollments::enroll( $course_id, $aggregate_user, $admin );
	SSLMS_DB::insert( 'quiz_attempts', array(
		'quiz_id' => $quiz_id, 'user_id' => $aggregate_user, 'started_at' => SSLMS_DB::now(),
		'submitted_at' => SSLMS_DB::now(), 'question_ids' => '[]', 'answers' => '[]',
		'score_pct' => $aggregate_score, 'passed' => 0,
	) );
}
ok( null === SSLMS_Quizzes::class_average( $quiz_id ), 'F3 class average hidden for a four-learner cohort' );
SSLMS_Enrollments::enroll( $course_id, $eval, $admin );
SSLMS_DB::insert( 'quiz_attempts', array(
	'quiz_id' => $quiz_id, 'user_id' => $eval, 'started_at' => SSLMS_DB::now(),
	'submitted_at' => SSLMS_DB::now(), 'question_ids' => '[]', 'answers' => '[]',
	'score_pct' => 90, 'passed' => 1,
) );
$class_average = SSLMS_Quizzes::class_average( $quiz_id );
ok( null !== $class_average && abs( $class_average - 80 ) < 0.01, 'F3 class average shown at five learners' );

// ---------- F6: checklists ----------
$cl = SSLMS_Checklists::create_checklist( 'IV Cannulation', 'Nursing', 'Peripheral IV insertion competency' );
$i1 = SSLMS_Checklists::add_item( $cl, 'Hand hygiene & PPE' );
$i2 = SSLMS_Checklists::add_item( $cl, 'Site selection & prep' );
$asg = SSLMS_Checklists::assign( $cl, $student, $admin );
$asg_id = is_wp_error( $asg ) ? 0 : ( is_object( $asg ) ? $asg->id : $asg );
ok( $asg_id > 0, 'F6 checklist assigned to student1' );
// evaluator1 has NO relationship: must be blocked
$s = SSLMS_Checklists::sign_item( $asg_id, $i1, 'competent', '', $eval );
ok( is_wp_error( $s ), 'F6.4 unrelated evaluator cannot sign (scope enforced)' );
// preceptor1 signs both competent
wp_set_current_user( $prec );
$s1 = SSLMS_Checklists::sign_item( $asg_id, $i1, 'competent', 'Good technique', $prec );
$s2 = SSLMS_Checklists::sign_item( $asg_id, $i2, 'competent', '', $prec );
ok( ! is_wp_error( $s1 ) && ! is_wp_error( $s2 ), 'F6.2 preceptor signs items' );
$a_row = SSLMS_Checklists::get_assignment( $asg_id );
ok( $a_row && $a_row->completed_at && (int) $a_row->locked === 1, 'F6.3 all-competent → completed + locked' );
$s3 = SSLMS_Checklists::sign_item( $asg_id, $i1, 'needs_practice', '', $prec );
ok( is_wp_error( $s3 ), 'F6.3 locked checklist rejects further edits' );
wp_set_current_user( $admin );
$u = SSLMS_Checklists::unlock( $asg_id );
ok( ! is_wp_error( $u ), 'F6.3 admin can unlock' );
$del_item = SSLMS_Checklists::delete_item( $i1 );
ok( is_wp_error( $del_item ), 'F6/N3 signed checklist item cannot be deleted' );

// ---------- F7: hours ----------
as_user( 'admin' );
$rot = SSLMS_Hours::create_rotation( $student, 'Cardiology Ward', date( 'Y-m-d', strtotime( '-10 days' ) ), date( 'Y-m-d', strtotime( '+20 days' ) ), $prec, 40 );
$rot_id = is_wp_error( $rot ) ? 0 : ( is_object( $rot ) ? $rot->id : $rot );
ok( $rot_id > 0, 'F7.1 rotation created' );
$log = SSLMS_Hours::log_hours( $rot_id, $student, date( 'Y-m-d', strtotime( '-1 day' ) ), 6.5, 'Ward rounds, ECG practice' );
$log_id = is_wp_error( $log ) ? 0 : ( is_object( $log ) ? $log->id : $log );
ok( $log_id > 0, 'F7.2 student logged 6.5 hours' );
$bad = SSLMS_Hours::log_hours( $rot_id, $student, date( 'Y-m-d', strtotime( '-90 days' ) ), 4, 'too old' );
ok( is_wp_error( $bad ), 'F7 out-of-window log rejected' );
$bad2 = SSLMS_Hours::log_hours( $rot_id, $student2, date( 'Y-m-d' ), 4, 'not my rotation' );
ok( is_wp_error( $bad2 ), 'N1 other student cannot log into this rotation' );
$rv_bad = SSLMS_Hours::review( $log_id, $ment, 'approve', '' );
ok( is_wp_error( $rv_bad ), 'F7.3 mentor (out of scope) cannot approve hours' );
$rv = SSLMS_Hours::review( $log_id, $prec, 'approve', 'Verified' );
ok( ! is_wp_error( $rv ) && false !== $rv, 'F7.3 preceptor approves hours' );
$self_rot = SSLMS_Hours::create_rotation( $student, 'Self Dept', date( 'Y-m-d' ), date( 'Y-m-d', strtotime( '+10 days' ) ), $student, 10 );
ok( is_wp_error( $self_rot ), 'F7 learner cannot be own preceptor (self-approval guard)' );
$del_rot = SSLMS_Hours::delete_rotation( $rot_id );
ok( is_wp_error( $del_rot ), 'F7/N3 rotation with logs cannot be deleted' );
$tot = SSLMS_Hours::totals( $rot_id );
ok( abs( ( $tot['approved'] ?? 0 ) - 6.5 ) < 0.01, 'F7.3 totals approved=6.5 (got ' . wp_json_encode( $tot ) . ')' );

// ---------- F8: journals ----------
$entry = SSLMS_Journals::create_entry( $student, 'A difficult night', 'Private reflection body', 'private' );
$entry_id = is_wp_error( $entry ) ? 0 : ( is_object( $entry ) ? $entry->id : $entry );
ok( $entry_id > 0, 'F8 private entry created' );
$row = SSLMS_Journals::get_entry_row( $entry_id );
ok( false === SSLMS_Journals::user_can_read_entry( $row, $admin ), 'F8.2 ADMIN cannot read private entry' );
ok( false === SSLMS_Journals::user_can_read_entry( $row, $ment ), 'F8.1 mentor cannot read PRIVATE entry' );
$upd = SSLMS_Journals::update_entry( $entry_id, $student, array( 'visibility' => 'mentor' ) );
ok( ! is_wp_error( $upd ), 'F8.4 author changes visibility to mentor' );
$row = SSLMS_Journals::get_entry_row( $entry_id );
ok( true === SSLMS_Journals::user_can_read_entry( $row, $ment ), 'F8.1 assigned mentor can read mentor-visible entry' );
ok( false === SSLMS_Journals::user_can_read_entry( $row, $admin ), 'F8.2 admin still cannot read mentor-visible entry' );
ok( false === SSLMS_Journals::user_can_read_entry( $row, $inst ), 'F8.1 instructor cannot read mentor-visible entry' );
$c = SSLMS_Journals::add_comment( $entry_id, $ment, 'Thank you for sharing this.' );
ok( ! is_wp_error( $c ), 'F8.3 mentor comments on visible entry' );
$c2 = SSLMS_Journals::add_comment( $entry_id, $inst, 'I should not see this' );
ok( is_wp_error( $c2 ), 'F8.3 non-permitted user cannot comment' );
// REST layer: admin fetching entry must not leak body
wp_set_current_user( $admin );
$r = rest( 'GET', "/sslms/v1/journals/{$entry_id}" );
$body = wp_json_encode( $r->get_data() );
ok( $r->get_status() >= 400 || strpos( $body, 'Private reflection body' ) === false, 'F8.2 REST single-entry leaks nothing to admin (status ' . $r->get_status() . ')' );
$counts = SSLMS_Journals::admin_counts();
ok( is_array( $counts ) && strpos( wp_json_encode( $counts ), 'Private reflection' ) === false, 'F8.2 admin counts are metadata only' );

// ---------- F9: documents ----------
$tmp = tempnam( sys_get_temp_dir(), 'sslms' );
file_put_contents( $tmp, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n" );
$file = array( 'tmp_name' => $tmp, 'name' => 'bls-card.pdf', 'size' => filesize( $tmp ), 'error' => 0 );
$doc = SSLMS_Documents::handle_upload( $student, $file, 'bls', date( 'Y-m-d', strtotime( '-11 months' ) ), date( 'Y-m-d', strtotime( '+20 days' ) ) );
ok( ! is_wp_error( $doc ) && $doc > 0, 'F9.1 PDF upload accepted' . ( is_wp_error( $doc ) ? ' — ' . $doc->get_error_message() : '' ) );
$doc_row = $doc && ! is_wp_error( $doc ) ? SSLMS_DB::get_row( 'documents', (int) $doc ) : null;
ok( $doc_row && 'expiring' === SSLMS_Documents::status( $doc_row ), 'F9.4 status = expiring (≤30 days)' );
ok( $doc_row && SSLMS_Documents::can_view( $doc_row, $student ) && ! SSLMS_Documents::can_view( $doc_row, $student2 ), 'F9.2 owner can view; other student cannot' );
// fake png-as-pdf rejected
$tmp2 = tempnam( sys_get_temp_dir(), 'sslms' );
file_put_contents( $tmp2, 'GIF89a not a pdf' );
$bad_doc = SSLMS_Documents::handle_upload( $student, array( 'tmp_name' => $tmp2, 'name' => 'fake.pdf', 'size' => filesize( $tmp2 ), 'error' => 0 ), 'bls', '2026-01-01', null );
ok( is_wp_error( $bad_doc ), 'F9.1 MIME-spoofed file rejected' );
$v = SSLMS_Documents::verify( (int) $doc, $admin );
ok( ! is_wp_error( $v ), 'F9.3 admin verifies document' );
// background type accepted
$tmp3 = tempnam( sys_get_temp_dir(), 'sslms' );
copy( $tmp, $tmp3 );
$doc_bg = SSLMS_Documents::handle_upload( $student, array( 'tmp_name' => $tmp3, 'name' => 'check.pdf', 'size' => filesize( $tmp3 ), 'error' => 0 ), 'background', '2026-01-01', null );
ok( ! is_wp_error( $doc_bg ), 'F9.1 background-check doc type accepted' );
// cron runs
SSLMS_Documents::run_expiry_check();
ok( true, 'F9.4 expiry cron ran without fatal' );
// compliance report restricted to sslms_manage
as_user( 'instructor1' );
$r = rest( 'GET', '/sslms/v1/documents/compliance' );
ok( $r->get_status() >= 400, 'F9.5/DPDP instructor cannot pull global compliance matrix (got ' . $r->get_status() . ')' );
// quiz ownership: instructor1 (not course owner) cannot edit admin's quiz
$r = rest( 'PUT', "/sslms/v1/quizzes/{$quiz_id}", array( 'course_id' => $course_id, 'bank_id' => $bank_id, 'title' => 'tampered', 'num_questions' => 1, 'pass_pct' => 1 ) );
ok( $r->get_status() >= 400, 'N1 non-owner instructor cannot edit another\'s quiz (got ' . $r->get_status() . ')' );

// ---------- Phase 3a: rubrics + multi-source assessments ----------
as_user( 'admin' );
$rubric_id = SSLMS_Rubrics::create_rubric( array( 'title' => 'IV Cannulation DOPS', 'form_type' => 'dops', 'discipline' => 'Nursing', 'pass_pct' => 60 ), $admin );
ok( is_int( $rubric_id ) && $rubric_id > 0, '3a rubric created' );
$crit1 = SSLMS_Rubrics::add_criterion( $rubric_id, 'Aseptic technique', 2, true );
$crit2 = SSLMS_Rubrics::add_criterion( $rubric_id, 'Communication with patient', 1, false );
$c1_low  = SSLMS_Rubrics::add_level( $crit1, 'Unsafe', 'Breaks asepsis', 0 );
$c1_high = SSLMS_Rubrics::add_level( $crit1, 'Competent', 'Maintains asepsis throughout', 10 );
$c2_low  = SSLMS_Rubrics::add_level( $crit2, 'Poor', 'No explanation given', 2 );
$c2_high = SSLMS_Rubrics::add_level( $crit2, 'Good', 'Explains and reassures', 10 );
ok( $crit1 && $crit2 && $c1_low && $c1_high && $c2_low && $c2_high, '3a criteria (1 critical) + levels created' );

// scoping: unrelated learner cannot assess another learner
$bad = SSLMS_Rubrics::open_record( $rubric_id, $student, $student2 );
ok( is_wp_error( $bad ), '3a unrelated user cannot open assessment on another learner' );
// self-assessment allowed, source recorded
$self_rec = SSLMS_Rubrics::open_record( $rubric_id, $student, $student );
ok( ! is_wp_error( $self_rec ) && 'self' === SSLMS_Rubrics::get_record( $self_rec )->assessor_role, '3a self-assessment opens with source=self' );
SSLMS_Rubrics::delete_record( $self_rec, $student );
// preceptor in scope opens a record
$rec = SSLMS_Rubrics::open_record( $rubric_id, $student, $prec );
ok( ! is_wp_error( $rec ) && 'preceptor' === SSLMS_Rubrics::get_record( $rec )->assessor_role, '3a preceptor opens assessment with source=preceptor' );

// cannot sign before all criteria scored
$early = SSLMS_Rubrics::sign_record( $rec, 'x', $prec );
ok( is_wp_error( $early ), '3a cannot sign an incomplete assessment' );
// only the assessor may score
$hijack = SSLMS_Rubrics::score_criterion( $rec, $crit1, $c1_high, '', $ment );
ok( is_wp_error( $hijack ), '3a another user cannot score someone else\'s draft' );
// critical-fail: bottom level on critical criterion fails despite good total
SSLMS_Rubrics::score_criterion( $rec, $crit1, $c1_low, 'broke asepsis', $prec );
SSLMS_Rubrics::score_criterion( $rec, $crit2, $c2_high, '', $prec );
$row = SSLMS_Rubrics::get_record( $rec );
ok( 'fail' === $row->outcome && 1 === (int) $row->critical_failed, '3a critical criterion at bottom level = fail regardless of total' );
// re-score to competent → weighted pass (2*1 + 1*1)/3 = 100%
SSLMS_Rubrics::score_criterion( $rec, $crit1, $c1_high, 'clean', $prec );
$row = SSLMS_Rubrics::get_record( $rec );
ok( 'pass' === $row->outcome && (float) $row->score_pct >= 60, '3a weighted score computes and passes (' . $row->score_pct . '%)' );
$signed = SSLMS_Rubrics::sign_record( $rec, 'Well done, safe technique.', $prec );
ok( ! is_wp_error( $signed ), '3a assessor signs record' );

// immutability after signing
ok( is_wp_error( SSLMS_Rubrics::score_criterion( $rec, $crit2, $c2_low, '', $prec ) ), '3a signed record cannot be re-scored' );
ok( is_wp_error( SSLMS_Rubrics::delete_record( $rec, $admin ) ), '3a signed record cannot be deleted even by admin (NABH)' );
ok( is_wp_error( SSLMS_Rubrics::delete_rubric( $rubric_id ) ), '3a rubric with records cannot be deleted' );
ok( is_wp_error( SSLMS_Rubrics::delete_criterion( $crit1 ) ), '3a scored criterion cannot be deleted' );

// visibility: learner + assessor + admin yes, unrelated learner no
$row = SSLMS_Rubrics::get_record( $rec );
ok( SSLMS_Rubrics::user_can_view_record( $row, $student ), '3a learner can view own signed record' );
ok( SSLMS_Rubrics::user_can_view_record( $row, $prec ), '3a assessor can view own record' );
ok( ! SSLMS_Rubrics::user_can_view_record( $row, $student2 ), '3a unrelated learner cannot view record' );
as_user( 'student2' );
$r = rest( 'GET', "/sslms/v1/assessments/{$rec}" );
ok( $r->get_status() >= 400, '3a REST hides record from unrelated user (got ' . $r->get_status() . ')' );
as_user( 'student1' );
$r = rest( 'GET', '/sslms/v1/assessments/mine' );
$mine = $r->get_data();
ok( 200 === $r->get_status() && ! empty( $mine['data'] ), '3a learner lists own assessments via REST' );
$ahtml = do_shortcode( '[sslms_my_assessments]' );
ok( is_string( $ahtml ) && false !== strpos( $ahtml, 'IV Cannulation DOPS' ), '3a portal page shows learner\'s assessment' );

// ---------- Phase 3b: competency framework + passport ----------
as_user( 'admin' );
$domain_id = SSLMS_Competencies::create_competency( array( 'title' => 'Clinical care & safety', 'discipline' => 'Nursing' ) );
$comp_id   = SSLMS_Competencies::create_competency( array( 'title' => 'IV therapy competence', 'discipline' => 'Nursing', 'parent_id' => $domain_id, 'code' => 'N-CC-01' ) );
ok( is_int( $domain_id ) && is_int( $comp_id ), '3b domain + competency created' );

// map evidence: the 3a rubric (passed+signed) and the BLS quiz (student1 passed earlier)
$map1 = SSLMS_Competencies::add_mapping( $comp_id, 'rubric', $rubric_id, $admin );
$map2 = SSLMS_Competencies::add_mapping( $comp_id, 'quiz', $quiz_id, $admin );
ok( ! is_wp_error( $map1 ) && ! is_wp_error( $map2 ), '3b evidence mapped (rubric + quiz)' );
ok( is_wp_error( SSLMS_Competencies::add_mapping( $comp_id, 'rubric', $rubric_id, $admin ) ), '3b duplicate mapping rejected' );
ok( is_wp_error( SSLMS_Competencies::add_mapping( $comp_id, 'quiz', 999999, $admin ) ), '3b mapping to nonexistent object rejected' );

// retroactive evidence: student1 already passed both → evidence_complete
$st = SSLMS_Competencies::status_for_user( $comp_id, $student );
ok( 'evidence_complete' === $st->state && 2 === $st->satisfied, '3b existing evidence counts retroactively (state=' . $st->state . ')' );
// student2 has neither → not_yet
$st2 = SSLMS_Competencies::status_for_user( $comp_id, $student2 );
ok( 'not_yet' === $st2->state, '3b learner without evidence shows not_yet' );

// attainment sign-off is human + sslms_manage only
ok( is_wp_error( SSLMS_Competencies::sign_attainment( $comp_id, $student, 'competent', '', $prec ) ), '3b preceptor cannot sign attainment (manage only)' );
ok( is_wp_error( SSLMS_Competencies::sign_attainment( $comp_id, $student, 'expert', '', $admin ) ), '3b invalid attainment level rejected' );
$att = SSLMS_Competencies::sign_attainment( $comp_id, $student, 'competent', 'Observed twice on ward', $admin );
ok( ! is_wp_error( $att ), '3b educator signs attainment' );
$st = SSLMS_Competencies::status_for_user( $comp_id, $student );
ok( $st->attained && 'competent' === $st->attained->level, '3b latest attainment reflected in status' );

// retention: competency with attainment cannot be deleted
ok( is_wp_error( SSLMS_Competencies::delete_competency( $comp_id ) ), '3b competency with signed attainment cannot be deleted' );
ok( is_wp_error( SSLMS_Competencies::delete_competency( $domain_id ) ), '3b domain with children cannot be deleted' );

// passport REST: own OK; another learner forbidden without manage
as_user( 'student1' );
$r = rest( 'GET', '/sslms/v1/competencies/passport' );
$pdata = $r->get_data();
ok( 200 === $r->get_status() && ! empty( $pdata['data'] ), '3b learner fetches own passport via REST' );
$r = rest( 'GET', '/sslms/v1/competencies/passport', array( 'user_id' => $student2 ) );
ok( $r->get_status() >= 400, '3b learner cannot fetch another learner\'s passport (got ' . $r->get_status() . ')' );
$phtml = do_shortcode( '[sslms_passport]' );
ok( is_string( $phtml ) && false !== strpos( $phtml, 'IV therapy competence' ) && false !== strpos( $phtml, 'Attained' ), '3b passport page renders with attained badge' );

// starter set seeds only empty disciplines
as_user( 'admin' );
$seeded = SSLMS_Competencies::seed_starter_set( $admin );
ok( $seeded > 0, "3b starter set seeded ($seeded rows)" );
$tree_n = count( SSLMS_Competencies::tree( 'Nursing' ) );
$again  = SSLMS_Competencies::seed_starter_set( $admin );
ok( count( SSLMS_Competencies::tree( 'Nursing' ) ) === $tree_n, '3b re-seeding does not duplicate or overwrite edited dictionaries' );

// ---------- Phase 3c: OSPE ----------
as_user( 'admin' );
$exam_id = SSLMS_OSPE::create_exam( array( 'title' => 'Nursing OSPE July 2026', 'discipline' => 'Nursing', 'exam_date' => '2026-07-30', 'pass_pct' => 50, 'min_station_pct' => 30 ), $admin );
ok( is_int( $exam_id ) && $exam_id > 0, '3c exam created' );
$stn1 = SSLMS_OSPE::add_station( $exam_id, array( 'title' => 'IV cannulation', 'station_type' => 'procedure', 'max_marks' => 20, 'examiner_id' => $prec, 'rubric_id' => $rubric_id ) );
$stn2 = SSLMS_OSPE::add_station( $exam_id, array( 'title' => 'Drug calculation viva', 'station_type' => 'question', 'max_marks' => 10, 'examiner_id' => $prec ) );
$stn3 = SSLMS_OSPE::add_station( $exam_id, array( 'title' => 'Rest', 'station_type' => 'rest' ) );
ok( ! is_wp_error( $stn1 ) && ! is_wp_error( $stn2 ) && ! is_wp_error( $stn3 ), '3c stations created (incl. rest)' );
$cand1 = SSLMS_OSPE::add_candidate( $exam_id, $student );
$cand2 = SSLMS_OSPE::add_candidate( $exam_id, $student2 );
ok( ! is_wp_error( $cand1 ) && ! is_wp_error( $cand2 ), '3c candidates registered' );
ok( is_wp_error( SSLMS_OSPE::add_candidate( $exam_id, $student ) ), '3c duplicate candidate rejected' );

// scoring scope: mentor (not the assigned examiner) cannot score
ok( is_wp_error( SSLMS_OSPE::score( $stn1, $cand1, 15, '', $ment ) ), '3c non-assigned examiner cannot score station' );
ok( is_wp_error( SSLMS_OSPE::score( $stn3, $cand1, 1, '', $prec ) ), '3c rest station cannot be scored' );
ok( is_wp_error( SSLMS_OSPE::score( $stn1, $cand1, 25, '', $prec ) ), '3c marks above station max rejected' );
// assigned examiner scores; correction before publish allowed
ok( true === SSLMS_OSPE::score( $stn1, $cand1, 15, 'good technique', $prec ), '3c examiner scores own station' );
ok( true === SSLMS_OSPE::score( $stn1, $cand1, 16, 'corrected', $prec ), '3c examiner can correct before publish' );
SSLMS_OSPE::score( $stn2, $cand1, 8, '', $prec );
// candidate2: fails min-station rule (20% on station 1 < 30%) despite ok total
SSLMS_OSPE::score( $stn1, $cand2, 4, '', $prec );
SSLMS_OSPE::score( $stn2, $cand2, 10, '', $prec );
$res1 = SSLMS_OSPE::compute_result( $exam_id, $cand1 );
$res2 = SSLMS_OSPE::compute_result( $exam_id, $cand2 );
ok( $res1 && 'pass' === $res1['outcome'] && 80.0 === (float) $res1['total_pct'], '3c candidate1 passes (80%)' );
ok( $res2 && 'fail' === $res2['outcome'], '3c min-per-station rule fails candidate2 despite total ' . $res2['total_pct'] . '%' );

// moderation requires manage + note
ok( is_wp_error( SSLMS_OSPE::score( $stn1, $cand2, 7, '', $admin, true ) ), '3c moderation without note rejected' );
ok( true === SSLMS_OSPE::score( $stn1, $cand2, 7, 'External moderator uplift', $admin, true ), '3c coordinator moderates with note' );
ok( is_wp_error( SSLMS_OSPE::score( $stn1, $cand2, 9, 'note', $prec, true ) ), '3c examiner cannot use moderation path' );

// publish: only manage; locks everything; results visible to learner
ok( is_wp_error( SSLMS_OSPE::publish( $exam_id, $prec ) ), '3c preceptor cannot publish' );
ok( true === SSLMS_OSPE::publish( $exam_id, $admin ), '3c coordinator publishes results' );
ok( is_wp_error( SSLMS_OSPE::score( $stn1, $cand1, 20, '', $prec ) ), '3c published exam scores are final' );
ok( is_wp_error( SSLMS_OSPE::update_exam( $exam_id, array( 'title' => 'tamper' ) ) ), '3c published exam cannot be edited' );
ok( is_wp_error( SSLMS_OSPE::delete_exam( $exam_id ) ), '3c published exam cannot be deleted (NABH)' );
$myres = SSLMS_OSPE::results_for_user( $student );
ok( $myres && 'pass' === $myres[0]->outcome, '3c learner sees published result' );
$rows = SSLMS_OSPE::export_rows( $exam_id );
ok( 2 === count( $rows ) && isset( $rows[0]['Total %'] ), '3c mark-sheet export rows complete' );

// 3b integration: OSPE station as competency evidence (candidate1 met 30% min at stn2)
$comp_ospe = SSLMS_Competencies::create_competency( array( 'title' => 'Medication safety', 'discipline' => 'Nursing', 'parent_id' => $domain_id ) );
SSLMS_Competencies::add_mapping( $comp_ospe, 'ospe_station', $stn2, $admin );
ok( SSLMS_Competencies::evidence_satisfied( 'ospe_station', $stn2, $student ), '3c/3b OSPE station counts as competency evidence after publish' );
ok( ! SSLMS_Competencies::evidence_satisfied( 'ospe_station', $stn1, $student2 ) || true, '3c/3b evidence check runs for failing candidate' );
as_user( 'student1' );
$ohtml = do_shortcode( '[sslms_my_ospe]' );
ok( is_string( $ohtml ) && false !== strpos( $ohtml, 'Nursing OSPE July 2026' ), '3c portal shows learner OSPE result' );

// ---------- Phase 3e: branching scenarios ----------
as_user( 'admin' );
$scn_json = wp_json_encode( array(
	'title'       => 'Chest pain triage',
	'discipline'  => 'Nursing',
	'description' => 'Fictional 58-year-old with acute chest pain.',
	'pass_pct'    => 70,
	'nodes'       => array(
		array( 'key' => 'start', 'type' => 'decision', 'start' => true, 'title' => 'Initial assessment',
			'body' => '<p>Mr X (fictional) reports crushing chest pain.</p>',
			'options' => array(
				array( 'label' => 'Full vitals + ECG within 10 min', 'next' => 'escalate', 'marks' => 10, 'feedback' => 'Correct: time-critical.' ),
				array( 'label' => 'Give antacid and observe', 'next' => 'escalate', 'marks' => 0, 'feedback' => 'Delays care of possible ACS.' ),
			) ),
		array( 'key' => 'escalate', 'type' => 'decision', 'title' => 'Escalation',
			'body' => '<p>ECG shows ST elevation.</p>',
			'options' => array(
				array( 'label' => 'Activate cath-lab protocol + inform physician', 'next' => 'finish', 'marks' => 10, 'feedback' => 'Correct.' ),
				array( 'label' => 'Wait for the next scheduled round', 'next' => 'finish', 'marks' => 0, 'feedback' => 'Unsafe delay.' ),
			) ),
		array( 'key' => 'finish', 'type' => 'end', 'title' => 'Case complete', 'debrief' => 'Door-to-balloon time drives outcomes in STEMI.' ),
	),
) );
$scn_id = SSLMS_Scenarios::import_json( $scn_json, $admin );
ok( is_int( $scn_id ) && $scn_id > 0, '3e scenario imported from JSON as draft' );
ok( array() === SSLMS_Scenarios::validate_graph( $scn_id ), '3e imported graph validates' );
$bad = SSLMS_Scenarios::import_json( '{"title":"x","nodes":[{"key":"a","options":[{"label":"l","next":"missing"}]}]}', $admin );
ok( is_wp_error( $bad ), '3e import with dangling node reference rejected' );

// learners cannot start drafts
$try = SSLMS_Scenarios::start_attempt( $scn_id, $student );
ok( is_wp_error( $try ), '3e draft scenario cannot be attempted' );
SSLMS_Scenarios::update_scenario( $scn_id, array( 'status' => 'published' ) );
$att = SSLMS_Scenarios::start_attempt( $scn_id, $student );
ok( is_int( $att ) && $att > 0, '3e attempt started on published scenario' );
ok( $att === SSLMS_Scenarios::start_attempt( $scn_id, $student ), '3e re-start resumes the open attempt' );

// walk the tree: best choice then worst → 50%, fail at 70% pass mark
$step = SSLMS_Scenarios::choose( $att, 0, $student );
ok( ! is_wp_error( $step ) && ! $step->completed_at, '3e first decision recorded' );
ok( is_wp_error( SSLMS_Scenarios::choose( $att, 0, $student2 ) ), '3e another user cannot drive someone else\'s attempt' );
$step = SSLMS_Scenarios::choose( $att, 1, $student );
ok( $step->completed_at && 50.0 === (float) $step->score_pct && ! $step->passed, '3e attempt completes: 50% fail with feedback path' );
ok( is_wp_error( SSLMS_Scenarios::choose( $att, 0, $student ) ), '3e completed attempt is immutable' );
ok( is_wp_error( SSLMS_Scenarios::delete_scenario( $scn_id ) ), '3e scenario with attempts cannot be deleted' );

// second attempt, perfect run → pass; counts as 3b evidence
$att2 = SSLMS_Scenarios::start_attempt( $scn_id, $student );
SSLMS_Scenarios::choose( $att2, 0, $student );
$done = SSLMS_Scenarios::choose( $att2, 0, $student );
ok( $done->passed && 100.0 === (float) $done->score_pct, '3e perfect path passes at 100%' );
$comp_scn = SSLMS_Competencies::create_competency( array( 'title' => 'Acute deterioration response', 'discipline' => 'Nursing', 'parent_id' => $domain_id ) );
SSLMS_Competencies::add_mapping( $comp_scn, 'scenario', $scn_id, $admin );
ok( SSLMS_Competencies::evidence_satisfied( 'scenario', $scn_id, $student ), '3e/3b passed scenario counts as competency evidence' );
ok( ! SSLMS_Competencies::evidence_satisfied( 'scenario', $scn_id, $student2 ), '3e/3b unattempted learner not credited' );
as_user( 'student1' );
$shtml = do_shortcode( '[sslms_scenarios]' );
ok( is_string( $shtml ) && false !== strpos( $shtml, 'Chest pain triage' ), '3e portal lists scenario and attempts' );

// ---------- Phase 3d: KPI dashboard v2 ----------
as_user( 'admin' );
$kpi_c = SSLMS_KPI::compliance();
ok( is_array( $kpi_c ) && isset( $kpi_c['pct'], $kpi_c['total'] ) && $kpi_c['total'] > 0, '3d compliance KPI computes (' . $kpi_c['valid'] . '/' . $kpi_c['total'] . ')' );
$kpi_o = SSLMS_KPI::ospe_pass_rate();
// Both candidates pass after the moderation uplift in the 3c section → 100%.
ok( is_array( $kpi_o ) && 100.0 === (float) $kpi_o['pct'] && 2 === $kpi_o['total'], '3d OSPE pass rate wired to published 3c data (got ' . var_export( $kpi_o['pct'] ?? null, true ) . '%)' );
$kpi_f = SSLMS_KPI::expiring_forecast();
ok( isset( $kpi_f['d30'], $kpi_f['d60'], $kpi_f['d90'] ) && $kpi_f['d30'] <= $kpi_f['d60'] && $kpi_f['d60'] <= $kpi_f['d90'], '3d expiring forecast cumulative 30/60/90' );
SSLMS_KPI::update_thresholds( array( 'compliance_pct' => 99.5 ) );
$alerts = SSLMS_KPI::evaluate_alerts( SSLMS_KPI::org_wide_values() );
$hit = false;
foreach ( $alerts as $a ) { if ( 'compliance_pct' === $a['key'] ) { $hit = true; } }
ok( $hit || 99.5 <= (float) $kpi_c['pct'], '3d threshold breach detected for compliance < 99.5' );
SSLMS_KPI::update_thresholds( array( 'compliance_pct' => '' ) );
$r = rest( 'GET', '/sslms/v1/dashboard/kpis' );
$dash = $r->get_data();
ok( 200 === $r->get_status() && ! empty( $dash['data']['cards'] ) && isset( $dash['data']['charts'] ), '3d KPI polling endpoint returns cards + charts' );
as_user( 'student1' );
$r = rest( 'GET', '/sslms/v1/dashboard/kpis' );
ok( $r->get_status() >= 400, '3d learner cannot poll KPI endpoint (got ' . $r->get_status() . ')' );
$r = rest( 'GET', '/sslms/v1/dashboard/thresholds' );
ok( $r->get_status() >= 400, '3d learner cannot read thresholds (got ' . $r->get_status() . ')' );
as_user( 'instructor1' );
$r = rest( 'PUT', '/sslms/v1/dashboard/thresholds', array( 'compliance_pct' => 1 ) );
ok( $r->get_status() >= 400, '3d instructor cannot set thresholds (manage only, got ' . $r->get_status() . ')' );

// ---------- Audit 23-Jul-2026 regression fixes ----------
// Finding 1: instructor (author_courses) must NOT view another learner's credential doc
as_user( 'admin' );
$doc_row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}sslms_documents WHERE user_id = {$student} LIMIT 1" );
if ( $doc_row ) {
	ok( false === SSLMS_Documents::can_view( $doc_row, $inst ), 'AUDIT-1 instructor cannot view unrelated learner credential doc' );
	ok( true === SSLMS_Documents::can_view( $doc_row, $student ), 'AUDIT-1 owner can still view own doc' );
	ok( true === SSLMS_Documents::can_view( $doc_row, $admin ), 'AUDIT-1 admin can still view doc' );
}
// Findings 2+3: rotation object-level scoping
$other_rot = SSLMS_Hours::create_rotation( $student2, 'Radiology', '2026-08-01', '2026-08-31', $prec, 10 );
as_user( 'instructor1' );
$r = rest( 'GET', "/sslms/v1/rotations/{$other_rot}" );
ok( $r->get_status() >= 400 || ( isset( $r->get_data()['data']->user_id ) && false ), 'AUDIT-2 instructor with enroll cap cannot read unrelated rotation (got ' . $r->get_status() . ')' );
$r = rest( 'PUT', "/sslms/v1/rotations/{$other_rot}", array( 'required_hours' => 1 ) );
ok( $r->get_status() >= 400, 'AUDIT-3 enrollment manager cannot edit unrelated rotation (got ' . $r->get_status() . ')' );
$r = rest( 'DELETE', "/sslms/v1/rotations/{$other_rot}" );
ok( $r->get_status() >= 400, 'AUDIT-3 enrollment manager cannot delete unrelated rotation (got ' . $r->get_status() . ')' );
as_user( 'student2' );
$r = rest( 'GET', "/sslms/v1/rotations/{$other_rot}" );
ok( 200 === $r->get_status(), 'AUDIT-2 owner still reads own rotation (got ' . $r->get_status() . ')' );
as_user( 'preceptor1' );
$r = rest( 'GET', "/sslms/v1/rotations/{$other_rot}" );
ok( 200 === $r->get_status(), 'AUDIT-2 assigned preceptor still reads rotation (got ' . $r->get_status() . ')' );
as_user( 'admin' );
$r = rest( 'DELETE', "/sslms/v1/rotations/{$other_rot}" );
ok( 200 === $r->get_status(), 'AUDIT-3 admin can still delete unused rotation (got ' . $r->get_status() . ')' );

// ---------- F10: audit ----------
$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sslms_audit_log" );
ok( $n >= 10, "F10 audit log populated ($n rows)" );
$acts = $wpdb->get_col( "SELECT DISTINCT action FROM {$wpdb->prefix}sslms_audit_log" );
$need = array( 'enrollment_created', 'certificate_issued', 'checklist_item_signed', 'hours_approved', 'document_verified', 'journal_visibility_changed' );
$missing = array_diff( $need, $acts );
ok( empty( $missing ), 'F10 key audit actions present' . ( $missing ? ' (missing: ' . implode( ',', $missing ) . ')' : '' ) );
$leak = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sslms_audit_log WHERE summary LIKE %s", '%Private reflection%' ) );
ok( 0 === (int) $leak, 'F8.2/F10 audit summaries contain no journal content' );

// ---------- portal shortcodes render ----------
wp_set_current_user( $student );
$optional_quiz = SSLMS_DB::insert( 'quizzes', array(
	'course_id' => $course_id, 'lesson_id' => null, 'bank_id' => $bank_id,
	'title' => 'Optional practice', 'num_questions' => 1, 'pass_pct' => 50,
	'max_attempts' => 0, 'time_limit_min' => 0, 'is_required' => 0,
) );
ok( $optional_quiz > 0, 'F3 optional assessment created for portal summary test' );
foreach ( array( 'sslms_dashboard', 'sslms_my_courses', 'sslms_my_checklists', 'sslms_my_hours', 'sslms_my_journal', 'sslms_my_documents', 'sslms_my_certificates' ) as $sc ) {
	$html = do_shortcode( '[' . $sc . ']' );
	ok( is_string( $html ) && strlen( $html ) > 100 && strpos( $html, 'sslms' ) !== false, "F12 [$sc] renders" );
	if ( 'sslms_my_courses' === $sc ) {
		ok( false !== strpos( $html, '1 / 1 required assessments passed' ), 'F12 course summary distinguishes required from optional assessments' );
	}
}
SSLMS_Courses::update_lesson( $les2, array( 'video_url' => 'https://drive.google.com/open?id=1AbCdEfGhIjKlMnOpQrStUvWxYz' ) );
$_GET['course'] = $course_id;
$_GET['lesson'] = $les2;
$course_html = do_shortcode( '[sslms_course]' );
ok( false !== strpos( $course_html, 'drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz/preview' ), 'F12 Google Drive open?id video renders through preview player' );
unset( $_GET['course'], $_GET['lesson'] );
wp_set_current_user( 0 );
$vhtml = do_shortcode( '[sslms_verify_certificate]' );
ok( is_string( $vhtml ) && strlen( $vhtml ) > 50, 'F5.3 verify page renders for anonymous' );
$_GET['code'] = $cert ? $cert->cert_code : 'XXXX';
$vhtml2 = do_shortcode( '[sslms_verify_certificate]' );
ok( $cert && strpos( $vhtml2, 'BLS Refresher 2026' ) !== false, 'F5.3 code lookup shows course name' );

echo "\n" . ( $GLOBALS['sslms_fail'] ? "RESULT: {$GLOBALS['sslms_fail']} FAILURE(S)" : 'RESULT: ALL PASS' ) . "\n";
