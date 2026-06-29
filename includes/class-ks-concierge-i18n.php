<?php
/**
 * Translation loading for Kashiwazaki SEO Concierge.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_I18n
 */
class Ks_Concierge_I18n {

	/**
	 * Register the textdomain loader.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load the plugin textdomain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'kashiwazaki-seo-concierge',
			false,
			dirname( KS_CONCIERGE_BASENAME ) . '/languages'
		);
	}
}
