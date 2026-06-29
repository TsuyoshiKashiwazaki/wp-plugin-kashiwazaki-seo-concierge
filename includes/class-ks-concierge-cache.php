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
		add_action( 'admin_post_ks_concierge_reindex_now', array( $this, 'handle_manual_reindex' ) );
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
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update(
					$wpdb->prefix . 'ks_concierge_pages',
					array(
						'lastmod'    => $lastmod,
						'status'     => 'active',
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$pages} SET status = 'broken' WHERE id IN ({$ph})", $chunk ) );
			Ks_Concierge_Embeddings::delete_for_pages( $chunk );
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
	 * KEPT for 'excluded' so that an admin exclude rule is reversible without a
	 * paid re-embed: search already filters to status='active', so an excluded
	 * page with its vector intact simply drops out of results, and removing the
	 * rule (status back to 'active') makes it searchable again immediately.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, array( 'status' => $status ), array( 'id' => $page->id ) );
		if ( 'active' !== $status && 'excluded' !== $status ) {
			Ks_Concierge_Embeddings::delete_for_pages( array( (int) $page->id ) );
		}
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
}
