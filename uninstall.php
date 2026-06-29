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

delete_option( 'ks_concierge_settings' );
delete_option( 'ks_concierge_dbversion' );
delete_option( 'ks_concierge_index_model' );
delete_option( 'ks_concierge_index_dims' );
delete_option( 'ks_concierge_last_reindex' );

$ks_concierge_tables = array(
	$wpdb->prefix . 'ks_concierge_pages',
	$wpdb->prefix . 'ks_concierge_embeddings',
	$wpdb->prefix . 'ks_concierge_logs',
	$wpdb->prefix . 'ks_concierge_cache',
	$wpdb->prefix . 'ks_concierge_usage',
);

foreach ( $ks_concierge_tables as $ks_concierge_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$ks_concierge_table}" );
}
