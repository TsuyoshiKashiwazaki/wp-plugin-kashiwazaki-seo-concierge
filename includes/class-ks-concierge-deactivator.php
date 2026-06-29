<?php
/**
 * Deactivation: clear scheduled cron events.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_Deactivator
 */
class Ks_Concierge_Deactivator {

	/**
	 * Run deactivation tasks.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Ks_Concierge_Cache::CRON_HOOK );
		wp_clear_scheduled_hook( 'ks_concierge_prune_logs' );
	}
}
