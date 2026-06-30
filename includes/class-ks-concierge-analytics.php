<?php
/**
 * Conversation logging, query aggregation (popular and unanswered questions)
 * and CSV export for Kashiwazaki SEO Concierge.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_Analytics
 */
class Ks_Concierge_Analytics {

	/**
	 * Register admin-post handler for CSV export.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_ks_concierge_export_logs', array( $this, 'handle_export' ) );
	}

	/**
	 * Record a conversation log row (question already PII-masked).
	 *
	 * @param array{question:string,matched_urls:array,top_score:float,lang:string,answered:bool,session_hash:string} $row Log data.
	 * @return void
	 */
	public static function log( array $row ) {
		if ( (bool) Ks_Concierge_Settings::get( 'consent_required', false ) && empty( $row['consent'] ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_logs';
		// A logged-in user who can manage the site is the administrator testing or
		// playing with the bot, not a real visitor — flag those rows so analytics
		// can keep them in a separate bucket.
		$is_admin = ( is_user_logged_in() && current_user_can( 'manage_options' ) ) ? 1 : 0;
		// Record the real client IP + browser for spam identification, gated by the
		// log_ip setting. IP detection accounts for Cloudflare / trusted proxies.
		$log_ip = (bool) Ks_Concierge_Settings::get( 'log_ip', true );
		$ip     = $log_ip ? Ks_Concierge_Security::client_ip() : null;
		$ua     = $log_ip ? Ks_Concierge_Security::client_ua() : null;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'created_at'   => current_time( 'mysql', true ),
				'question'     => isset( $row['question'] ) ? $row['question'] : '',
				'answer'       => isset( $row['answer'] ) ? (string) $row['answer'] : '',
				'matched_urls' => wp_json_encode( isset( $row['matched_urls'] ) ? $row['matched_urls'] : array() ),
				'top_score'    => isset( $row['top_score'] ) ? (float) $row['top_score'] : 0,
				'lang'         => isset( $row['lang'] ) ? $row['lang'] : '',
				'answered'     => ! empty( $row['answered'] ) ? 1 : 0,
				'is_admin'     => $is_admin,
				'session_hash' => isset( $row['session_hash'] ) ? $row['session_hash'] : '',
				'ip'           => ( null !== $ip && '' !== $ip ) ? $ip : null,
				'user_agent'   => ( null !== $ua && '' !== $ua ) ? $ua : null,
			)
		);
	}

	/**
	 * SQL fragment that limits a query to one audience bucket.
	 *
	 * @param string $scope 'visitor' (real visitors), 'admin' (logged-in admin
	 *                       tests) or 'all'.
	 * @return string SQL condition (always starts with a space, never empty).
	 */
	protected static function scope_sql( $scope ) {
		if ( 'visitor' === $scope ) {
			return ' AND is_admin = 0';
		}
		if ( 'admin' === $scope ) {
			return ' AND is_admin = 1';
		}
		return '';
	}

	/**
	 * Recent conversation log rows for the history table (newest first).
	 *
	 * @param int    $limit  Rows per page.
	 * @param int    $offset Offset.
	 * @param string $scope  'visitor', 'admin' or 'all'.
	 * @return array<int,object>
	 */
	public function recent_logs( $limit = 20, $offset = 0, $scope = 'all' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_logs';
		$cond  = self::scope_sql( $scope );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT created_at, question, answer, matched_urls, clicked_url, top_score, answered, is_admin, lang, ip, user_agent FROM {$table} WHERE 1=1{$cond} ORDER BY id DESC LIMIT %d OFFSET %d", (int) $limit, max( 0, (int) $offset ) ) );
	}

	/**
	 * Count conversation log rows in a bucket (for pagination + summary).
	 *
	 * @param string $scope 'visitor', 'admin' or 'all'.
	 * @return int
	 */
	public function count_logs( $scope = 'all' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_logs';
		$cond  = self::scope_sql( $scope );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE 1=1{$cond}" );
	}

	/**
	 * Most frequently asked questions.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,object>
	 */
	public function popular_questions( $limit = 10 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT question, COUNT(*) AS cnt FROM {$table} WHERE question <> '' AND is_admin = 0 GROUP BY question ORDER BY cnt DESC LIMIT %d", (int) $limit ) );
	}

	/**
	 * Questions that produced no usable answer (content gaps).
	 *
	 * @param int $limit Max rows.
	 * @return array<int,object>
	 */
	public function content_gaps( $limit = 10 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT question, COUNT(*) AS cnt FROM {$table} WHERE answered = 0 AND question <> '' AND is_admin = 0 GROUP BY question ORDER BY cnt DESC LIMIT %d", (int) $limit ) );
	}

	/**
	 * Record a candidate click against the most recent matching log row.
	 *
	 * @param string $url          Clicked URL.
	 * @param string $session_hash Visitor session hash.
	 * @return void
	 */
	public static function record_click( $url, $session_hash ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE session_hash = %s ORDER BY id DESC LIMIT 1", $session_hash ) );
		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, array( 'clicked_url' => esc_url_raw( $url ) ), array( 'id' => (int) $id ) );
		}
	}

	/**
	 * Stream conversation logs as a CSV download.
	 *
	 * @return void
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'kashiwazaki-seo-concierge' ) );
		}
		check_admin_referer( 'ks_concierge_export_logs' );
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT created_at, is_admin, question, answer, top_score, clicked_url, lang, answered FROM {$table} ORDER BY id DESC", ARRAY_A );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ks-concierge-logs.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'created_at', 'is_admin', 'question', 'answer', 'top_score', 'clicked_url', 'lang', 'answered' ) );
		if ( $rows ) {
			foreach ( $rows as $row ) {
				fputcsv( $out, array_map( array( $this, 'csv_safe' ), $row ) );
			}
		}
		fclose( $out );
		exit;
	}

	/**
	 * Neutralize CSV formula injection by prefixing risky cells with a quote.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	protected function csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
