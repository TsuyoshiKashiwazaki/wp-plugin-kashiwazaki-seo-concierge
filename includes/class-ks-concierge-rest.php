<?php
/**
 * REST API endpoints for Kashiwazaki SEO Concierge.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_REST
 */
class Ks_Concierge_REST {

	const NAMESPACE = 'ks-concierge/v1';

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Define the routes.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			self::NAMESPACE,
			'/ask',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_ask' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'question' => array(
						'required' => true,
						'type'     => 'string',
					),
					'consent'  => array(
						'required' => false,
						'type'     => 'boolean',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/click',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_click' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_pages' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
	}

	/**
	 * Capability check for management endpoints.
	 *
	 * @return bool
	 */
	public function admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Handle a visitor question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_ask( WP_REST_Request $request ) {
		if ( ! Ks_Concierge_Security::verify_origin() ) {
			return new WP_REST_Response(
				array( 'error' => __( 'リクエストがブロックされました。', 'kashiwazaki-seo-concierge' ) ),
				403
			);
		}
		if ( ! Ks_Concierge_Security::check_rate_limit() ) {
			return new WP_REST_Response(
				array( 'error' => __( 'リクエストが多すぎます。しばらくしてからもう一度お試しください。', 'kashiwazaki-seo-concierge' ) ),
				429
			);
		}

		$question = Ks_Concierge_Security::sanitize_question( (string) $request->get_param( 'question' ) );
		if ( '' === $question ) {
			return new WP_REST_Response(
				array( 'error' => __( '質問を入力してください。', 'kashiwazaki-seo-concierge' ) ),
				400
			);
		}

		if ( Ks_Concierge_Security::is_blocked( $question ) ) {
			return new WP_REST_Response(
				array(
					'answer'     => __( '申し訳ありませんが、その内容にはお答えできません。', 'kashiwazaki-seo-concierge' ),
					'candidates' => array(),
					'fallback'   => true,
				),
				200
			);
		}

		// Consent gate: when consent is required, do not send to the AI or log
		// until the visitor has explicitly consented.
		$consent = (bool) $request->get_param( 'consent' );
		if ( (bool) Ks_Concierge_Settings::get( 'consent_required', false ) && ! $consent ) {
			return new WP_REST_Response(
				array(
					'answer'       => __( '続けるには、質問をAIに送信することへの同意が必要です。', 'kashiwazaki-seo-concierge' ),
					'candidates'   => array(),
					'fallback'     => true,
					'need_consent' => true,
				),
				200
			);
		}

		$pii_mode = (string) Ks_Concierge_Settings::get( 'pii_mode', 'mask' );
		if ( 'block' === $pii_mode && Ks_Concierge_Security::contains_pii( $question ) ) {
			return new WP_REST_Response(
				array(
					'answer'     => __( 'プライバシー保護のため、個人情報は入力しないでください。質問は送信されませんでした。', 'kashiwazaki-seo-concierge' ),
					'candidates' => array(),
					'fallback'   => true,
				),
				200
			);
		}

		// Mask PII before any external send / storage.
		$safe_question = Ks_Concierge_Security::mask_pii( $question );

		$query    = new Ks_Concierge_Query();
		$response = $query->answer( $safe_question, Ks_Concierge_Security::session_hash(), $consent );

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Handle a candidate click event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_click( WP_REST_Request $request ) {
		if ( ! Ks_Concierge_Security::verify_origin() ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		if ( ! Ks_Concierge_Security::check_rate_limit() ) {
			return new WP_REST_Response( array( 'ok' => false ), 429 );
		}
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );
		if ( '' !== $url && in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			Ks_Concierge_Analytics::record_click( $url, Ks_Concierge_Security::session_hash() );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Return indexed pages (management endpoint).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_pages( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_pages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, url, title, status, lang, priority, updated_at FROM {$table} ORDER BY updated_at DESC LIMIT 500" );
		return new WP_REST_Response( array( 'pages' => $rows ), 200 );
	}
}
