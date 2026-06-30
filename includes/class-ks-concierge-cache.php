<?php
/**
 * Index storage, incremental diff detection and the WP-Cron reindex job for
 * Kashiwazaki SEO Concierge.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_Cache
 */
class Ks_Concierge_Cache {

	const CRON_HOOK         = 'ks_concierge_reindex';
	const LOCK_KEY          = 'ks_concierge_reindex_lock';
	const BATCH_LIMIT       = 25;
	const STATE_KEY         = 'ks_concierge_reindex_state';
	const MAX_DRAIN_BATCHES = 200;

	// Link-reachability check: how many URLs to probe per pass, the dedicated
	// drain cron hook / lock for the manual "check links now" sweep, and the
	// number of consecutive transient failures required before a reachable page
	// is demoted to 'unreachable' (one transient 5xx/timeout must not evict a
	// live page).
	const REACH_BATCH       = 15;
	const LINKCHECK_HOOK    = 'ks_concierge_check_links';
	const LINKCHECK_LOCK    = 'ks_concierge_check_links_lock';
	const LINKCHECK_RUN_KEY = 'ks_concierge_linkcheck_run';
	const REACH_FAIL_LIMIT  = 2;

	/**
	 * Whether every configured source returned results in the last collection.
	 *
	 * @var bool
	 */
	protected $sources_ok = false;

