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
foreach ( array( 'sslms_dashboard', 'sslms_my_courses', 'sslms_my_checklists', 'sslms_my_hours', 'sslms_my_journal', 'sslms_my_documents', 'sslms_my_certificates' ) as $sc ) {
	$html = do_shortcode( '[' . $sc . ']' );
	ok( is_string( $html ) && strlen( $html ) > 100 && strpos( $html, 'sslms' ) !== false, "F12 [$sc] renders" );
}
wp_set_current_user( 0 );
$vhtml = do_shortcode( '[sslms_verify_certificate]' );
ok( is_string( $vhtml ) && strlen( $vhtml ) > 50, 'F5.3 verify page renders for anonymous' );
$_GET['code'] = $cert ? $cert->cert_code : 'XXXX';
$vhtml2 = do_shortcode( '[sslms_verify_certificate]' );
ok( $cert && strpos( $vhtml2, 'BLS Refresher 2026' ) !== false, 'F5.3 code lookup shows course name' );

echo "\n" . ( $GLOBALS['sslms_fail'] ? "RESULT: {$GLOBALS['sslms_fail']} FAILURE(S)" : 'RESULT: ALL PASS' ) . "\n";
