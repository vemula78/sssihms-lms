<?php
/**
 * Seeds fictional demo data for the Phase 3 screens (rubrics/assessments,
 * competency passport, OSPE, scenarios, KPI thresholds) on top of the
 * Phase 1 demo content from seed-demo-data.php. Idempotent: checks titles
 * before creating. Run:
 *   wp --path=/srv/www/wordpress eval-file seed-phase3-demo.php --url=demo.sssihms.org
 */

function p3_user( string $login ): int {
	$u = get_user_by( 'login', $login );
	return $u ? $u->ID : 0;
}

$instructor = p3_user( 'demoinstructor' );
$student1   = p3_user( 'demostudent1' );   // Nursing / clinical
$student2   = p3_user( 'demostudent2' );   // Chaplaincy + allied
$preceptor  = p3_user( 'demopreceptor' );
$mentor     = p3_user( 'demomentor' );
$evaluator  = p3_user( 'demoevaluator' );
$admin      = p3_user( 'demo' ) ?: p3_user( 'admin' );

foreach ( compact( 'instructor', 'student1', 'student2', 'preceptor', 'mentor', 'evaluator', 'admin' ) as $k => $v ) {
	if ( ! $v ) {
		echo "MISSING USER: $k — aborting.\n";
		exit( 1 );
	}
}
wp_set_current_user( $admin );
global $wpdb;

function p3_find( string $table, string $col, string $val ) {
	global $wpdb;
	$t = SSLMS_DB::table( $table );
	return $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE {$col} = %s", $val ) );
}

/* ------------------------------------------------------------------
 * 1. Rubrics: a DOPS and a Mini-CEX with levels + critical criterion
 * ---------------------------------------------------------------- */
$dops_id = p3_find( 'rubrics', 'title', 'IV Cannulation — DOPS' );
if ( ! $dops_id ) {
	$dops_id = SSLMS_Rubrics::create_rubric( array(
		'title' => 'IV Cannulation — DOPS', 'form_type' => 'dops', 'discipline' => 'Nursing',
		'description' => 'Direct observation of peripheral IV cannulation on the ward.', 'pass_pct' => 60,
	), $admin );
	$crits = array(
		array( 'Hand hygiene and aseptic non-touch technique', 2, true ),
		array( 'Patient identification, explanation and consent', 1, false ),
		array( 'Vein selection and first-attempt insertion technique', 1.5, false ),
		array( 'Securing, labelling and documentation', 1, false ),
	);
	foreach ( $crits as $c ) {
		$cid = SSLMS_Rubrics::add_criterion( $dops_id, $c[0], $c[1], $c[2] );
		SSLMS_Rubrics::add_level( $cid, 'Unsafe / not done', 'Breaches safety or omits the step entirely', 0 );
		SSLMS_Rubrics::add_level( $cid, 'Developing', 'Performs with prompting or minor lapses', 5 );
		SSLMS_Rubrics::add_level( $cid, 'Competent', 'Performs independently and correctly', 10 );
	}
	echo "CREATED rubric: IV Cannulation — DOPS\n";
} else {
	echo "SKIP rubric: IV Cannulation — DOPS\n";
}

$cex_id = p3_find( 'rubrics', 'title', 'Patient Communication — Mini-CEX' );
if ( ! $cex_id ) {
	$cex_id = SSLMS_Rubrics::create_rubric( array(
		'title' => 'Patient Communication — Mini-CEX', 'form_type' => 'mini_cex', 'discipline' => 'Chaplaincy',
		'description' => 'Observed patient/family interaction — affective domain.', 'pass_pct' => 50,
	), $admin );
	foreach ( array( 'Presence and active listening', 'Empathy and dignity', 'Clarity and appropriateness of response' ) as $text ) {
		$cid = SSLMS_Rubrics::add_criterion( $cex_id, $text, 1, false );
		SSLMS_Rubrics::add_level( $cid, 'Below expectations', 'Distracted, interrupts, or misjudges the moment', 2 );
		SSLMS_Rubrics::add_level( $cid, 'Meets expectations', 'Attentive and appropriate throughout', 6 );
		SSLMS_Rubrics::add_level( $cid, 'Exceeds expectations', 'Exceptional rapport; patient visibly at ease', 10 );
	}
	echo "CREATED rubric: Patient Communication — Mini-CEX\n";
} else {
	echo "SKIP rubric: Patient Communication — Mini-CEX\n";
}

