<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SSLMS_Install {

	const DB_VERSION = '1.0.0';

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
			'verify'       => array( 'Verify Certificate', '[sslms_verify_certificate]' ),
		);
	}

	private static function create_pages(): void {
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
