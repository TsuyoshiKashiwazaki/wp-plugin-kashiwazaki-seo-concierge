<?php
/**
 * Plugin Name: Kashiwazaki SEO Concierge
 * Plugin URI: https://www.tsuyoshikashiwazaki.jp
 * Description: sitemap.xml と llms.txt を解析し、訪問者の質問に応じてサイト内の最適なコンテンツをAIが案内するフローティング型チャットボットを設置します。
 * Version: 1.0.0
 * Author: 柏崎剛 (Tsuyoshi Kashiwazaki)
 * Author URI: https://www.tsuyoshikashiwazaki.jp/profile/
 * Text Domain: kashiwazaki-seo-concierge
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KS_CONCIERGE_VERSION', '1.0.0' );
define( 'KS_CONCIERGE_DB_VERSION', '3' );
define( 'KS_CONCIERGE_FILE', __FILE__ );
define( 'KS_CONCIERGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'KS_CONCIERGE_URL', plugin_dir_url( __FILE__ ) );
define( 'KS_CONCIERGE_BASENAME', plugin_basename( __FILE__ ) );
define( 'KS_CONCIERGE_PREFIX', 'ks_concierge_' );

/**
 * Require an includes class file.
 *
 * @param string $slug Class file slug (without the class-ks-concierge- prefix).
 * @return void
 */
function ks_concierge_require( $slug ) {
	require_once KS_CONCIERGE_DIR . 'includes/class-ks-concierge-' . $slug . '.php';
}

ks_concierge_require( 'settings' );
ks_concierge_require( 'security' );
ks_concierge_require( 'privacy' );
ks_concierge_require( 'openai' );
ks_concierge_require( 'parser' );
ks_concierge_require( 'embeddings' );
ks_concierge_require( 'cache' );
ks_concierge_require( 'analytics' );
ks_concierge_require( 'query' );
ks_concierge_require( 'rest' );
ks_concierge_require( 'frontend' );
ks_concierge_require( 'i18n' );
ks_concierge_require( 'admin' );
ks_concierge_require( 'activator' );
ks_concierge_require( 'deactivator' );
ks_concierge_require( 'loader' );

register_activation_hook( __FILE__, array( 'Ks_Concierge_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Ks_Concierge_Deactivator', 'deactivate' ) );

/**
 * Bootstrap the plugin once all plugins are loaded.
 *
 * @return Ks_Concierge_Loader
 */
function ks_concierge() {
	static $loader = null;
	if ( null === $loader ) {
		// Run schema migrations for normal updates (auto-update / zip overwrite)
		// where the activation hook does not fire, before any table is read.
		Ks_Concierge_Activator::maybe_migrate();
		$loader = new Ks_Concierge_Loader();
		$loader->run();
	}
	return $loader;
}
add_action( 'plugins_loaded', 'ks_concierge' );
