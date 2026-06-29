<?php
/**
 * Activation: create custom tables, seed default options and schedule cron.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_Activator
 */
class Ks_Concierge_Activator {

	/**
	 * Run activation tasks.
	 *
	 * @return void
	 */
	public static function activate() {
		self::maybe_migrate();

		if ( false === get_option( Ks_Concierge_Settings::OPTION_KEY, false ) ) {
			Ks_Concierge_Settings::update( Ks_Concierge_Settings::defaults() );
		}

		$interval = (string) Ks_Concierge_Settings::get( 'reindex_interval', 'daily' );
		if ( ! wp_next_scheduled( Ks_Concierge_Cache::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $interval ? $interval : 'daily', Ks_Concierge_Cache::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( 'ks_concierge_prune_logs' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'ks_concierge_prune_logs' );
		}
	}

	/**
	 * Run the schema migration when the stored DB version is behind.
	 *
	 * Hooked on plugins_loaded so it runs for every entry point (admin, REST and
	 * front-end) before new code reads the tables — normal plugin updates (auto
	 * update / zip overwrite) do not fire the activation hook. Idempotent and
	 * serialized with a short lock so concurrent requests do not race.
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( self::db_version() === KS_CONCIERGE_DB_VERSION ) {
			return;
		}
		// Another request is migrating: wait (bounded) until the schema is current
		// so this request does not read the new schema before the columns exist.
		if ( get_transient( 'ks_concierge_migrating' ) ) {
			for ( $i = 0; $i < 50; $i++ ) {
				usleep( 100000 ); // 100ms x 50 = up to 5s.
				if ( self::db_version() === KS_CONCIERGE_DB_VERSION ) {
					return;
				}
			}
			// Timed out waiting: fall through and run the migration ourselves
			// (dbDelta and backfill are idempotent) rather than proceed on a
			// possibly-stale schema.
		}
		set_transient( 'ks_concierge_migrating', 1, MINUTE_IN_SECONDS );
		try {
			self::run_ddl();
			self::backfill_v2();
			update_option( 'ks_concierge_dbversion', KS_CONCIERGE_DB_VERSION, false );
		} finally {
			delete_transient( 'ks_concierge_migrating' );
		}
	}

	/**
	 * Read the stored DB version directly (bypassing the options cache) so the
	 * migration poll sees another request's update promptly.
	 *
	 * @return string
	 */
	protected static function db_version() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$val = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'ks_concierge_dbversion' ) );
		return null === $val ? '' : (string) $val;
	}

	/**
	 * Backward-compatible alias for the table creator (used by older callers).
	 *
	 * @return void
	 */
	public static function create_tables() {
		self::maybe_migrate();
	}

	/**
	 * Backfill v2 columns after the DDL has added them.
	 *
	 * - embed_sig: computed per row from the row's own model/dims plus the
	 *   current embed provider/base, so rows matching the current configuration
	 *   stay searchable and rows from a different model/dims are excluded (same
	 *   visible set as the legacy model+dims filter). No empty window on upgrade.
	 * - content_hash: copied from the matching pages row so the first reindex
	 *   after upgrade does not re-embed unchanged pages.
	 *
	 * @return void
	 */
	protected static function backfill_v2() {
		global $wpdb;
		$emb   = $wpdb->prefix . 'ks_concierge_embeddings';
		$pages = $wpdb->prefix . 'ks_concierge_pages';

		$provider = Ks_Concierge_Settings::get_provider( 'embed' );
		$base     = Ks_Concierge_Settings::get_api_base( 'embed' );

		// embed_sig = substr(sha1(provider|base|model|dims),0,16), computed in SQL
		// with the same formula as Ks_Concierge_Embeddings::current_embed_sig().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$emb} SET embed_sig = SUBSTRING( SHA1( CONCAT( %s, '|', %s, '|', model, '|', dims ) ), 1, 16 ) WHERE embed_sig IS NULL OR embed_sig = ''",
				$provider,
				$base
			)
		);

		// content_hash <- pages.content_hash for rows that have none yet.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$emb} e JOIN {$pages} p ON e.page_id = p.id SET e.content_hash = p.content_hash WHERE ( e.content_hash IS NULL OR e.content_hash = '' ) AND p.content_hash IS NOT NULL"
		);
	}

	/**
	 * Create or update the custom tables via dbDelta. Adds new columns on
	 * existing installs (dbDelta performs ALTER ... ADD COLUMN).
	 *
	 * @return void
	 */
	protected static function run_ddl() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$prefix          = $wpdb->prefix . 'ks_concierge_';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = array();

		$sql[] = "CREATE TABLE {$prefix}pages (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url varchar(2048) NOT NULL,
  url_hash char(64) NOT NULL,
  title text NULL,
  summary longtext NULL,
  content_hash char(64) NULL,
  lastmod datetime NULL,
  source varchar(16) NOT NULL DEFAULT 'sitemap',
  status varchar(16) NOT NULL DEFAULT 'active',
  priority int(11) NOT NULL DEFAULT 0,
  lang varchar(10) NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY status (status),
  KEY lang (lang),
  KEY updated_at (updated_at)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}embeddings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  page_id bigint(20) unsigned NOT NULL,
  vector mediumblob NOT NULL,
  model varchar(64) NOT NULL,
  dims smallint(5) unsigned NOT NULL,
  embed_sig varchar(16) NULL,
  content_hash char(64) NULL,
  created_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY page_id (page_id),
  KEY model_dims (model, dims),
  KEY embed_sig (embed_sig)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}logs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  question text NULL,
  answer text NULL,
  matched_urls longtext NULL,
  top_score float NULL,
  clicked_url varchar(2048) NULL,
  lang varchar(10) NULL,
  answered tinyint(1) NOT NULL DEFAULT 0,
  is_admin tinyint(1) NOT NULL DEFAULT 0,
  session_hash char(64) NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY is_admin (is_admin)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}cache (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  q_norm_hash char(64) NOT NULL,
  question_norm text NULL,
  answer_json longtext NULL,
  created_at datetime NULL,
  expires_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY q_norm_hash (q_norm_hash),
  KEY expires_at (expires_at)
) {$charset_collate};";

		$sql[] = "CREATE TABLE {$prefix}usage (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  day date NOT NULL,
  embed_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  chat_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  requests bigint(20) unsigned NOT NULL DEFAULT 0,
  est_cost_usd decimal(10,4) NOT NULL DEFAULT 0.0000,
  cost_embed_nano bigint(20) NOT NULL DEFAULT 0,
  cost_chat_nano bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY day (day)
) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