	/**
	 * Register cron and admin-post hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'run_reindex' ) );
		add_action( self::LINKCHECK_HOOK, array( $this, 'run_link_check' ) );
		add_action( 'admin_post_ks_concierge_reindex_now', array( $this, 'handle_manual_reindex' ) );
		add_action( 'admin_post_ks_concierge_check_links_now', array( $this, 'handle_check_links_now' ) );
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
	}

	/**
	 * Add custom cron schedules.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'kashiwazaki-seo-concierge' ),
			);
		}
		return $schedules;
	}

	/**
	 * Handle the "reindex now" admin-post action.
	 *
	 * @return void
	 */
	public function handle_manual_reindex() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'kashiwazaki-seo-concierge' ) );
		}
		check_admin_referer( 'ks_concierge_reindex_now' );
		$this->run_reindex();
		wp_safe_redirect( add_query_arg( array( 'page' => 'kashiwazaki-seo-concierge', 'tab' => 'index', 'reindexed' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle the "check links now" admin-post action: queue a full reachability
	 * sweep. Every active/unreachable page is flagged pending (http_checked_at
	 * NULL), one batch is probed inline for immediate feedback, and the rest are
	 * drained by chained cron so the request never blocks on hundreds of HTTP
	 * round-trips (avoids PHP max_execution_time).
	 *
	 * @return void
	 */
	public function handle_check_links_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'kashiwazaki-seo-concierge' ) );
		}
		check_admin_referer( 'ks_concierge_check_links_now' );
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_pages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET http_checked_at = NULL WHERE status IN ( 'active', 'unreachable' )" );
		update_option( self::LINKCHECK_RUN_KEY, time(), false );
		$this->run_link_check();
		wp_safe_redirect( add_query_arg( array( 'page' => 'kashiwazaki-seo-concierge', 'tab' => 'index', 'linkcheck' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Drain one batch of the queued reachability sweep and chain the next batch
	 * via cron until no pending (http_checked_at NULL) pages remain. Lock-guarded
	 * so cron and the inline kick do not collide.
	 *
	 * @return void
	 */
	public function run_link_check() {
		if ( ! Ks_Concierge_Settings::get( 'reachability_check', true ) ) {
			delete_option( self::LINKCHECK_RUN_KEY );
			return;
		}
		if ( get_transient( self::LINKCHECK_LOCK ) ) {
			return;
		}
		set_transient( self::LINKCHECK_LOCK, time(), 10 * MINUTE_IN_SECONDS );
		try {
			$this->reachability_pass( self::REACH_BATCH, 'http_checked_at IS NULL' );
			global $wpdb;
			$table = $wpdb->prefix . 'ks_concierge_pages';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ( 'active', 'unreachable' ) AND http_checked_at IS NULL" );
			if ( $remaining > 0 ) {
				wp_schedule_single_event( time(), self::LINKCHECK_HOOK );
			} else {
				delete_option( self::LINKCHECK_RUN_KEY );
			}
		} finally {
			delete_transient( self::LINKCHECK_LOCK );
		}
	}

	/**
	 * Run one reindex batch. A reindex is a drain session: discovery (parse the
	 * sources into the pages table) runs once at session start, then the
	 * embedding phase processes one batch of pages that need (re)embedding and
	 * chains the next batch until the backlog is drained. Lock-guarded so cron
	 * and manual triggers do not collide, with try/finally lock release.
	 *
	 * @return array{processed:int,broken:int,skipped:int}
	 */
	public function run_reindex() {
		$stats = array(
			'processed' => 0,
			'broken'    => 0,
			'skipped'   => 0,
		);
		if ( get_transient( self::LOCK_KEY ) ) {
			return $stats;
		}
		set_transient( self::LOCK_KEY, time(), 10 * MINUTE_IN_SECONDS );

		try {
			// The embedding phase depends on the embed-role breaker; when it is
			// open we pause without wiping (the current-sig rows keep serving).
			if ( Ks_Concierge_OpenAI::is_breaker_open( 'embed' ) ) {
				return $stats;
			}

			$state = get_option( self::STATE_KEY, array() );
			if ( ! is_array( $state ) || empty( $state['active'] ) ) {
				// Start a new drain session: discovery runs exactly once here.
				$this->discovery();
				$state = array(
					'active'     => true,
					'cursor_id'  => 0,
					'depth'      => 0,
					'started_at' => time(),
				);
				update_option( self::STATE_KEY, $state, false );
			}

			$model        = (string) Ks_Concierge_Settings::get( 'embeddings_model', 'text-embedding-3-small' );
			$dims         = (int) Ks_Concierge_Settings::get( 'embeddings_dims', 1536 );
			$cursor       = (int) $state['cursor_id'];
			$candidates   = $this->select_candidates( $cursor, self::BATCH_LIMIT );
			$rows_selected = count( $candidates );

			$parser = new Ks_Concierge_Parser();
			foreach ( $candidates as $page ) {
				$cursor = max( $cursor, (int) $page->id );

				// Per-page breaker check: stop the batch cleanly if the budget is
				// exhausted mid-run (resume on the next scheduled run).
				if ( Ks_Concierge_OpenAI::is_breaker_open( 'embed' ) ) {
					break;
				}

				$url     = (string) $page->url;
				$title   = (string) $page->title;
				$summary = (string) $page->summary;

				if ( '' === $title || '' === $summary ) {
					$meta = $parser->fetch_page_meta( $url );
					if ( null === $meta ) {
						$this->upsert_broken( $url );
						$stats['broken']++;
						continue;
					}
					$title   = '' === $title ? $meta['title'] : $title;
					$summary = '' === $summary ? $meta['summary'] : $summary;
				}

				$content_hash = hash( 'sha256', $title . '|' . $summary );
				$page_id      = $this->upsert_page( $url, $title, $summary, $content_hash, $page->lastmod );
				if ( ! $page_id ) {
					continue;
				}

				$embed_input = trim( $title . "\n" . $summary );
				$result      = Ks_Concierge_OpenAI::embed( array( $embed_input ) );
				if ( is_wp_error( $result ) || empty( $result['vectors'][0] ) ) {
					continue;
				}
				Ks_Concierge_Embeddings::store( $page_id, $result['vectors'][0], $model, $dims, $content_hash );
				$stats['processed']++;
			}

			// Advance the cursor past every selected row (success or not) so a
			// failing page does not stall the drain; it retries on the next pass.
			$state['cursor_id'] = $cursor;
			$state['depth']     = (int) $state['depth'] + 1;

			Ks_Concierge_Embeddings::flush_matrix_cache();
			update_option( 'ks_concierge_last_reindex', time(), false );

			if ( 0 === $rows_selected || $state['depth'] >= self::MAX_DRAIN_BATCHES ) {
				// Backlog drained (or depth cap reached: hand back to regular cron).
				// Run the periodic link-reachability sweep only here — when the
				// embedding drain is idle — so a long initial drain (which chains
				// many back-to-back batches) does not fire a storm of HTTP probes;
				// in steady state each cron tick probes one small least-recently-
				// checked batch, catching pages that 404 after indexing.
				if ( Ks_Concierge_Settings::get( 'reachability_check', true ) ) {
					$this->reachability_pass( self::REACH_BATCH );
					Ks_Concierge_Embeddings::flush_matrix_cache();
				}
				$state['active'] = false;
				update_option( self::STATE_KEY, $state, false );
			} else {
				update_option( self::STATE_KEY, $state, false );
				wp_schedule_single_event( time(), self::CRON_HOOK );
			}
		} finally {
			delete_transient( self::LOCK_KEY );
		}

		return $stats;
	}

	/**
	 * Discovery phase: parse the sources into the pages table (run once per drain
	 * session). Cheap relative to embedding — no per-page metadata HTTP here.
	 *
	 * @return void
	 */
	protected function discovery() {
		global $wpdb;
		$parser  = new Ks_Concierge_Parser();
		$entries = $this->collect_entries( $parser );

		if ( ! empty( $entries ) && $this->sources_ok ) {
			$this->reconcile_removed( wp_list_pluck( $entries, 'url' ) );
		}

		$emb = $wpdb->prefix . 'ks_concierge_embeddings';
		foreach ( $entries as $entry ) {
			$url = $entry['url'];
			if ( $this->is_excluded( $url ) ) {
				$this->set_status_by_url( $url, 'excluded' );
				continue;
			}
			$title   = isset( $entry['title'] ) ? (string) $entry['title'] : '';
			$summary = isset( $entry['summary'] ) ? (string) $entry['summary'] : '';
			$lastmod = isset( $entry['lastmod'] ) ? $entry['lastmod'] : null;
			$existing = $this->get_page_by_url( $url );

			if ( '' !== $title && '' !== $summary ) {
				// llms.txt provides the content directly: store it with its hash.
				$this->upsert_page( $url, $title, $summary, hash( 'sha256', $title . '|' . $summary ), $lastmod );
				continue;
			}

			// Sitemap-only URL: upsert position/lastmod, keep existing meta/hash.
			if ( $existing ) {
				$changed = ( null !== $lastmod && ! empty( $existing->lastmod ) && $lastmod > $existing->lastmod );
				// Preserve a reachability demotion (see upsert_page): a page marked
				// 'unreachable' stays so until a successful probe restores it.
				$new_status = ( 'unreachable' === (string) $existing->status ) ? 'unreachable' : 'active';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update(
					$wpdb->prefix . 'ks_concierge_pages',
					array(
						'lastmod'    => $lastmod,
						'status'     => $new_status,
						'priority'   => $this->priority_for( $url ),
						'updated_at' => current_time( 'mysql', true ),
					),
					array( 'id' => $existing->id )
				);
				if ( $changed ) {
					// Flag for re-embed without dropping the existing vector from
					// search: clear the embedding content hash so the candidate
					// query picks it up.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( $wpdb->prepare( "UPDATE {$emb} SET content_hash = NULL WHERE page_id = %d", (int) $existing->id ) );
				}
				continue;
			}
			// New sitemap-only page: metadata fetched later in the embedding phase.
			$this->upsert_page( $url, '', '', '', $lastmod );
		}
	}

	/**
	 * Select the next batch of active pages needing (re)embedding for the current
	 * embedding signature, using an id cursor (no source re-fetch per batch).
	 *
	 * @param int $cursor Last processed page id.
	 * @param int $limit  Batch size.
	 * @return object[]
	 */
	protected function select_candidates( $cursor, $limit ) {
		global $wpdb;
		$pages = $wpdb->prefix . 'ks_concierge_pages';
		$emb   = $wpdb->prefix . 'ks_concierge_embeddings';
		$sig   = Ks_Concierge_Embeddings::current_embed_sig();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.* FROM {$pages} p
				 LEFT JOIN {$emb} e ON e.page_id = p.id AND e.embed_sig = %s
				 WHERE p.status = 'active' AND p.id > %d
				 AND ( e.id IS NULL OR e.content_hash IS NULL OR e.content_hash <> p.content_hash )
				 ORDER BY p.id ASC
				 LIMIT %d",
				$sig,
				(int) $cursor,
				(int) $limit
			)
		);
	}

	/**
	 * Mark active pages that are absent from the current source URL set as broken
	 * and remove their embeddings.
	 *
	 * @param string[] $current_urls URLs present in the current source.
	 * @return void
	 */
	protected function reconcile_removed( array $current_urls ) {
		global $wpdb;
		$hashes = array();
		foreach ( $current_urls as $url ) {
			$hashes[] = hash( 'sha256', $url );
		}
		$hashes = array_values( array_unique( array_filter( $hashes ) ) );
		if ( empty( $hashes ) ) {
			return;
		}
		$pages        = $wpdb->prefix . 'ks_concierge_pages';
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$pages} WHERE status = 'active' AND url_hash NOT IN ({$placeholders})", $hashes ) );
		$ids = array_map( 'intval', (array) $ids );
		if ( empty( $ids ) ) {
			return;
		}
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$ph = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// Capture URLs before the status change so cached answers that point at
			// these now-removed pages can be purged (same cache-integrity guarantee
			// as set_status_by_url; this bulk path bypasses that helper).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$urls = $wpdb->get_col( $wpdb->prepare( "SELECT url FROM {$pages} WHERE id IN ({$ph})", $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$pages} SET status = 'broken' WHERE id IN ({$ph})", $chunk ) );
			Ks_Concierge_Embeddings::delete_for_pages( $chunk );
			foreach ( (array) $urls as $removed_url ) {
				$this->purge_answer_cache_for_url( (string) $removed_url );
			}
		}
	}

	/**
	 * Gather candidate entries from llms.txt (preferred) and/or sitemap.xml.
	 *
	 * @param Ks_Concierge_Parser $parser Parser instance.
	 * @return array<int,array<string,mixed>>
	 */
	protected function collect_entries( $parser ) {
		$entries     = array();
		$llms_url    = (string) Ks_Concierge_Settings::get( 'llms_txt_url', '' );
		$llms_on     = (bool) Ks_Concierge_Settings::get( 'llms_txt_enabled', true );
		$sitemap_url = (string) Ks_Concierge_Settings::get( 'sitemap_url', '' );
		$sources_ok  = true;

		if ( $llms_on && '' !== $llms_url ) {
			$llms = $parser->parse_llms_txt( $llms_url );
			if ( empty( $llms ) ) {
				$sources_ok = false;
			}
			foreach ( $llms as $e ) {
				$entries[ $e['url'] ] = array(
					'url'     => $e['url'],
					'title'   => $e['title'],
					'summary' => $e['summary'],
					'lastmod' => null,
				);
			}
		}

		if ( '' !== $sitemap_url ) {
			$sitemap = $parser->parse_sitemap( $sitemap_url );
			if ( empty( $sitemap ) || $parser->sitemap_incomplete ) {
				$sources_ok = false;
			}
			foreach ( $sitemap as $e ) {
				if ( isset( $entries[ $e['url'] ] ) ) {
					if ( null !== $e['lastmod'] ) {
						$entries[ $e['url'] ]['lastmod'] = $e['lastmod'];
					}
					continue;
				}
				$entries[ $e['url'] ] = array(
					'url'     => $e['url'],
					'title'   => '',
					'summary' => '',
					'lastmod' => $e['lastmod'],
				);
			}
		}

		$this->sources_ok = $sources_ok;
		return array_values( $entries );
	}

	/**
	 * Check a URL against the exclude rules (one pattern per line, substring).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	protected function is_excluded( $url ) {
		$raw = (string) Ks_Concierge_Settings::get( 'exclude_rules', '' );
		if ( '' === trim( $raw ) ) {
			return false;
		}
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && false !== strpos( $url, $line ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Re-apply the current exclude rules to already-indexed pages and refresh the
	 * search index. Toggles pages between 'active' and 'excluded' based on the
	 * rules (never touching 'broken' pages), then flushes the matrix cache so the
	 * change is reflected immediately without a full reindex. Vectors are kept, so
	 * removing a rule re-includes its pages without re-embedding.
	 *
	 * @return int Number of pages whose status changed.
	 */
	public function apply_exclude_rules() {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_pages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows    = $wpdb->get_results( "SELECT url, status FROM {$table} WHERE status IN ( 'active', 'excluded' )" );
		$changed = 0;
		foreach ( (array) $rows as $row ) {
			$should = $this->is_excluded( $row->url );
			if ( $should && 'excluded' !== $row->status ) {
				$this->set_status_by_url( $row->url, 'excluded' );
				$changed++;
			} elseif ( ! $should && 'excluded' === $row->status ) {
				$this->set_status_by_url( $row->url, 'active' );
				$changed++;
			}
		}
		if ( $changed > 0 ) {
			Ks_Concierge_Embeddings::flush_matrix_cache();
		}
		return $changed;
	}

	/**
	 * Compute the priority bump for a URL from the priority rules.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	protected function priority_for( $url ) {
		$raw = (string) Ks_Concierge_Settings::get( 'priority_rules', '' );
		if ( '' === trim( $raw ) ) {
			return 0;
		}
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && false !== strpos( $url, $line ) ) {
				return 10;
			}
		}
		return 0;
	}

	/**
	 * Get a page row by URL.
	 *
	 * @param string $url URL.
	 * @return object|null
	 */
	public function get_page_by_url( $url ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_pages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE url_hash = %s", hash( 'sha256', $url ) ) );
	}

	/**
	 * Insert or update a page row, marking it active.
	 *
	 * @param string      $url          URL.
	 * @param string      $title        Title.
	 * @param string      $summary      Summary.
	 * @param string      $content_hash Content hash.
	 * @param string|null $lastmod      Lastmod datetime.
	 * @return int Page id (0 on failure).
	 */
	protected function upsert_page( $url, $title, $summary, $content_hash, $lastmod ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'ks_concierge_pages';
		$url_hash = hash( 'sha256', $url );
		$source   = Ks_Concierge_Settings::get( 'llms_txt_enabled', true ) && '' !== (string) Ks_Concierge_Settings::get( 'llms_txt_url', '' ) ? 'llms_txt' : 'sitemap';
		$data     = array(
			'url'          => $url,
			'url_hash'     => $url_hash,
			'title'        => $title,
			'summary'      => $summary,
			'content_hash' => $content_hash,
			'lastmod'      => $lastmod,
			'source'       => $source,
			'status'       => 'active',
			'priority'     => $this->priority_for( $url ),
			'lang'         => $this->detect_lang( $url ),
			'updated_at'   => current_time( 'mysql', true ),
		);
		$existing = $this->get_page_by_url( $url );
		if ( $existing ) {
			// Preserve a link-reachability demotion: discovery / re-embed must not
			// blindly re-activate a page the link check marked 'unreachable'. Only a
			// successful reachability probe (reachability_pass) restores it to active.
			if ( 'unreachable' === (string) $existing->status ) {
				$data['status'] = 'unreachable';
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $data, array( 'id' => $existing->id ) );
			return (int) $existing->id;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Mark a URL as broken (excluded from guidance).
	 *
	 * @param string $url URL.
	 * @return void
	 */
	protected function upsert_broken( $url ) {
		$existing = $this->get_page_by_url( $url );
		if ( $existing ) {
			$this->set_status_by_url( $url, 'broken' );
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_pages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'url'        => $url,
				'url_hash'   => hash( 'sha256', $url ),
				'status'     => 'broken',
				'source'     => 'sitemap',
				'updated_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Set a page status by URL and clean up embeddings when no longer needed.
	 *
	 * Embeddings are deleted for permanently-gone statuses (e.g. 'broken'), but
	 * KEPT for the reversible non-search statuses 'excluded' and 'unreachable':
	 * search already filters to status='active', so such a page simply drops out
	 * of results with its vector intact, and restoring it (status back to
	 * 'active') makes it searchable again immediately with no paid re-embed.
	 *
	 * Any move away from 'active' also purges cached answers that reference the
	 * URL, so a stale cached answer cannot keep surfacing a now-removed page.
	 *
	 * @param string $url    URL.
	 * @param string $status New status.
	 * @return void
	 */
	protected function set_status_by_url( $url, $status ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_pages';
		$page  = $this->get_page_by_url( $url );
		if ( ! $page ) {
			return;
		}
		$prev = (string) $page->status;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, array( 'status' => $status ), array( 'id' => $page->id ) );
		if ( ! in_array( $status, array( 'active', 'excluded', 'unreachable' ), true ) ) {
			Ks_Concierge_Embeddings::delete_for_pages( array( (int) $page->id ) );
		}
		// Purge cached answers referencing this URL when it leaves the active set
		// (only on an actual transition, to avoid redundant work).
		if ( 'active' !== $status && $prev !== $status ) {
			$this->purge_answer_cache_for_url( $url );
		}
	}

	/**
	 * Delete cached answers (wp_ks_concierge_cache) whose stored answer JSON
	 * references the given URL. Matches the JSON-escaped form of the URL (forward
	 * slashes are stored as "\/") so the LIKE hits the real bytes in the column.
	 *
	 * @param string $url URL.
	 * @return void
	 */
	protected function purge_answer_cache_for_url( $url ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_cache';
		// Match the URL exactly as it appears inside answer_json: wp_json_encode
		// yields the quoted, slash-escaped JSON string value ("https:\/\/..."),
		// which is the precise byte sequence stored. Keeping the surrounding
		// quotes makes the LIKE an exact value match, so a URL that is a prefix of
		// a longer URL does not over-match and purge unrelated cache rows.
		$needle = (string) wp_json_encode( (string) $url );
		if ( '' === $needle || '""' === $needle ) {
			return;
		}
		$like = '%' . $wpdb->esc_like( $needle ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE answer_json LIKE %s", $like ) );
	}

	/**
	 * Probe a URL's reachability. HEAD first (cheap), falling back to a ranged GET
	 * when HEAD is unsupported (405/501) or yields no code. Redirects are followed
	 * so the final landing status is judged.
	 *
	 * @param string $url URL.
	 * @return int HTTP status code, or 0 on a network error / timeout.
	 */
	protected function check_url_reachable( $url ) {
		$args = array(
			'timeout'     => 5,
			'redirection' => 5,
			'sslverify'   => true,
			'user-agent'  => 'KashiwazakiSEOConcierge/' . ( defined( 'KS_CONCIERGE_VERSION' ) ? KS_CONCIERGE_VERSION : '1.0' ) . '; ' . home_url( '/' ),
		);

		$resp = wp_remote_head( $url, $args );
		$code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );

		// HEAD unsupported or inconclusive: retry once with a tiny ranged GET.
		if ( 0 === $code || 405 === $code || 501 === $code ) {
			$args['headers'] = array( 'Range' => 'bytes=0-0' );
			$resp            = wp_remote_get( $url, $args );
			$code            = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
		}

		return $code;
	}

	/**
	 * Probe a batch of the least-recently-checked active/unreachable pages and
	 * apply the reachability policy:
	 *
	 * - 2xx/3xx (final landing) -> reachable: reset the failure counter and
	 *   restore 'active' (no re-embed needed, the vector was kept).
	 * - 404/410 -> gone for good: mark 'unreachable' immediately.
	 * - 5xx / 429 / 403 / timeout / connection error -> transient: increment the
	 *   consecutive-failure counter; only demote to 'unreachable' once it reaches
	 *   REACH_FAIL_LIMIT, so a single blip does not evict a live page.
	 *
	 * @param int   $limit     Max pages to probe.
	 * @param array $where_sql Optional extra WHERE fragment (already-safe SQL).
	 * @return int Number of pages probed.
	 */
	protected function reachability_pass( $limit, $where_sql = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_pages';
		$extra = '' !== $where_sql ? ' AND ' . $where_sql : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url, status, consecutive_failures FROM {$table}
				 WHERE status IN ( 'active', 'unreachable' ){$extra}
				 ORDER BY ( http_checked_at IS NOT NULL ), http_checked_at ASC, id ASC
				 LIMIT %d",
				(int) $limit
			)
		);
		if ( empty( $rows ) ) {
			return 0;
		}

		$now     = current_time( 'mysql', true );
		$changed = false;
		foreach ( $rows as $row ) {
			$code      = $this->check_url_reachable( (string) $row->url );
			$reachable = ( $code >= 200 && $code < 400 );
			$gone      = ( 404 === $code || 410 === $code );
			$fails     = (int) $row->consecutive_failures;

			if ( $reachable ) {
				$new_status = 'active';
				$fails      = 0;
			} elseif ( $gone ) {
				$new_status = 'unreachable';
				$fails++;
			} else {
				// Transient failure: count it, demote only at the threshold.
				$fails++;
				$new_status = ( $fails >= self::REACH_FAIL_LIMIT ) ? 'unreachable' : (string) $row->status;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				array(
					'http_status'          => ( $code > 0 ) ? $code : null,
					'http_checked_at'      => $now,
					'consecutive_failures' => $fails,
				),
				array( 'id' => (int) $row->id )
			);

			if ( $new_status !== (string) $row->status ) {
				$this->set_status_by_url( (string) $row->url, $new_status );
				$changed = true;
			}
		}

		if ( $changed ) {
			Ks_Concierge_Embeddings::flush_matrix_cache();
		}
		return count( $rows );
	}

	/**
	 * Best-effort language detection from a URL path segment.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected function detect_lang( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#/([a-z]{2})(/|$)#', $path, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Fetch active pages by id for result hydration.
	 *
	 * @param int[] $ids Page ids.
	 * @return array<int,object> Keyed by id.
	 */
	public function get_pages_by_ids( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$table        = $wpdb->prefix . 'ks_concierge_pages';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->id ] = $row;
		}
		return $out;
	}

	/**
	 * Get up to N popular/active pages as a fallback suggestion source.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,object>
	 */
	public function get_fallback_pages( $limit = 3 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_pages';
		// Only surface admin-curated pages (priority > 0) as "you might also like"
		// suggestions on a no-match fallback. Without this filter the query falls
		// back to updated_at order and shows whichever pages were reindexed most
		// recently — typically irrelevant to an off-topic question. When no
		// priority pages are configured this returns an empty set, so the fallback
		// message is shown alone (no misleading suggestions).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'active' AND priority > 0 ORDER BY priority DESC, updated_at DESC LIMIT %d", (int) $limit ) );
	}

	/**
	 * Restore every page demoted to 'unreachable' back to 'active'. Called when the
	 * reachability check is turned off: without the periodic probe nothing would
	 * ever recover those pages, so they must re-enter search immediately. Vectors
	 * were kept, so this needs no re-embed.
	 *
	 * @return int Rows restored.
	 */
	public function restore_unreachable() {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_pages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$restored = (int) $wpdb->query( "UPDATE {$table} SET status = 'active', consecutive_failures = 0 WHERE status = 'unreachable'" );
		if ( $restored > 0 ) {
			Ks_Concierge_Embeddings::flush_matrix_cache();
		}
		return $restored;
	}

	/**
	 * Count indexed pages by status for the admin index breakdown.
	 *
	 * @return array{active:int,excluded:int,unreachable:int,broken:int,total:int}
	 */
	public function status_counts() {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_pages';
		$out   = array( 'active' => 0, 'excluded' => 0, 'unreachable' => 0, 'broken' => 0, 'total' => 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$count           = (int) $row['c'];
			$out['total']   += $count;
			$status          = (string) $row['status'];
			if ( isset( $out[ $status ] ) ) {
				$out[ $status ] = $count;
			}
		}
		return $out;
	}

	/**
	 * List pages that are not in search because the link is unavailable: both
	 * 'unreachable' (404/error reachability failures, with an HTTP status) and
	 * 'broken' (removed from the source sitemap/llms.txt, http_status NULL). The
	 * row's status is returned so the admin table can distinguish the two.
	 *
	 * @param int $limit Max rows (newest-checked first).
	 * @return object[]
	 */
	public function get_unavailable_pages( $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_pages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Order by recency: broken pages have no http_checked_at, so fall back to
		// updated_at (when they were marked broken) instead of sinking them all to
		// the bottom past the row limit.
		return $wpdb->get_results( $wpdb->prepare( "SELECT url, http_status, http_checked_at, status FROM {$table} WHERE status IN ( 'unreachable', 'broken' ) ORDER BY COALESCE( http_checked_at, updated_at ) DESC, id DESC LIMIT %d", (int) $limit ) );
	}
}
