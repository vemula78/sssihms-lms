<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SSLMS_Install {

	const DB_VERSION = '1.4.0';

	public static function activate(): void {
		self::create_tables();
		SSLMS_Roles::install();
		self::create_pages();
		self::create_private_dir();
		if ( ! wp_next_scheduled( 'sslms_daily_expiry_check' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 06:00' ), 'daily', 'sslms_daily_expiry_check' );
		}
		update_option( 'sslms_db_version', self::DB_VERSION );
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'sslms_daily_expiry_check' );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'sslms_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
			SSLMS_Roles::install();
			// Too early for wp_insert_post here (plugins_loaded, $wp_rewrite not ready).
			add_action( 'init', array( __CLASS__, 'create_pages' ) );
			update_option( 'sslms_db_version', self::DB_VERSION );
		}
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$p = $wpdb->prefix . 'sslms_';

		$sql = array();

		$sql[] = "CREATE TABLE {$p}courses (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(200) NOT NULL,
  slug varchar(200) NOT NULL DEFAULT '',
  description text,
  track varchar(20) NOT NULL DEFAULT 'clinical',
  status varchar(20) NOT NULL DEFAULT 'draft',
  open_enrollment tinyint(1) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY track (track)
) $c;";

		$sql[] = "CREATE TABLE {$p}modules (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  course_id bigint(20) unsigned NOT NULL,
  title varchar(200) NOT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY course_id (course_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}lessons (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  module_id bigint(20) unsigned NOT NULL,
  title varchar(200) NOT NULL,
  content longtext,
  video_url varchar(500) NOT NULL DEFAULT '',
  attachments text,
  sort_order int(11) NOT NULL DEFAULT 0,
  est_minutes int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY module_id (module_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}enrollments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  course_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  enrolled_by bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  enrolled_at datetime NOT NULL,
  completed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY course_user (course_id,user_id),
  KEY user_id (user_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}question_banks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(200) NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
) $c;";

		$sql[] = "CREATE TABLE {$p}questions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  bank_id bigint(20) unsigned NOT NULL,
  qtype varchar(10) NOT NULL DEFAULT 'mcq',
  prompt text NOT NULL,
  options text,
  correct text,
  points int(11) NOT NULL DEFAULT 1,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY bank_id (bank_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}quizzes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  course_id bigint(20) unsigned NOT NULL,
  lesson_id bigint(20) unsigned DEFAULT NULL,
  bank_id bigint(20) unsigned NOT NULL,
  title varchar(200) NOT NULL,
  num_questions int(11) NOT NULL DEFAULT 10,
  pass_pct decimal(5,2) NOT NULL DEFAULT 70,
  max_attempts int(11) NOT NULL DEFAULT 0,
  time_limit_min int(11) NOT NULL DEFAULT 0,
  is_required tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY course_id (course_id),
  KEY lesson_id (lesson_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}quiz_attempts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  quiz_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  started_at datetime NOT NULL,
  submitted_at datetime DEFAULT NULL,
  question_ids text,
  answers longtext,
  score_pct decimal(5,2) DEFAULT NULL,
  passed tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY quiz_user (quiz_id,user_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}lesson_progress (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lesson_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  completed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY lesson_user (lesson_id,user_id),
  KEY user_id (user_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}certificates (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  enrollment_id bigint(20) unsigned NOT NULL,
  cert_code char(12) NOT NULL,
  issued_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY cert_code (cert_code),
  UNIQUE KEY enrollment_id (enrollment_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}checklists (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(200) NOT NULL,
  discipline varchar(100) NOT NULL DEFAULT '',
  description text,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id)
) $c;";

		$sql[] = "CREATE TABLE {$p}checklist_items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  checklist_id bigint(20) unsigned NOT NULL,
  item_text text NOT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY checklist_id (checklist_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}checklist_assignments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  checklist_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  assigned_by bigint(20) unsigned NOT NULL DEFAULT 0,
  assigned_at datetime NOT NULL,
  completed_at datetime DEFAULT NULL,
  locked tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY checklist_user (checklist_id,user_id),
  KEY user_id (user_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}checklist_signoffs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  assignment_id bigint(20) unsigned NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  rating varchar(20) NOT NULL,
  note text,
  signed_by bigint(20) unsigned NOT NULL,
  signed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY assignment_item (assignment_id,item_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}rotations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  department varchar(150) NOT NULL,
  start_date date NOT NULL,
  end_date date NOT NULL,
  preceptor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  required_hours decimal(7,2) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY preceptor_id (preceptor_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}hour_logs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rotation_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  log_date date NOT NULL,
  hours decimal(5,2) NOT NULL,
  activity text,
  status varchar(20) NOT NULL DEFAULT 'pending',
  reviewed_by bigint(20) unsigned DEFAULT NULL,
  reviewed_at datetime DEFAULT NULL,
  review_note text,
  PRIMARY KEY  (id),
  KEY rotation_id (rotation_id),
  KEY user_status (user_id,status)
) $c;";

		$sql[] = "CREATE TABLE {$p}journal_entries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  title varchar(200) NOT NULL DEFAULT '',
  body longtext,
  visibility varchar(20) NOT NULL DEFAULT 'private',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY visibility (visibility)
) $c;";

		$sql[] = "CREATE TABLE {$p}journal_comments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  author_id bigint(20) unsigned NOT NULL,
  body text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY entry_id (entry_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}relationships (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  related_user_id bigint(20) unsigned NOT NULL,
  rel_type varchar(20) NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY rel (user_id,related_user_id,rel_type),
  KEY related_user_id (related_user_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}documents (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  doc_type varchar(20) NOT NULL DEFAULT 'other',
  file_path varchar(500) NOT NULL,
  original_name varchar(255) NOT NULL,
  mime varchar(100) NOT NULL,
  issue_date date DEFAULT NULL,
  expiry_date date DEFAULT NULL,
  verified_by bigint(20) unsigned DEFAULT NULL,
  verified_at datetime DEFAULT NULL,
  uploaded_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY expiry_date (expiry_date)
) $c;";

		$sql[] = "CREATE TABLE {$p}profiles (
  user_id bigint(20) unsigned NOT NULL,
  staff_id varchar(50) NOT NULL DEFAULT '',
  discipline varchar(100) NOT NULL DEFAULT '',
  tracks varchar(100) NOT NULL DEFAULT '',
  consent_at datetime DEFAULT NULL,
  PRIMARY KEY  (user_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(64) NOT NULL,
  object_type varchar(40) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  summary text,
  ip varbinary(16) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY action (action),
  KEY created_at (created_at)
) $c;";

		// Phase 3a — rubrics engine + multi-source assessment records (ROADMAP-PHASE3.md).
		$sql[] = "CREATE TABLE {$p}rubrics (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(200) NOT NULL,
  form_type varchar(30) NOT NULL DEFAULT 'custom',
  discipline varchar(100) NOT NULL DEFAULT '',
  description text,
  pass_pct decimal(5,2) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY is_active (is_active)
) $c;";

		$sql[] = "CREATE TABLE {$p}rubric_criteria (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rubric_id bigint(20) unsigned NOT NULL,
  criterion_text text NOT NULL,
  weight decimal(6,2) NOT NULL DEFAULT 1,
  is_critical tinyint(1) NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY rubric_id (rubric_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}rubric_levels (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  criterion_id bigint(20) unsigned NOT NULL,
  level_index int(11) NOT NULL DEFAULT 0,
  label varchar(100) NOT NULL,
  descriptor text,
  marks decimal(6,2) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY criterion_id (criterion_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}assessment_records (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rubric_id bigint(20) unsigned NOT NULL,
  learner_id bigint(20) unsigned NOT NULL,
  assessor_id bigint(20) unsigned NOT NULL,
  assessor_role varchar(20) NOT NULL DEFAULT '',
  context varchar(200) NOT NULL DEFAULT '',
  feedback text,
  score_pct decimal(5,2) DEFAULT NULL,
  critical_failed tinyint(1) NOT NULL DEFAULT 0,
  outcome varchar(10) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'draft',
  created_at datetime NOT NULL,
  signed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY learner_id (learner_id),
  KEY assessor_status (assessor_id,status),
  KEY rubric_id (rubric_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}assessment_scores (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  record_id bigint(20) unsigned NOT NULL,
  criterion_id bigint(20) unsigned NOT NULL,
  level_id bigint(20) unsigned NOT NULL,
  marks decimal(6,2) NOT NULL DEFAULT 0,
  note text,
  PRIMARY KEY  (id),
  UNIQUE KEY record_criterion (record_id,criterion_id)
) $c;";

		// Phase 3b — competency framework + learner passport (ROADMAP-PHASE3.md).
		$sql[] = "CREATE TABLE {$p}competencies (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  discipline varchar(100) NOT NULL DEFAULT '',
  code varchar(30) NOT NULL DEFAULT '',
  title varchar(200) NOT NULL,
  description text,
  sort_order int(11) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY parent_id (parent_id),
  KEY discipline (discipline)
) $c;";

		$sql[] = "CREATE TABLE {$p}competency_map (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  competency_id bigint(20) unsigned NOT NULL,
  object_type varchar(30) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY map (competency_id,object_type,object_id),
  KEY object (object_type,object_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}competency_attainments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  competency_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  level varchar(20) NOT NULL,
  note text,
  signed_by bigint(20) unsigned NOT NULL,
  signed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY comp_user (competency_id,user_id),
  KEY user_id (user_id)
) $c;";

		// Phase 3c — OSPE station-based practical exams (ROADMAP-PHASE3.md).
		$sql[] = "CREATE TABLE {$p}ospe_exams (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(200) NOT NULL,
  discipline varchar(100) NOT NULL DEFAULT '',
  exam_date date DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  pass_pct decimal(5,2) NOT NULL DEFAULT 50,
  min_station_pct decimal(5,2) NOT NULL DEFAULT 0,
  coordinator_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $c;";

		$sql[] = "CREATE TABLE {$p}ospe_stations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  exam_id bigint(20) unsigned NOT NULL,
  station_no int(11) NOT NULL DEFAULT 0,
  title varchar(200) NOT NULL,
  station_type varchar(20) NOT NULL DEFAULT 'procedure',
  duration_min int(11) NOT NULL DEFAULT 5,
  max_marks decimal(6,2) NOT NULL DEFAULT 10,
  rubric_id bigint(20) unsigned NOT NULL DEFAULT 0,
  examiner_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY exam_id (exam_id),
  KEY examiner_id (examiner_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}ospe_candidates (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  exam_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  candidate_no int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'registered',
  total_pct decimal(5,2) DEFAULT NULL,
  outcome varchar(10) NOT NULL DEFAULT '',
  moderation_note text,
  PRIMARY KEY  (id),
  UNIQUE KEY exam_user (exam_id,user_id),
  KEY user_id (user_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}ospe_scores (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  station_id bigint(20) unsigned NOT NULL,
  candidate_id bigint(20) unsigned NOT NULL,
  marks decimal(6,2) NOT NULL DEFAULT 0,
  note text,
  signed_by bigint(20) unsigned NOT NULL,
  signed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY station_candidate (station_id,candidate_id),
  KEY candidate_id (candidate_id)
) $c;";

		// Phase 3e — branching clinical scenarios, Tier A (ROADMAP-PHASE3.md).
		$sql[] = "CREATE TABLE {$p}scenarios (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(200) NOT NULL,
  discipline varchar(100) NOT NULL DEFAULT '',
  description text,
  status varchar(20) NOT NULL DEFAULT 'draft',
  pass_pct decimal(5,2) NOT NULL DEFAULT 70,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $c;";

		$sql[] = "CREATE TABLE {$p}scenario_nodes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scenario_id bigint(20) unsigned NOT NULL,
  node_type varchar(20) NOT NULL DEFAULT 'decision',
  title varchar(200) NOT NULL DEFAULT '',
  body longtext,
  options longtext,
  debrief text,
  is_start tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY scenario_id (scenario_id)
) $c;";

		$sql[] = "CREATE TABLE {$p}scenario_attempts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scenario_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  current_node_id bigint(20) unsigned NOT NULL DEFAULT 0,
  path longtext,
  score decimal(8,2) NOT NULL DEFAULT 0,
  max_score decimal(8,2) NOT NULL DEFAULT 0,
  score_pct decimal(5,2) DEFAULT NULL,
  passed tinyint(1) NOT NULL DEFAULT 0,
  started_at datetime NOT NULL,
  completed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY scenario_user (scenario_id,user_id),
  KEY user_id (user_id)
) $c;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/** Portal pages: slug => [title, shortcode]. Page IDs stored in option sslms_pages. */
	public static function pages(): array {
		return array(
			'dashboard'    => array( 'LMS Dashboard', '[sslms_dashboard]' ),
			'my_courses'   => array( 'My Courses', '[sslms_my_courses]' ),
			'course'       => array( 'Course', '[sslms_course]' ),
			'my_checklists' => array( 'My Checklists', '[sslms_my_checklists]' ),
			'my_hours'     => array( 'My Hours', '[sslms_my_hours]' ),
			'my_journal'   => array( 'My Journal', '[sslms_my_journal]' ),
			'my_documents' => array( 'My Documents', '[sslms_my_documents]' ),
			'my_certificates' => array( 'My Certificates', '[sslms_my_certificates]' ),
			'my_assessments' => array( 'My Assessments', '[sslms_my_assessments]' ),
			'passport'       => array( 'Competency Passport', '[sslms_passport]' ),
			'my_ospe'        => array( 'OSPE', '[sslms_my_ospe]' ),
			'scenarios'      => array( 'Practice Scenarios', '[sslms_scenarios]' ),
			'verify'       => array( 'Verify Certificate', '[sslms_verify_certificate]' ),
		);
	}

	public static function create_pages(): void {
		$ids = get_option( 'sslms_pages', array() );
		foreach ( self::pages() as $key => $def ) {
			if ( ! empty( $ids[ $key ] ) && get_post_status( $ids[ $key ] ) ) {
				continue;
			}
			$ids[ $key ] = wp_insert_post( array(
				'post_title'   => $def[0],
				'post_content' => $def[1],
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_name'    => 'lms-' . str_replace( '_', '-', $key ),
			) );
		}
		update_option( 'sslms_pages', $ids );
	}

	private static function create_private_dir(): void {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'sslms-private';
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
	}
}