/* ------------------------------------------------------------------
 * 2. Assessment records: signed pass, signed mentor CEX, one open draft
 * ---------------------------------------------------------------- */
function p3_score_all( int $record_id, int $assessor, int $level_index /* 0-based from bottom */ ): void {
	$detail = SSLMS_Rubrics::record_detail( $record_id );
	foreach ( $detail->criteria as $c ) {
		$levels = array_values( (array) $c->levels );
		$pick   = $levels[ min( $level_index, count( $levels ) - 1 ) ];
		SSLMS_Rubrics::score_criterion( $record_id, (int) $c->criterion_id, (int) $pick->id, '', $assessor );
	}
}

$ar_t = SSLMS_DB::table( 'assessment_records' );
$have = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ar_t} WHERE rubric_id = %d AND learner_id = %d", $dops_id, $student1 ) );
if ( ! $have ) {
	// Signed pass by the preceptor.
	$rec = SSLMS_Rubrics::open_record( (int) $dops_id, $student1, $preceptor, 'CCU bedside, morning shift' );
	p3_score_all( (int) $rec, $preceptor, 2 );
	SSLMS_Rubrics::sign_record( (int) $rec, 'Confident, safe technique. First-attempt success; keep narrating steps to the patient.', $preceptor );
	// Learner self-assessment, signed, slightly humbler scores.
	$self = SSLMS_Rubrics::open_record( (int) $dops_id, $student1, $student1, 'Self-review after ward round' );
	p3_score_all( (int) $self, $student1, 1 );
	SSLMS_Rubrics::sign_record( (int) $self, 'I still hesitate on vein selection with difficult access.', $student1 );
	// One open draft so the "in progress" card shows.
	SSLMS_Rubrics::open_record( (int) $dops_id, $student1, $preceptor, 'Repeat observation — scheduled' );
	echo "CREATED assessments for demostudent1 (signed pass + self + open draft)\n";
} else {
	echo "SKIP assessments for demostudent1\n";
}

$have = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ar_t} WHERE rubric_id = %d AND learner_id = %d", $cex_id, $student2 ) );
if ( ! $have ) {
	$rec = SSLMS_Rubrics::open_record( (int) $cex_id, $student2, $mentor, 'Family meeting, palliative ward' );
	p3_score_all( (int) $rec, $mentor, 2 );
	SSLMS_Rubrics::sign_record( (int) $rec, 'Held the silence beautifully; the family felt heard.', $mentor );
	echo "CREATED mini-CEX for demostudent2 (signed by mentor)\n";
} else {
	echo "SKIP mini-CEX for demostudent2\n";
}

/* ------------------------------------------------------------------
 * 3. Competencies: starter set + evidence mapping + one signed attainment
 * ---------------------------------------------------------------- */
SSLMS_Competencies::seed_starter_set( $admin ); // no-op if already seeded

$comp_t  = SSLMS_DB::table( 'competencies' );
$iv_comp = $wpdb->get_var( "SELECT id FROM {$comp_t} WHERE title = 'Infection prevention & control' AND discipline = 'Nursing' LIMIT 1" );
$listen  = $wpdb->get_var( "SELECT id FROM {$comp_t} WHERE title = 'Presence & listening' AND discipline = 'Chaplaincy' LIMIT 1" );
$bls     = $wpdb->get_var( "SELECT id FROM {$comp_t} WHERE title = 'Basic life support' AND discipline = 'Nursing' LIMIT 1" );
$quiz_id = $wpdb->get_var( 'SELECT id FROM ' . SSLMS_DB::table( 'quizzes' ) . ' ORDER BY id ASC LIMIT 1' );

