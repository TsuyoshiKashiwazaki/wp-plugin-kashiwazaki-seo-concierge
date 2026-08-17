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
		wp_clear_scheduled_hook( Ks_Concierge_Cache::LINKCHECK_HOOK );
		wp_clear_scheduled_hook( Ks_Concierge_Cache::PRIORITY_HOOK );
		wp_clear_scheduled_hook( 'ks_concierge_prune_logs' );
		// Drop the in-flight run state and locks too: leaving them behind means a
		// re-activated plugin resumes a queue built under the old configuration.
		delete_option( Ks_Concierge_Cache::STATE_KEY );
		delete_option( Ks_Concierge_Cache::PRIORITY_STATE_KEY );
		delete_transient( Ks_Concierge_Cache::LOCK_KEY );
		delete_transient( Ks_Concierge_Cache::LINKCHECK_LOCK );
	}
}
