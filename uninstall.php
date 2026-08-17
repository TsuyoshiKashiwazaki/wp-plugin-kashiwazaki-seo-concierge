<?php
/**
 * Uninstall handler for Kashiwazaki SEO Concierge.
 *
 * Removes plugin options and custom tables.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Remove every trace of the plugin from the current site.
 *
 * Options are deleted by prefix rather than one by one: the plugin also stores
 * per-signature matrix versions and run-state keys whose names are not fixed, and
 * an explicit list silently leaves those behind. Transients are removed by their
 * stored option names so their timeout twins go with them.
 *
 * @return void
 */
function ks_concierge_uninstall_site() {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$like = $wpdb->esc_like( 'ks_concierge_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

	foreach ( array( '_transient_ks_concierge_', '_transient_timeout_ks_concierge_' ) as $prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	}

	$ks_concierge_tables = array(
		$wpdb->prefix . 'ks_concierge_pages',
		$wpdb->prefix . 'ks_concierge_embeddings',
		$wpdb->prefix . 'ks_concierge_logs',
		$wpdb->prefix . 'ks_concierge_cache',
		$wpdb->prefix . 'ks_concierge_usage',
	);
	foreach ( $ks_concierge_tables as $ks_concierge_table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$ks_concierge_table}" );
	}
	// phpcs:enable

	wp_clear_scheduled_hook( 'ks_concierge_reindex' );
	wp_clear_scheduled_hook( 'ks_concierge_check_links' );
	wp_clear_scheduled_hook( 'ks_concierge_recalc_priorities' );
	wp_clear_scheduled_hook( 'ks_concierge_prune_logs' );
}

/**
 * Remove network-level leftovers.
 *
 * Site transients live in the network meta table, not in any site's options, so
 * the per-site sweep never reaches them.
 *
 * @return void
 */
function ks_concierge_uninstall_network() {
	global $wpdb;
	if ( empty( $wpdb->sitemeta ) ) {
		return;
	}
	foreach ( array( '_site_transient_ks_concierge_', '_site_transient_timeout_ks_concierge_' ) as $prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	}
}

// On a network, every site has its own tables and options: deleting only the
// current one leaves the other sites' question logs — including IP addresses and
// user agents — in the database after the plugin is gone.
if ( is_multisite() ) {
	$ks_concierge_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $ks_concierge_site_ids as $ks_concierge_site_id ) {
		switch_to_blog( (int) $ks_concierge_site_id );
		ks_concierge_uninstall_site();
		restore_current_blog();
	}
	ks_concierge_uninstall_network();
} else {
	ks_concierge_uninstall_site();
	// Single-site installs keep site transients in the options table, which the
	// per-site sweep above already covered.
	foreach ( array( '_site_transient_ks_concierge_', '_site_transient_timeout_ks_concierge_' ) as $ks_concierge_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $ks_concierge_prefix ) . '%' ) );
	}
}