if ( $iv_comp ) {
	SSLMS_Competencies::add_mapping( (int) $iv_comp, 'rubric', (int) $dops_id, $admin ); // WP_Error dupes are fine
	$att = SSLMS_Competencies::latest_attainment( (int) $iv_comp, $student1 );
	if ( ! $att ) {
		SSLMS_Competencies::sign_attainment( (int) $iv_comp, $student1, 'competent', 'DOPS passed under direct observation, 23-Jul-2026.', $admin );
		echo "CREATED attainment: Infection prevention & control = competent (demostudent1)\n";
	}
}
if ( $listen ) {
	SSLMS_Competencies::add_mapping( (int) $listen, 'rubric', (int) $cex_id, $admin );
}
if ( $bls && $quiz_id ) {
	SSLMS_Competencies::add_mapping( (int) $bls, 'quiz', (int) $quiz_id, $admin );
}
echo "Competency mappings ensured\n";

/* ------------------------------------------------------------------
 * 4. OSPE: one published exam with results + one scheduled upcoming exam
 * ---------------------------------------------------------------- */
$ospe1 = p3_find( 'ospe_exams', 'title', 'Nursing Skills OSPE — June 2026' );
if ( ! $ospe1 ) {
	$ospe1 = SSLMS_OSPE::create_exam( array(
		'title' => 'Nursing Skills OSPE — June 2026', 'discipline' => 'Nursing',
		'exam_date' => '2026-06-26', 'pass_pct' => 50, 'min_station_pct' => 30,
	), $admin );
	$s1 = SSLMS_OSPE::add_station( $ospe1, array( 'title' => 'IV cannulation (manikin)', 'station_type' => 'procedure', 'duration_min' => 8, 'max_marks' => 20, 'examiner_id' => $preceptor, 'rubric_id' => (int) $dops_id ) );
	$s2 = SSLMS_OSPE::add_station( $ospe1, array( 'title' => 'Drug dose calculation viva', 'station_type' => 'question', 'duration_min' => 5, 'max_marks' => 10, 'examiner_id' => $evaluator ) );
	$s3 = SSLMS_OSPE::add_station( $ospe1, array( 'title' => 'Rest station', 'station_type' => 'rest', 'duration_min' => 5 ) );
	$c1 = SSLMS_OSPE::add_candidate( $ospe1, $student1 );
	$c2 = SSLMS_OSPE::add_candidate( $ospe1, $student2 );
	SSLMS_OSPE::score( $s1, $c1, 17, 'Clean technique', $preceptor );
	SSLMS_OSPE::score( $s2, $c1, 8, '', $evaluator );
	SSLMS_OSPE::score( $s1, $c2, 9, 'Hesitant, needed prompting', $preceptor );
	SSLMS_OSPE::score( $s2, $c2, 7, '', $evaluator );
	SSLMS_OSPE::publish( $ospe1, $admin );
	echo "CREATED published OSPE: Nursing Skills OSPE — June 2026\n";
} else {
	echo "SKIP OSPE June\n";
}

