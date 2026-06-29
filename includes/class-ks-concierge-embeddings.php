<?php
/**
 * Embedding storage (packed, L2-normalized BLOBs), a versioned in-memory vector
 * matrix cache and cosine (dot-product) similarity search.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_Embeddings
 */
class Ks_Concierge_Embeddings {

	const CACHE_GROUP = 'ks_concierge';

	/**
	 * Current embedding configuration signature.
	 *
	 * substr(sha1(provider|base|model|dims),0,16). Must match the SQL formula
	 * used by the v2 migration backfill (Ks_Concierge_Activator::backfill_v2).
	 *
	 * @return string
	 */
	public static function current_embed_sig() {
		$provider = Ks_Concierge_Settings::get_provider( 'embed' );
		$base     = Ks_Concierge_Settings::get_api_base( 'embed' );
		$model    = (string) Ks_Concierge_Settings::get( 'embeddings_model', 'text-embedding-3-small' );
		$dims     = (int) Ks_Concierge_Settings::get( 'embeddings_dims', 1536 );
		return substr( sha1( $provider . '|' . $base . '|' . $model . '|' . $dims ), 0, 16 );
	}

	/**
	 * Current matrix cache version for a signature (bumped on every write so a
	 * partially-grown matrix is not served stale during a reindex).
	 *
	 * @param string $sig Embedding signature.
	 * @return int
	 */
	protected static function matrix_ver( $sig ) {
		return (int) get_option( 'ks_concierge_matrix_ver_' . $sig, 0 );
	}

	/**
	 * Bump the matrix version for the current signature and drop the prior
	 * version's cached matrix so it cannot accumulate during a reindex.
	 *
	 * @return void
	 */
	protected static function bump_matrix_ver() {
		$sig      = self::current_embed_sig();
		$prev     = self::matrix_ver( $sig );
		$prev_key = self::cache_key_for( $sig, $prev );
		wp_cache_delete( $prev_key, self::CACHE_GROUP );
		delete_transient( 'ks_concierge_' . $prev_key );
		update_option( 'ks_concierge_matrix_ver_' . $sig, $prev + 1, false );
	}

	/**
	 * Whether a reindex drain session is currently active (for cache TTL choice).
	 *
	 * @return bool
	 */
	protected static function reindex_active() {
		$state = get_option( 'ks_concierge_reindex_state', array() );
		return is_array( $state ) && ! empty( $state['active'] );
	}

	/**
	 * L2-normalize a vector. Returns null for zero/NaN vectors.
	 *
	 * @param float[] $vector Raw vector.
	 * @return float[]|null
	 */
	public static function normalize( array $vector ) {
		$sum = 0.0;
		foreach ( $vector as $v ) {
			$sum += $v * $v;
		}
		if ( $sum <= 0.0 || is_nan( $sum ) || is_infinite( $sum ) ) {
			return null;
		}
		$norm = sqrt( $sum );
		$out  = array();
		foreach ( $vector as $v ) {
			$out[] = $v / $norm;
		}
		return $out;
	}

	/**
	 * Pack a float vector to a binary blob (little-endian float32).
	 *
	 * @param float[] $vector Vector.
	 * @return string
	 */
	public static function pack_vector( array $vector ) {
		return pack( 'g*', ...array_map( 'floatval', $vector ) );
	}

	/**
	 * Unpack a binary blob back to a float vector with validation.
	 *
	 * @param string $blob Packed blob.
	 * @param int    $dims Expected dimensions.
	 * @return float[]|null Null when the blob does not match the expected size.
	 */
	public static function unpack_vector( $blob, $dims ) {
		if ( ! is_string( $blob ) || strlen( $blob ) !== $dims * 4 ) {
			return null;
		}
		$values = unpack( 'g*', $blob );
		if ( false === $values ) {
			return null;
		}
		$values = array_values( $values );
		if ( count( $values ) !== $dims ) {
			return null;
		}
		return $values;
	}

