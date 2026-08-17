<?php
/**
 * Input sanitization, PII masking, blocklist, rate limiting and request-origin
 * checks for Kashiwazaki SEO Concierge.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_Security
 */
class Ks_Concierge_Security {

	/**
	 * Object-cache group for rate-limit counters.
	 */
	const RATE_CACHE_GROUP = 'ks_concierge_rl';

	/**
	 * Sanitize a visitor question.
	 *
	 * @param string $question Raw input.
	 * @return string
	 */
	public static function sanitize_question( $question ) {
		$question = wp_strip_all_tags( (string) $question );
		$question = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $question );
		$question = trim( $question );
		$max      = (int) Ks_Concierge_Settings::get( 'max_question_len', 500 );
		if ( $max > 0 && function_exists( 'mb_substr' ) ) {
			$question = mb_substr( $question, 0, $max );
		}
		return $question;
	}

	/**
	 * Best-effort PII masking. Detection is not exhaustive (free-form names and
	 * addresses may be missed); see also the "no external send" PII mode.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	public static function mask_pii( $text ) {
		$text = (string) $text;
		// Email addresses.
		$text = preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $text );
		// Phone numbers (JP and generic, 9+ digits possibly separated).
		$text = preg_replace( '/(?<!\d)(\+?\d[\d\-\(\)\s]{8,}\d)(?!\d)/', '[phone]', $text );
		// Japanese postal code 123-4567.
		$text = preg_replace( '/(?<!\d)\d{3}-\d{4}(?!\d)/', '[postal]', $text );
		return $text;
	}

	/**
	 * Whether the text contains any potential PII.
	 *
	 * @param string $text Input text.
	 * @return bool
	 */
	public static function contains_pii( $text ) {
		return self::mask_pii( $text ) !== (string) $text;
	}

	/**
	 * Check the question against the configured blocklist.
	 *
	 * @param string $question Sanitized question.
	 * @return bool True when blocked.
	 */
	public static function is_blocked( $question ) {
		$raw = (string) Ks_Concierge_Settings::get( 'blocklist', '' );
		if ( '' === trim( $raw ) ) {
			return false;
		}
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $question ) : strtolower( $question );
		$lines  = preg_split( '/\r\n|\r|\n/', $raw );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$term = function_exists( 'mb_strtolower' ) ? mb_strtolower( $line ) : strtolower( $line );
			if ( false !== strpos( $needle, $term ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Verify that the request originates from the same site, when an Origin or
	 * Referer header is present. A missing header is allowed (rate limiting is
	 * the primary control) to avoid blocking privacy-extension or proxy users.
	 *
	 * @return bool
	 */
	public static function verify_origin() {
		$origin = '';
		if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			$origin = esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
		} elseif ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$origin = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}
		if ( '' === $origin ) {
			return true;
		}
		$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( empty( $origin_host ) || empty( $site_host ) ) {
			return true;
		}
		return strtolower( $origin_host ) === strtolower( $site_host );
	}

	/**
	 * Build an opaque per-visitor session hash for logging/click correlation.
	 *
	 * @return string 64-char hex.
	 */
	public static function session_hash() {
		$ip = self::client_ip();
		$ua = self::client_ua();
		return hash_hmac( 'sha256', $ip . '|' . $ua, wp_salt( 'nonce' ) );
	}

	/**
	 * IP-only hash for rate limiting. Deliberately excludes the User-Agent so a
	 * client cannot reset its rate-limit bucket by rotating the UA header.
	 *
	 * @return string 64-char hex.
	 */
	public static function ip_hash() {
		return hash_hmac( 'sha256', self::client_ip(), wp_salt( 'nonce' ) );
	}

	/**
	 * Raw client User-Agent string (trimmed to a sane length).
	 *
	 * @return string
	 */
	public static function client_ua() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return '' === $ua ? '' : mb_substr( $ua, 0, 512 );
	}

	/**
	 * Resolve the real client IP, accounting for reverse proxies.
	 *
	 * Proxy headers (CF-Connecting-IP / X-Forwarded-For) are client-spoofable, so
	 * they are trusted ONLY when the direct peer (REMOTE_ADDR) is itself a trusted
	 * edge:
	 *  - When `trust_cloudflare` is on AND REMOTE_ADDR is inside a published
	 *    Cloudflare range, use CF-Connecting-IP (a single value CF sets).
	 *  - When the admin has configured `trusted_proxies` CIDRs AND REMOTE_ADDR is
	 *    inside them, use the right-most X-Forwarded-For entry that is NOT itself a
	 *    trusted proxy (the left-most entry is attacker-controllable).
	 * Otherwise REMOTE_ADDR is used verbatim. This also makes rate limiting work
	 * correctly behind a proxy (where REMOTE_ADDR would otherwise be one shared
	 * edge IP for every visitor).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $remote || ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			// Never return a non-IP value to callers (it would be stored/hashed).
			return '';
		}

		// Cloudflare: trust CF-Connecting-IP only when the peer is a CF edge.
		if ( Ks_Concierge_Settings::get( 'trust_cloudflare', false )
			&& self::ip_in_ranges( $remote, self::cloudflare_ranges() ) ) {
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$cf = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
				if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
					return $cf;
				}
			}
		}

		// Generic trusted reverse proxies (admin-configured CIDR list).
		$trusted = self::trusted_proxy_ranges();
		if ( ! empty( $trusted )
			&& self::ip_in_ranges( $remote, $trusted )
			&& ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = array_map( 'trim', explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			// Walk right-to-left, skipping our own trusted hops; the first
			// non-trusted, valid IP is the real client.
			for ( $i = count( $parts ) - 1; $i >= 0; $i-- ) {
				$ip = $parts[ $i ];
				if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					continue;
				}
				if ( ! self::ip_in_ranges( $ip, $trusted ) ) {
					return $ip;
				}
			}
		}

		return $remote;
	}

	/**
	 * Parse the admin-configured trusted-proxy CIDR list (one per line).
	 *
	 * @return string[]
	 */
	protected static function trusted_proxy_ranges() {
		$raw = (string) Ks_Concierge_Settings::get( 'trusted_proxies', '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * True when $ip falls inside any of the given CIDR/IP ranges.
	 *
	 * @param string   $ip     IP address.
	 * @param string[] $ranges CIDR strings (or bare IPs).
	 * @return bool
	 */
	public static function ip_in_ranges( $ip, array $ranges ) {
		foreach ( $ranges as $cidr ) {
			if ( self::ip_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * CIDR membership test that works for both IPv4 and IPv6 via inet_pton.
	 *
	 * @param string $ip   IP address.
	 * @param string $cidr CIDR (or bare IP, treated as /32 or /128).
	 * @return bool
	 */
	public static function ip_in_cidr( $ip, $cidr ) {
		// Normalize an IPv4-mapped IPv6 address (::ffff:a.b.c.d) to plain IPv4 so it
		// matches bundled IPv4 ranges instead of failing the family-length guard.
		if ( 0 === stripos( $ip, '::ffff:' ) && false !== strpos( substr( $ip, 7 ), '.' ) ) {
			$ip = substr( $ip, 7 );
		}
		if ( false === strpos( $cidr, '/' ) ) {
			$cidr .= ( false !== strpos( $cidr, ':' ) ) ? '/128' : '/32';
		}
		list( $subnet, $bits_raw ) = explode( '/', $cidr, 2 );
		// Reject a malformed prefix length: a non-numeric "/abc" would cast to 0 and
		// turn the range into /0 (matches every address of that family).
		if ( ! ctype_digit( (string) $bits_raw ) ) {
			return false;
		}
		$bits = (int) $bits_raw;
		$ipb  = @inet_pton( $ip );      // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$sub  = @inet_pton( $subnet );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $ipb || false === $sub || strlen( $ipb ) !== strlen( $sub ) ) {
			return false;
		}
		if ( $bits < 0 || $bits > ( strlen( $ipb ) * 8 ) ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		$rem   = $bits % 8;
		if ( $bytes > 0 && 0 !== strncmp( $ipb, $sub, $bytes ) ) {
			return false;
		}
		if ( 0 === $rem ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rem ) ) & 0xff );
		return ( ord( $ipb[ $bytes ] ) & ord( $mask ) ) === ( ord( $sub[ $bytes ] ) & ord( $mask ) );
	}

	/**
	 * Published Cloudflare edge IP ranges (IPv4 + IPv6). Bundled static list;
	 * refresh on release if Cloudflare changes it. Source: https://www.cloudflare.com/ips/
	 *
	 * @return string[]
	 */
	public static function cloudflare_ranges() {
		return array(
			// IPv4 (https://www.cloudflare.com/ips-v4).
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			// IPv6 (https://www.cloudflare.com/ips-v6).
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		);
	}

	/**
	 * Enforce a sliding-window rate limit per visitor.
	 *
	 * @return bool True when the request is allowed.
	 */
	public static function check_rate_limit() {
		$limit  = (int) Ks_Concierge_Settings::get( 'rate_limit', 20 );
		$window = (int) Ks_Concierge_Settings::get( 'rate_window', 60 );
		if ( $limit <= 0 || $window <= 0 ) {
			return true;
		}
		$key = 'ks_concierge_rl_' . self::ip_hash();

		// A persistent object cache offers an atomic increment; use it.
		if ( wp_using_ext_object_cache() ) {
			$count = wp_cache_incr( $key, 1, self::RATE_CACHE_GROUP );
			if ( false === $count ) {
				// No counter yet. wp_cache_add() is "create only if absent", so
				// exactly one concurrent request wins it and the rest must
				// increment the winner's value rather than assume 1 — otherwise an
				// opening burst all passes at once, which is the case the limit
				// exists for.
				if ( wp_cache_add( $key, 1, self::RATE_CACHE_GROUP, $window ) ) {
					$count = 1;
				} else {
					$count = wp_cache_incr( $key, 1, self::RATE_CACHE_GROUP );
					if ( false === $count ) {
						// Neither incr nor add worked: the backing store is not
						// answering (a dropped memcached connection behaves this
						// way). Denying everyone would take the site's chat down
						// over a cache outage, so fall through to the database
						// counter, which is authoritative and equally atomic.
						return self::increment_option_counter( $key, $limit, $window );
					}
				}
			}
			return ( (int) $count <= $limit );
		}

		// No object cache: transients live in the options table, so the counter is
		// incremented with a single atomic UPSERT instead of read-then-write. The
		// read-then-write version let every request in a simultaneous burst read
		// the same value and pass together.
		return self::increment_option_counter( $key, $limit, $window );
	}

	/**
	 * Atomically increment a windowed counter stored as a transient, returning
	 * whether the caller is still within the limit.
	 *
	 * Writes the transient's own option rows directly: `INSERT ... ON DUPLICATE
	 * KEY UPDATE` is one statement, so concurrent requests serialize inside MySQL
	 * and each receives a distinct count. Only reached when no persistent object
	 * cache is installed, which is exactly when transients are option rows.
	 *
	 * @param string $key    Transient key (without the _transient_ prefix).
	 * @param int    $limit  Maximum allowed within the window.
	 * @param int    $window Window length in seconds.
	 * @return bool
	 */
	/**
	 * Delete expired rate-limit rows written by the database counter.
	 *
	 * WordPress skips its own expired-transient sweep entirely when a persistent
	 * object cache is installed (wp-includes/option.php: `if ( ! $force_db &&
	 * wp_using_ext_object_cache() ) return;`). The database path is exactly what
	 * runs when that cache is unavailable, so those rows would be left behind on
	 * such a site. Called from the plugin's daily maintenance job.
	 *
	 * @return int Rows removed.
	 */
	public static function purge_expired_rate_limits() {
		global $wpdb;
		$now = time();
		// Both rows go in ONE statement, keyed on the timeout row being expired.
		// Deleting them separately let a request roll the window over between the
		// two deletes, after which the second delete removed a counter that had
		// just become live again. The composite value is never parsed here, so a
		// malformed one cannot abort the statement under strict mode. The REGEXP
		// guard on the timeout value covers the same hazard for these deletes: an
		// externally corrupted row would otherwise abort the daily sweep.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE v, t FROM {$wpdb->options} v
				 INNER JOIN {$wpdb->options} t
					 ON t.option_name = CONCAT( '_transient_timeout_', SUBSTRING( v.option_name, 12 ) )
				 WHERE v.option_name LIKE %s
				   AND t.option_value REGEXP '^[0-9]+$'
				   AND CAST( t.option_value AS UNSIGNED ) < %d",
				$wpdb->esc_like( '_transient_ks_concierge_rl_' ) . '%',
				(int) $now
			)
		);
		// Counter rows whose timeout row is gone. This direction cannot arise from
		// this class any more (the timeout row is always written first), but core's
		// own transient sweep can delete a timeout row on its own, and without this
		// the counter would never be collected. Age is read from the value's own
		// embedded expiry, guarded so a malformed row is skipped rather than
		// aborting the statement.
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE v FROM {$wpdb->options} v
				 LEFT JOIN {$wpdb->options} t
					 ON t.option_name = CONCAT( '_transient_timeout_', SUBSTRING( v.option_name, 12 ) )
				 WHERE v.option_name LIKE %s
				   AND t.option_name IS NULL
				   AND SUBSTRING_INDEX( v.option_value, '|', 1 ) REGEXP '^[0-9]+$'
				   AND CAST( SUBSTRING_INDEX( v.option_value, '|', 1 ) AS UNSIGNED ) < %d",
				$wpdb->esc_like( '_transient_ks_concierge_rl_' ) . '%',
				(int) $now
			)
		);
		// Timeout rows whose counter is already gone (an interrupted write, or a
		// row removed by other means). Harmless but pointless to keep.
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE t FROM {$wpdb->options} t
				 LEFT JOIN {$wpdb->options} v
					 ON v.option_name = CONCAT( '_transient_', SUBSTRING( t.option_name, 20 ) )
				 WHERE t.option_name LIKE %s
				   AND t.option_value REGEXP '^[0-9]+$'
				   AND CAST( t.option_value AS UNSIGNED ) < %d
				   AND v.option_name IS NULL",
				$wpdb->esc_like( '_transient_timeout_ks_concierge_rl_' ) . '%',
				(int) $now
			)
		);
		// phpcs:enable
		if ( $removed > 0 && function_exists( 'wp_cache_flush_group' ) ) {
			// Added in WordPress 6.1; this plugin supports 6.0, where the deleted
			// rows simply age out of the options cache on the next request.
			wp_cache_flush_group( 'options' );
		}
		return $removed;
	}

	protected static function increment_option_counter( $key, $limit, $window ) {
		global $wpdb;
		$value_option   = '_transient_' . $key;
		$timeout_option = '_transient_timeout_' . $key;

		// Ordering matters more than any single statement here. The companion
		// timeout row is refreshed BEFORE the counter row, because the cleanup
		// sweep decides what to delete by looking at that timeout: refreshing it
		// first means a sweep running alongside this request always sees a live
		// window and leaves both rows alone. Doing it the other way round let the
		// sweep delete a counter that had just been rolled over, which reset the
		// count and allowed a burst straight through.
		//
		// Two attempts: if a sweep removed the rows in the instant between the
		// INSERTs and the UPDATE, the UPDATE matches nothing and the request would
		// otherwise be judged on a count that was never assigned.
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$now     = time();
			$expires = $now + $window;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $timeout_option, (string) $expires ) );
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $value_option, $expires . '|0' ) );

			// Advance-only: a request holding an older expiry can never push the
			// window back into the past and make the sweep drop a live counter.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND ( option_value NOT REGEXP '^[0-9]+$' OR CAST( option_value AS UNSIGNED ) < %d )", (string) $expires, $timeout_option, (int) $expires ) );

			// One statement decides the window and this request's number. The
			// first branch also repairs a value that is not "digits|digits":
			// applying CAST() to such a string raises a truncation warning that a
			// server in strict mode turns into an error, aborting the statement
			// and disabling rate limiting entirely.
			$rows = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options}
					 SET option_value = CASE
						 WHEN SUBSTRING_INDEX( option_value, '|', 1 ) NOT REGEXP '^[0-9]+$'
							 OR SUBSTRING_INDEX( option_value, '|', -1 ) NOT REGEXP '^[0-9]+$'
							 THEN CONCAT( %d, '|', LAST_INSERT_ID( 1 ) )
						 WHEN CAST( SUBSTRING_INDEX( option_value, '|', 1 ) AS UNSIGNED ) <= %d
							 THEN CONCAT( %d, '|', LAST_INSERT_ID( 1 ) )
						 ELSE CONCAT(
							 SUBSTRING_INDEX( option_value, '|', 1 ), '|',
							 LAST_INSERT_ID( CAST( SUBSTRING_INDEX( option_value, '|', -1 ) AS UNSIGNED ) + 1 )
						 )
					 END
					 WHERE option_name = %s",
					$expires,
					$now,
					$expires,
					$value_option
				)
			);
			// phpcs:enable

			if ( $rows > 0 ) {
				// LAST_INSERT_ID() is per connection, so this is the number this
				// request was assigned — not whatever the row holds by now.
				$count = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
				wp_cache_delete( $value_option, 'options' );
				wp_cache_delete( $timeout_option, 'options' );
				return ( $count >= 1 && $count <= $limit );
			}
		}

		wp_cache_delete( $value_option, 'options' );
		wp_cache_delete( $timeout_option, 'options' );
		// Two attempts and still no row: something is wrong with the counter, and
		// letting the request through would mean no limit at all.
		return false;
	}
}
