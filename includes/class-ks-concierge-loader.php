<?php
/**
 * Central wiring for Kashiwazaki SEO Concierge: instantiates components and
 * registers their hooks.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_Loader
 */
class Ks_Concierge_Loader {

	/**
	 * Boot all components.
	 *
	 * @return void
	 */
	public function run() {
		( new Ks_Concierge_I18n() )->register();
		( new Ks_Concierge_Cache() )->register();
		( new Ks_Concierge_Privacy() )->register();
		( new Ks_Concierge_Analytics() )->register();
		( new Ks_Concierge_REST() )->register();
		( new Ks_Concierge_Frontend() )->register();

		if ( is_admin() ) {
			( new Ks_Concierge_Admin() )->register();
		}
	}
}