$ospe2 = p3_find( 'ospe_exams', 'title', 'Nursing Skills OSPE — August 2026' );
if ( ! $ospe2 ) {
	$ospe2 = SSLMS_OSPE::create_exam( array(
		'title' => 'Nursing Skills OSPE — August 2026', 'discipline' => 'Nursing',
		'exam_date' => '2026-08-21', 'pass_pct' => 50, 'min_station_pct' => 30,
	), $admin );
	SSLMS_OSPE::update_exam( $ospe2, array( 'status' => 'scheduled' ) );
	SSLMS_OSPE::add_station( $ospe2, array( 'title' => 'Wound dressing (aseptic)', 'station_type' => 'procedure', 'duration_min' => 10, 'max_marks' => 20, 'examiner_id' => $preceptor ) );
	SSLMS_OSPE::add_station( $ospe2, array( 'title' => 'ECG lead placement', 'station_type' => 'procedure', 'duration_min' => 6, 'max_marks' => 10, 'examiner_id' => $evaluator ) );
	SSLMS_OSPE::add_candidate( $ospe2, $student1 );
	SSLMS_OSPE::add_candidate( $ospe2, $student2 );
	echo "CREATED scheduled OSPE: Nursing Skills OSPE — August 2026 (examiner worklist demo)\n";
} else {
	echo "SKIP OSPE August\n";
}

// Map an OSPE station from the published exam as competency evidence.
if ( $bls && $ospe1 ) {
	$stn = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . SSLMS_DB::table( 'ospe_stations' ) . ' WHERE exam_id = %d AND station_type = %s LIMIT 1', $ospe1, 'question' ) );
	if ( $stn ) {
		SSLMS_Competencies::add_mapping( (int) $bls, 'ospe_station', (int) $stn, $admin );
	}
}

/* ------------------------------------------------------------------
 * 5. Scenarios: two published cases with attempts
 * ---------------------------------------------------------------- */
function p3_scenario( string $json, int $admin ): int {
	$data = json_decode( $json, true );
	$id   = p3_find( 'scenarios', 'title', $data['title'] );
	if ( $id ) {
		echo 'SKIP scenario: ' . $data['title'] . "\n";
		return (int) $id;
	}
	$id = SSLMS_Scenarios::import_json( $json, $admin );
	if ( is_wp_error( $id ) ) {
		echo 'FAIL scenario import: ' . $id->get_error_message() . "\n";
		return 0;
	}
	SSLMS_Scenarios::update_scenario( (int) $id, array( 'status' => 'published' ) );
	echo 'CREATED scenario: ' . $data['title'] . "\n";
	return (int) $id;
}

$scn1 = p3_scenario( wp_json_encode( array(
	'title' => 'Chest Pain in the Corridor', 'discipline' => 'Nursing', 'pass_pct' => 70,
	'description' => 'A fictional 58-year-old visitor develops crushing chest pain outside the OPD.',
	'nodes' => array(
		array( 'key' => 'start', 'type' => 'decision', 'start' => true, 'title' => 'First response',
			'body' => '<p>A visitor clutches his chest and slumps against the wall. He is pale and sweating.</p>',
			'options' => array(
				array( 'label' => 'Stay with him, call for help and a wheelchair, get him to casualty', 'next' => 'triage', 'marks' => 10, 'feedback' => 'Correct — never leave him, escalate immediately.' ),
				array( 'label' => 'Ask him to walk to casualty by himself', 'next' => 'triage', 'marks' => 0, 'feedback' => 'Walking increases myocardial demand and he may collapse alone.' ),
			) ),
		array( 'key' => 'triage', 'type' => 'decision', 'title' => 'In casualty',
			'body' => '<p>He is on a trolley. The casualty nurse looks to you.</p>',
			'options' => array(
				array( 'label' => 'Vitals + 12-lead ECG within 10 minutes, inform the duty doctor', 'next' => 'end', 'marks' => 10, 'feedback' => 'Correct — time-critical ACS pathway.' ),
				array( 'label' => 'Register him first and wait for the doctor\'s round', 'next' => 'end', 'marks' => 0, 'feedback' => 'Paperwork never precedes an ECG in chest pain.' ),
			) ),
		array( 'key' => 'end', 'type' => 'end', 'title' => 'Case complete',
			'debrief' => 'Chest pain is an ECG-first presentation. Door-to-ECG under 10 minutes; escalate before documenting.' ),
	),
) ), $admin );

