<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for question banks, questions, quizzes (authoring — cap
 * sslms_author_courses) and quiz attempts (learner — cap sslms_learn).
 */
class SSLMS_REST_Quizzes extends SSLMS_REST_Base {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$ns = self::NS;

		/* ---------------- Question banks (authoring) ---------------- */

		register_rest_route( $ns, '/banks', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_banks' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_bank' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'title' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $v ) {
							return '' !== trim( (string) $v );
						},
					),
				),
			),
		) );

		register_rest_route( $ns, '/banks/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_bank' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_bank' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => array(
					'title' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $v ) {
							return '' !== trim( (string) $v );
						},
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_bank' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
			),
		) );

		/* ---------------- Questions (authoring) ---------------- */

		register_rest_route( $ns, '/banks/(?P<bank_id>\d+)/questions', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_questions' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_question' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => self::question_args(),
			),
		) );

		register_rest_route( $ns, '/questions/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_question' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => self::question_args(),
			),
		) );

		/* ---------------- Quizzes (authoring) ---------------- */

		register_rest_route( $ns, '/quizzes', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_quizzes' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_quiz' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => self::quiz_args(),
			),
		) );

		register_rest_route( $ns, '/quizzes/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_quiz' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_quiz' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
				'args'                => self::quiz_args(),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_quiz' ),
				'permission_callback' => self::can( 'sslms_author_courses' ),
			),
		) );

		/* ---------------- Learner attempt lifecycle ---------------- */

		register_rest_route( $ns, '/quizzes/(?P<id>\d+)/start', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'start_quiz' ),
			'permission_callback' => self::can( 'sslms_learn' ),
		) );

		register_rest_route( $ns, '/attempts/(?P<id>\d+)/submit', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'submit_attempt' ),
			'permission_callback' => self::can( 'sslms_learn' ),
			'args'                => array(
				'answers' => array(
					'required'          => true,
					'type'              => 'object',
					'validate_callback' => static function ( $v ) {
						return is_array( $v );
					},
				),
			),
		) );

		register_rest_route( $ns, '/quizzes/(?P<id>\d+)/attempts', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'my_attempts' ),
			'permission_callback' => self::can( 'sslms_learn' ),
		) );
	}

	/* ------------------------------------------------------------------ *
	 * Arg schemas.
	 * ------------------------------------------------------------------ */

	private static function question_args(): array {
		return array(
			'qtype'   => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => static function ( $v ) {
					return in_array( $v, array( 'mcq', 'multi', 'tf' ), true );
				},
			),
			'prompt'  => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'options' => array(
				'required'          => false,
				'type'              => 'array',
				'default'           => array(),
				'validate_callback' => static function ( $v ) {
					return is_array( $v );
				},
			),
			'correct' => array(
				'required'          => true,
				'type'              => 'array',
				'validate_callback' => static function ( $v ) {
					return is_array( $v ) && ! empty( $v );
				},
			),
			'points'  => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'is_active' => array(
				'required' => false,
				'default'  => true,
			),
		);
	}

	private static function quiz_args(): array {
		return array(
			'course_id'      => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'lesson_id'      => array(
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'bank_id'        => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'title'          => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $v ) {
					return '' !== trim( (string) $v );
				},
			),
			'num_questions'  => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'pass_pct'       => array(
				'required'          => true,
				'validate_callback' => static function ( $v ) {
					return is_numeric( $v ) && $v >= 0 && $v <= 100;
				},
			),
			'max_attempts'   => array(
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'time_limit_min' => array(
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'is_required'    => array(
				'required' => false,
				'default'  => true,
			),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Bank handlers.
	 * ------------------------------------------------------------------ */

	public static function list_banks( WP_REST_Request $req ) {
		global $wpdb;
		$banks_t = SSLMS_DB::table( 'question_banks' );
		$q_t     = SSLMS_DB::table( 'questions' );
		$rows    = $wpdb->get_results(
			"SELECT b.*, (SELECT COUNT(*) FROM {$q_t} q WHERE q.bank_id = b.id) AS question_count,
			 (SELECT COUNT(*) FROM {$q_t} q WHERE q.bank_id = b.id AND q.is_active = 1) AS active_count
			 FROM {$banks_t} b ORDER BY b.title ASC"
		);
		return self::ok( $rows );
	}

	public static function get_bank( WP_REST_Request $req ) {
		$bank = SSLMS_Quizzes::get_bank( (int) $req->get_param( 'id' ) );
		if ( ! $bank ) {
			return self::fail( 'sslms_no_bank', 'Question bank not found.', 404 );
		}
		return self::ok( $bank );
	}

	public static function create_bank( WP_REST_Request $req ) {
		$id = SSLMS_DB::insert( 'question_banks', array(
			'title'      => $req->get_param( 'title' ),
			'created_by' => get_current_user_id(),
		) );
		if ( ! $id ) {
			return self::fail( 'sslms_save_failed', 'Could not create the bank.', 500 );
		}
		SSLMS_Audit::log( 'bank_created', 'question_bank', $id, 'Bank "' . $req->get_param( 'title' ) . '" created' );
		return self::ok( SSLMS_DB::get_row( 'question_banks', $id ), 201 );
	}

	public static function update_bank( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		if ( ! SSLMS_Quizzes::get_bank( $id ) ) {
			return self::fail( 'sslms_no_bank', 'Question bank not found.', 404 );
		}
		SSLMS_DB::update( 'question_banks', array( 'title' => $req->get_param( 'title' ) ), array( 'id' => $id ) );
		SSLMS_Audit::log( 'bank_updated', 'question_bank', $id, 'Bank renamed' );
		return self::ok( SSLMS_DB::get_row( 'question_banks', $id ) );
	}

	public static function delete_bank( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		if ( ! SSLMS_Quizzes::get_bank( $id ) ) {
			return self::fail( 'sslms_no_bank', 'Question bank not found.', 404 );
		}
		if ( SSLMS_Quizzes::bank_in_use( $id ) ) {
			return self::fail( 'sslms_bank_in_use', 'This bank is used by one or more quizzes and cannot be deleted.', 409 );
		}
		global $wpdb;
		$wpdb->delete( SSLMS_DB::table( 'questions' ), array( 'bank_id' => $id ) );
		SSLMS_DB::delete( 'question_banks', array( 'id' => $id ) );
		SSLMS_Audit::log( 'bank_deleted', 'question_bank', $id, 'Bank deleted' );
		return self::ok( array( 'deleted' => true ) );
	}

	/* ------------------------------------------------------------------ *
	 * Question handlers.
	 * ------------------------------------------------------------------ */

	public static function list_questions( WP_REST_Request $req ) {
		$bank_id = (int) $req->get_param( 'bank_id' );
		if ( ! SSLMS_Quizzes::get_bank( $bank_id ) ) {
			return self::fail( 'sslms_no_bank', 'Question bank not found.', 404 );
		}
		return self::ok( SSLMS_Quizzes::questions_for_bank( $bank_id ) );
	}

	public static function create_question( WP_REST_Request $req ) {
		$bank_id = (int) $req->get_param( 'bank_id' );
		if ( ! SSLMS_Quizzes::get_bank( $bank_id ) ) {
			return self::fail( 'sslms_no_bank', 'Question bank not found.', 404 );
		}
		$payload = self::normalize_question_payload( $req );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$payload['bank_id'] = $bank_id;
		$id                 = SSLMS_DB::insert( 'questions', $payload );
		if ( ! $id ) {
			return self::fail( 'sslms_save_failed', 'Could not save the question.', 500 );
		}
		SSLMS_Audit::log( 'question_created', 'question', $id, 'Question added to bank #' . $bank_id );
		return self::ok( SSLMS_DB::get_row( 'questions', $id ), 201 );
	}

	public static function update_question( WP_REST_Request $req ) {
		$id       = (int) $req->get_param( 'id' );
		$existing = SSLMS_Quizzes::get_question( $id );
		if ( ! $existing ) {
			return self::fail( 'sslms_no_question', 'Question not found.', 404 );
		}
		$payload = self::normalize_question_payload( $req );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		SSLMS_DB::update( 'questions', $payload, array( 'id' => $id ) );
		SSLMS_Audit::log( 'question_updated', 'question', $id, 'Question updated' );
		return self::ok( SSLMS_DB::get_row( 'questions', $id ) );
	}

	/**
	 * Validate + normalize a question payload into DB-ready fields (bank_id
	 * excluded — callers attach it). Options are renumbered 1..n from
	 * whichever slots were non-empty; `correct` values are the 1-based slot
	 * positions as rendered by the admin form and are remapped through the
	 * same renumbering so gaps in the submitted option list never break the
	 * id/correct correspondence.
	 *
	 * @return array|WP_Error
	 */
	private static function normalize_question_payload( WP_REST_Request $req ) {
		$qtype = (string) $req->get_param( 'qtype' );
		if ( ! in_array( $qtype, array( 'mcq', 'multi', 'tf' ), true ) ) {
			return self::fail( 'sslms_bad_qtype', 'qtype must be mcq, multi or tf.', 400 );
		}

		$prompt = sanitize_textarea_field( (string) $req->get_param( 'prompt' ) );
		if ( '' === trim( $prompt ) ) {
			return self::fail( 'sslms_bad_prompt', 'Prompt is required.', 400 );
		}

		$points = max( 1, absint( $req->get_param( 'points' ) ?: 1 ) );

		if ( 'tf' === $qtype ) {
			$options = array(
				array( 'id' => 1, 'text' => 'True' ),
				array( 'id' => 2, 'text' => 'False' ),
			);
			$correct = array_values( array_unique( array_intersect(
				array_map( 'intval', (array) $req->get_param( 'correct' ) ),
				array( 1, 2 )
			) ) );
			if ( 1 !== count( $correct ) ) {
				return self::fail( 'sslms_bad_correct', 'True/False needs exactly one correct answer.', 400 );
			}
		} else {
			$raw_options = (array) $req->get_param( 'options' );
			$options     = array();
			$id_map      = array(); // 1-based submitted slot => renumbered option id
			$next_id     = 1;
			foreach ( array_values( $raw_options ) as $slot => $opt ) {
				$text = is_array( $opt ) ? (string) ( $opt['text'] ?? '' ) : (string) $opt;
				$text = sanitize_text_field( $text );
				if ( '' === trim( $text ) ) {
					continue;
				}
				$id_map[ $slot + 1 ] = $next_id;
				$options[]           = array( 'id' => $next_id, 'text' => $text );
				++$next_id;
			}
			if ( count( $options ) < 2 || count( $options ) > 8 ) {
				return self::fail( 'sslms_bad_options', 'Provide between 2 and 8 non-empty options.', 400 );
			}

			$raw_correct = array_map( 'intval', (array) $req->get_param( 'correct' ) );
			$correct     = array();
			foreach ( $raw_correct as $slot ) {
				if ( isset( $id_map[ $slot ] ) ) {
					$correct[] = $id_map[ $slot ];
				}
			}
			$correct = array_values( array_unique( $correct ) );
			if ( empty( $correct ) ) {
				return self::fail( 'sslms_bad_correct', 'Select at least one correct option.', 400 );
			}
			if ( 'mcq' === $qtype && 1 !== count( $correct ) ) {
				return self::fail( 'sslms_bad_correct', 'Single-answer MCQ needs exactly one correct option.', 400 );
			}
		}

		return array(
			'qtype'     => $qtype,
			'prompt'    => $prompt,
			'options'   => wp_json_encode( $options ),
			'correct'   => wp_json_encode( $correct ),
			'points'    => $points,
			'is_active' => $req->has_param( 'is_active' ) ? (int) (bool) $req->get_param( 'is_active' ) : 1,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Quiz handlers.
	 * ------------------------------------------------------------------ */

	/**
	 * Instructors may only touch quizzes of courses they created (mirrors
	 * SSLMS_REST_Courses::forbidden_unless_owner); sslms_manage bypasses.
	 */
	private static function forbidden_unless_course_owner( int $course_id ) {
		if ( current_user_can( 'sslms_manage' ) || SSLMS_Courses::can_edit( $course_id, get_current_user_id() ) ) {
			return null;
		}
		return self::fail( 'sslms_forbidden', 'You may only manage quizzes for courses you created.', 403 );
	}

	public static function list_quizzes( WP_REST_Request $req ) {
		global $wpdb;
		$quizzes_t = SSLMS_DB::table( 'quizzes' );
		$courses_t = SSLMS_DB::table( 'courses' );
		if ( current_user_can( 'sslms_manage' ) ) {
			$rows = $wpdb->get_results(
				"SELECT z.*, c.title AS course_title FROM {$quizzes_t} z
				 LEFT JOIN {$courses_t} c ON c.id = z.course_id ORDER BY z.id DESC"
			);
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT z.*, c.title AS course_title FROM {$quizzes_t} z
				 INNER JOIN {$courses_t} c ON c.id = z.course_id
				 WHERE c.created_by = %d ORDER BY z.id DESC",
				get_current_user_id()
			) );
		}
		return self::ok( $rows );
	}

	public static function get_quiz( WP_REST_Request $req ) {
		$quiz = SSLMS_Quizzes::get_quiz( (int) $req->get_param( 'id' ) );
		if ( ! $quiz ) {
			return self::fail( 'sslms_no_quiz', 'Quiz not found.', 404 );
		}
		$forbidden = self::forbidden_unless_course_owner( (int) $quiz->course_id );
		if ( $forbidden ) {
			return $forbidden;
		}
		return self::ok( $quiz );
	}

	public static function create_quiz( WP_REST_Request $req ) {
		$payload = self::normalize_quiz_payload( $req );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$forbidden = self::forbidden_unless_course_owner( (int) $payload['course_id'] );
		if ( $forbidden ) {
			return $forbidden;
		}
		$id = SSLMS_DB::insert( 'quizzes', $payload );
		if ( ! $id ) {
			return self::fail( 'sslms_save_failed', 'Could not create the quiz.', 500 );
		}
		SSLMS_Audit::log( 'quiz_created', 'quiz', $id, 'Quiz "' . $payload['title'] . '" created' );
		return self::ok( SSLMS_DB::get_row( 'quizzes', $id ), 201 );
	}

	public static function update_quiz( WP_REST_Request $req ) {
		$id   = (int) $req->get_param( 'id' );
		$quiz = SSLMS_Quizzes::get_quiz( $id );
		if ( ! $quiz ) {
			return self::fail( 'sslms_no_quiz', 'Quiz not found.', 404 );
		}
		$forbidden = self::forbidden_unless_course_owner( (int) $quiz->course_id );
		if ( $forbidden ) {
			return $forbidden;
		}
		$payload = self::normalize_quiz_payload( $req );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$forbidden = self::forbidden_unless_course_owner( (int) $payload['course_id'] );
		if ( $forbidden ) {
			return $forbidden;
		}
		SSLMS_DB::update( 'quizzes', $payload, array( 'id' => $id ) );
		SSLMS_Audit::log( 'quiz_updated', 'quiz', $id, 'Quiz updated' );
		return self::ok( SSLMS_DB::get_row( 'quizzes', $id ) );
	}

	public static function delete_quiz( WP_REST_Request $req ) {
		$id   = (int) $req->get_param( 'id' );
		$quiz = SSLMS_Quizzes::get_quiz( $id );
		if ( ! $quiz ) {
			return self::fail( 'sslms_no_quiz', 'Quiz not found.', 404 );
		}
		$forbidden = self::forbidden_unless_course_owner( (int) $quiz->course_id );
		if ( $forbidden ) {
			return $forbidden;
		}
		if ( SSLMS_Quizzes::quiz_has_attempts( $id ) ) {
			return self::fail( 'sslms_quiz_has_attempts', 'This quiz has recorded attempts and cannot be deleted (NABH audit trail).', 409 );
		}
		SSLMS_DB::delete( 'quizzes', array( 'id' => $id ) );
		SSLMS_Audit::log( 'quiz_deleted', 'quiz', $id, 'Quiz deleted' );
		return self::ok( array( 'deleted' => true ) );
	}

	/** @return array|WP_Error */
	private static function normalize_quiz_payload( WP_REST_Request $req ) {
		$course_id = absint( $req->get_param( 'course_id' ) );
		if ( ! self::row_exists( 'courses', $course_id ) ) {
			return self::fail( 'sslms_bad_course', 'Course not found.', 400 );
		}

		$lesson_id = absint( $req->get_param( 'lesson_id' ) ?: 0 );
		if ( $lesson_id ) {
			global $wpdb;
			$lessons_t = SSLMS_DB::table( 'lessons' );
			$modules_t = SSLMS_DB::table( 'modules' );
			$ok        = $wpdb->get_var( $wpdb->prepare(
				"SELECT l.id FROM {$lessons_t} l INNER JOIN {$modules_t} m ON m.id = l.module_id WHERE l.id = %d AND m.course_id = %d",
				$lesson_id, $course_id
			) );
			if ( ! $ok ) {
				return self::fail( 'sslms_bad_lesson', 'The selected lesson does not belong to the selected course.', 400 );
			}
		}

		$bank_id = absint( $req->get_param( 'bank_id' ) );
		if ( ! self::row_exists( 'question_banks', $bank_id ) ) {
			return self::fail( 'sslms_bad_bank', 'Question bank not found.', 400 );
		}

		$title = sanitize_text_field( (string) $req->get_param( 'title' ) );
		if ( '' === trim( $title ) ) {
			return self::fail( 'sslms_bad_title', 'Title is required.', 400 );
		}

		$pass_pct = (float) $req->get_param( 'pass_pct' );
		if ( $pass_pct < 0 || $pass_pct > 100 ) {
			return self::fail( 'sslms_bad_pass_pct', 'Pass percentage must be between 0 and 100.', 400 );
		}

		return array(
			'course_id'      => $course_id,
			'lesson_id'      => $lesson_id ?: null,
			'bank_id'        => $bank_id,
			'title'          => $title,
			'num_questions'  => max( 1, absint( $req->get_param( 'num_questions' ) ) ),
			'pass_pct'       => $pass_pct,
			'max_attempts'   => max( 0, absint( $req->get_param( 'max_attempts' ) ) ),
			'time_limit_min' => max( 0, absint( $req->get_param( 'time_limit_min' ) ) ),
			'is_required'    => $req->has_param( 'is_required' ) ? (int) (bool) $req->get_param( 'is_required' ) : 1,
		);
	}

	private static function row_exists( string $table, int $id ): bool {
		return $id > 0 && null !== SSLMS_DB::get_row( $table, $id );
	}

	/* ------------------------------------------------------------------ *
	 * Learner attempt lifecycle.
	 * ------------------------------------------------------------------ */

	public static function start_quiz( WP_REST_Request $req ) {
		$quiz_id = (int) $req->get_param( 'id' );
		$result  = SSLMS_Quizzes::start_attempt( $quiz_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( $result );
	}

	public static function submit_attempt( WP_REST_Request $req ) {
		$attempt_id = (int) $req->get_param( 'id' );
		$answers    = (array) $req->get_param( 'answers' );
		$result     = SSLMS_Quizzes::submit_attempt( $attempt_id, get_current_user_id(), $answers );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( $result );
	}

	public static function my_attempts( WP_REST_Request $req ) {
		$quiz_id = (int) $req->get_param( 'id' );
		if ( ! SSLMS_Quizzes::get_quiz( $quiz_id ) ) {
			return self::fail( 'sslms_no_quiz', 'Quiz not found.', 404 );
		}
		return self::ok( SSLMS_Quizzes::attempt_history( $quiz_id, get_current_user_id() ) );
	}
}
SSLMS_REST_Quizzes::init();
