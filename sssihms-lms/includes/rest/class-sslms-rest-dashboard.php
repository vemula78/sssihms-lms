<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 3d — KPI dashboard v2 self-refresh endpoint. Namespace sslms/v1.
 *
 * GET /dashboard/kpis polls the same card/chart builders the admin page
 * itself renders (SSLMS_Admin_Dashboard) so the two never diverge, plus
 * SSLMS_KPI::evaluate_alerts() for the alert banner. Capability floor is
 * sslms_view_reports (instructors see their own-course scope, same as the
 * page); the new org-wide KPI cards/alerts are populated only when the
 * caller also has sslms_manage — identical to how the page already hides
 * those cards from instructors, department-scoped or not.
 *
 * GET/PUT /dashboard/thresholds (sslms_manage only) reads/writes the
 * options-based threshold config.
 */
class SSLMS_REST_Dashboard extends SSLMS_REST_Base {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {

		register_rest_route( self::NS, '/dashboard/kpis', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'kpis' ),
			'permission_callback' => self::can( 'sslms_view_reports' ),
			'args'                => array(
				'department' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				),
			),
		) );

		register_rest_route( self::NS, '/dashboard/thresholds', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_thresholds' ),
				'permission_callback' => self::can( 'sslms_manage' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'put_thresholds' ),
				'permission_callback' => self::can( 'sslms_manage' ),
				'args'                => self::threshold_args(),
			),
		) );
	}

	private static function threshold_args(): array {
		$args = array();
		foreach ( SSLMS_KPI::KPI_DEFS as $key => $def ) {
			$args[ $key ] = array(
				'required'          => false,
				'validate_callback' => array( __CLASS__, 'is_blank_or_number' ),
			);
		}
		return $args;
	}

	public static function is_blank_or_number( $value ): bool {
		return '' === $value || null === $value || is_numeric( $value );
	}

	/* ---------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------- */

	public static function kpis( WP_REST_Request $req ): WP_REST_Response {
		$departments = SSLMS_KPI::departments();
		$department  = (string) $req->get_param( 'department' );
		if ( '' !== $department && ! in_array( $department, $departments, true ) ) {
			$department = '';
		}

		$is_manager = current_user_can( 'sslms_manage' );
		$uid        = get_current_user_id();

		$cards = SSLMS_Admin_Dashboard::stat_cards( $is_manager, $uid, $department );

		$raw = array();
		foreach ( $cards as $c ) {
			if ( isset( $c['key'], $c['raw'] ) && array_key_exists( $c['key'], SSLMS_KPI::KPI_DEFS ) ) {
				$raw[ $c['key'] ] = $c['raw'];
			}
		}

		return self::ok( array(
			'department'  => $department,
			'departments' => $departments,
			'cards'       => $cards,
			'charts'      => array(
				'sslms-chart-enrol' => SSLMS_Admin_Dashboard::enrolments_vs_completions( $is_manager, $uid, $department ),
				'sslms-chart-month' => SSLMS_Admin_Dashboard::completions_per_month( $is_manager, $uid, $department ),
				'sslms-chart-quiz'  => SSLMS_Admin_Dashboard::quiz_pass_rates( $is_manager, $uid, $department ),
			),
			'alerts'      => $is_manager ? SSLMS_KPI::evaluate_alerts( $raw ) : array(),
		) );
	}

	public static function get_thresholds(): WP_REST_Response {
		return self::ok( array(
			'defs'       => SSLMS_KPI::KPI_DEFS,
			'thresholds' => SSLMS_KPI::get_thresholds(),
		) );
	}

	public static function put_thresholds( WP_REST_Request $req ): WP_REST_Response {
		$values = array();
		foreach ( SSLMS_KPI::KPI_DEFS as $key => $def ) {
			$v = $req->get_param( $key );
			if ( null !== $v ) {
				$values[ $key ] = $v;
			}
		}
		return self::ok( SSLMS_KPI::update_thresholds( $values ) );
	}
}
SSLMS_REST_Dashboard::init();
