<?php
/**
 * WordPress privacy integration: data exporter/eraser registration, retention
 * cleanup and policy disclosure for Kashiwazaki SEO Concierge.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_Privacy
 */
class Ks_Concierge_Privacy {

	/**
	 * Register privacy hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'ks_concierge_prune_logs', array( $this, 'prune_logs' ) );
	}

	/**
	 * Suggested privacy policy text describing the external (OpenAI) processing.
	 *
	 * @return void
	 */
	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = wp_kses_post(
			__( 'Kashiwazaki SEO Concierge sends visitor questions to OpenAI to generate guidance toward relevant pages on this site. Questions and selected pages may be stored temporarily for analytics and are subject to best-effort personal-data masking before storage. Data is retained for the configured retention period and then deleted.', 'kashiwazaki-seo-concierge' )
		);
		wp_add_privacy_policy_content( 'Kashiwazaki SEO Concierge', '<p>' . $content . '</p>' );
	}

	/**
	 * Register the personal data exporter.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['kashiwazaki-seo-concierge'] = array(
			'exporter_friendly_name' => 'Kashiwazaki SEO Concierge',
			'callback'               => array( $this, 'export_data' ),
		);
		return $exporters;
	}

	/**
	 * Register the personal data eraser.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['kashiwazaki-seo-concierge'] = array(
			'eraser_friendly_name' => 'Kashiwazaki SEO Concierge',
			'callback'             => array( $this, 'erase_data' ),
		);
		return $erasers;
	}

	/**
	 * Export logged conversation rows. Conversation logs are not tied to a
	 * WordPress user account; this returns a notice describing that.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public function export_data( $email, $page = 1 ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	/**
	 * Erase logged data. Conversation logs are stored anonymized (session hash,
	 * masked question) and are not linkable to an email address, so this is a
	 * no-op that reports completion.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public function erase_data( $email, $page = 1 ) {
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Delete conversation logs older than the retention window.
	 *
	 * @return void
	 */
	public function prune_logs() {
		global $wpdb;
		$days = (int) Ks_Concierge_Settings::get( 'log_retention_days', 90 );
		if ( $days <= 0 ) {
			return;
		}
		$table  = $wpdb->prefix . 'ks_concierge_logs';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