$scn2 = p3_scenario( wp_json_encode( array(
	'title' => 'The Anxious Relative', 'discipline' => 'Chaplaincy', 'pass_pct' => 60,
	'description' => 'A fictional encounter with a distressed relative outside the ICU.',
	'nodes' => array(
		array( 'key' => 'start', 'type' => 'decision', 'start' => true, 'title' => 'Outside the ICU',
			'body' => '<p>A woman is weeping outside the ICU. Her father was admitted an hour ago.</p>',
			'options' => array(
				array( 'label' => 'Sit with her, listen first, ask nothing until she is ready', 'next' => 'talk', 'marks' => 10, 'feedback' => 'Presence before words.' ),
				array( 'label' => 'Reassure her immediately that everything will be fine', 'next' => 'talk', 'marks' => 3, 'feedback' => 'Well-meant, but false reassurance can close the conversation.' ),
			) ),
		array( 'key' => 'talk', 'type' => 'decision', 'title' => 'She asks: "Will he survive?"',
			'body' => '<p>She looks at you directly and asks a question only the doctors can answer.</p>',
			'options' => array(
				array( 'label' => 'Acknowledge the fear, stay honest about what you do not know, offer to walk with her to the care team', 'next' => 'end', 'marks' => 10, 'feedback' => 'Honesty plus accompaniment.' ),
				array( 'label' => 'Quote survival statistics you read recently', 'next' => 'end', 'marks' => 0, 'feedback' => 'Clinical prognosis is not the chaplain\'s role.' ),
			) ),
		array( 'key' => 'end', 'type' => 'end', 'title' => 'Reflection',
			'debrief' => 'The ministry of presence: listen first, never promise outcomes, connect the family to the clinical team.' ),
	),
) ), $admin );

$sat = SSLMS_DB::table( 'scenario_attempts' );
if ( $scn1 && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$sat} WHERE scenario_id = %d AND user_id = %d", $scn1, $student1 ) ) ) {
	$a = SSLMS_Scenarios::start_attempt( $scn1, $student1 );
	SSLMS_Scenarios::choose( (int) $a, 0, $student1 );
	SSLMS_Scenarios::choose( (int) $a, 0, $student1 ); // perfect run → pass
	echo "CREATED scenario attempt: demostudent1 passes Chest Pain\n";
}
if ( $scn2 && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$sat} WHERE scenario_id = %d AND user_id = %d", $scn2, $student2 ) ) ) {
	$a = SSLMS_Scenarios::start_attempt( $scn2, $student2 );
	SSLMS_Scenarios::choose( (int) $a, 1, $student2 );
	SSLMS_Scenarios::choose( (int) $a, 0, $student2 ); // 13/20 = 65% → pass at 60
	echo "CREATED scenario attempt: demostudent2 completes Anxious Relative\n";
}
if ( $scn1 ) {
	// Map the clinical scenario as evidence for deterioration response.
	$deter = $wpdb->get_var( "SELECT id FROM {$comp_t} WHERE title = 'Recognising deterioration' AND discipline = 'Nursing' LIMIT 1" );
	if ( $deter ) {
		SSLMS_Competencies::add_mapping( (int) $deter, 'scenario', (int) $scn1, $admin );
	}
}

/* ------------------------------------------------------------------
 * 6. KPI thresholds (visible alert banner + settings panel demo)
 * ---------------------------------------------------------------- */
if ( class_exists( 'SSLMS_KPI' ) && ! SSLMS_KPI::get_thresholds() ) {
	SSLMS_KPI::update_thresholds( array(
		'compliance_pct' => 90,   // likely breached on demo data → visible banner
		'expiring_30'    => 5,
	) );
	echo "CREATED KPI thresholds (compliance < 90, expiring-30 > 5)\n";
} else {
	echo "SKIP KPI thresholds\n";
}

echo "Phase 3 demo seed done.\n";