	/**
	 * Store a normalized embedding for a page.
	 *
	 * @param int     $page_id      Page row id.
	 * @param float[] $vector       Raw embedding vector.
	 * @param string  $model        Model id.
	 * @param int     $dims         Dimensions.
	 * @param string  $content_hash Source content hash (for change detection).
	 * @return bool
	 */
	public static function store( $page_id, array $vector, $model, $dims, $content_hash = '' ) {
		global $wpdb;
		$normalized = self::normalize( $vector );
		if ( null === $normalized || count( $normalized ) !== (int) $dims ) {
			return false;
		}
		$table = $wpdb->prefix . 'ks_concierge_embeddings';
		$blob  = self::pack_vector( $normalized );
		$sig   = self::current_embed_sig();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (page_id, vector, model, dims, embed_sig, content_hash, created_at)
				 VALUES (%d, %s, %s, %d, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE vector = VALUES(vector), model = VALUES(model), dims = VALUES(dims), embed_sig = VALUES(embed_sig), content_hash = VALUES(content_hash), created_at = VALUES(created_at)",
				$page_id,
				$blob,
				$model,
				(int) $dims,
				$sig,
				(string) $content_hash,
				current_time( 'mysql', true )
			)
		);
		// phpcs:enable
		self::bump_matrix_ver();
		return true;
	}

	/**
	 * Delete embeddings for a set of page ids.
	 *
	 * @param int[] $page_ids Page ids.
	 * @return void
	 */
	public static function delete_for_pages( array $page_ids ) {
		global $wpdb;
		$page_ids = array_map( 'intval', $page_ids );
		$page_ids = array_filter( $page_ids );
		if ( empty( $page_ids ) ) {
			return;
		}
		$table        = $wpdb->prefix . 'ks_concierge_embeddings';
		$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE page_id IN ({$placeholders})", $page_ids ) );
		self::bump_matrix_ver();
	}

	/**
	 * Matrix cache key for a given signature and version.
	 *
	 * @param string $sig Embedding signature.
	 * @param int    $ver Matrix version.
	 * @return string
	 */
	protected static function cache_key_for( $sig, $ver ) {
		return 'matrix_' . md5( $sig . ':' . (int) $ver );
	}

	/**
	 * Current vector matrix cache key, versioned by embedding signature and the
	 * per-signature matrix version.
	 *
	 * @return string
	 */
	protected static function cache_key() {
		$sig = self::current_embed_sig();
		return self::cache_key_for( $sig, self::matrix_ver( $sig ) );
	}

	/**
	 * Invalidate the in-memory matrix cache (called after a reindex completes).
	 *
	 * @return void
	 */
	public static function flush_matrix_cache() {
		$key = self::cache_key();
		wp_cache_delete( $key, self::CACHE_GROUP );
		delete_transient( 'ks_concierge_' . $key );
	}

	/**
	 * Search the index for the top-N pages most similar to a query vector.
	 *
	 * The query vector must already be L2-normalized and produced with the same
	 * model/dims that the serving matrix was built with.
	 *
	 * @param float[] $query_vector Normalized query vector.
	 * @param int     $top_n        Number of results.
	 * @param string  $lang         Optional language filter (page lang).
	 * @return array<int,array{page_id:int,score:float}>
	 */
	public static function search( array $query_vector, $top_n, $lang = '' ) {
		$matrix = self::get_matrix();
		if ( empty( $matrix ) ) {
			return array();
		}
		$dims = (int) Ks_Concierge_Settings::get( 'embeddings_dims', 1536 );
		if ( count( $query_vector ) !== $dims ) {
			return array();
		}
		$scored = array();
		foreach ( $matrix as $row ) {
			if ( '' !== $lang && '' !== $row['lang'] && $row['lang'] !== $lang ) {
				continue;
			}
			$vec = $row['vector'];
			if ( count( $vec ) !== $dims ) {
				continue;
			}
			$dot = 0.0;
			for ( $i = 0; $i < $dims; $i++ ) {
				$dot += $query_vector[ $i ] * $vec[ $i ];
			}
			$scored[] = array(
				'page_id' => $row['page_id'],
				'score'   => $dot,
			);
		}
		usort(
			$scored,
			static function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return 0;
				}
				return ( $a['score'] < $b['score'] ) ? 1 : -1;
			}
		);
		return array_slice( $scored, 0, max( 1, (int) $top_n ) );
	}

	/**
	 * Build or retrieve the active normalized vector matrix.
	 *
	 * Prefers a persistent object cache; falls back to a transient; and finally
	 * to a direct (batched) database read when neither is warm.
	 *
	 * @return array<int,array{page_id:int,lang:string,vector:float[]}>
	 */
	protected static function get_matrix() {
		$key   = self::cache_key();
		$found = false;
		$data  = wp_cache_get( $key, self::CACHE_GROUP, false, $found );
		if ( $found && is_array( $data ) ) {
			return $data;
		}
		$data = get_transient( 'ks_concierge_' . $key );
		if ( is_array( $data ) ) {
			wp_cache_set( $key, $data, self::CACHE_GROUP, HOUR_IN_SECONDS );
			return $data;
		}
		$data = self::build_matrix();
		// During an active reindex the matrix grows incrementally; use a short
		// TTL so a partially-built matrix is not pinned, and so empty/partial
		// results do not stick for a full day.
		$ttl = self::reindex_active() ? MINUTE_IN_SECONDS : DAY_IN_SECONDS;
		wp_cache_set( $key, $data, self::CACHE_GROUP, self::reindex_active() ? MINUTE_IN_SECONDS : HOUR_IN_SECONDS );
		set_transient( 'ks_concierge_' . $key, $data, $ttl );
		return $data;
	}

	/**
	 * Read active page embeddings from the database in batches.
	 *
	 * @return array<int,array{page_id:int,lang:string,vector:float[]}>
	 */
	protected static function build_matrix() {
		global $wpdb;
		$dims  = (int) Ks_Concierge_Settings::get( 'embeddings_dims', 1536 );
		$sig   = self::current_embed_sig();
		$pages = $wpdb->prefix . 'ks_concierge_pages';
		$emb   = $wpdb->prefix . 'ks_concierge_embeddings';
		$out   = array();
		$batch = 500;
		$offset = 0;
		while ( true ) {
			// Filter by the current embedding signature so vectors from a previous
			// provider/model/dims configuration are never mixed into the search.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.page_id AS page_id, p.lang AS lang, e.vector AS vector
					 FROM {$emb} e
					 INNER JOIN {$pages} p ON p.id = e.page_id
					 WHERE p.status = 'active' AND e.embed_sig = %s
					 ORDER BY e.page_id ASC
					 LIMIT %d OFFSET %d",
					$sig,
					$batch,
					$offset
				),
				ARRAY_A
			);
			// phpcs:enable
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$vec = self::unpack_vector( $row['vector'], $dims );
				if ( null === $vec ) {
					continue;
				}
				$out[] = array(
					'page_id' => (int) $row['page_id'],
					'lang'    => (string) $row['lang'],
					'vector'  => $vec,
				);
			}
			if ( count( $rows ) < $batch ) {
				break;
			}
			$offset += $batch;
		}
		return $out;
	}
}
