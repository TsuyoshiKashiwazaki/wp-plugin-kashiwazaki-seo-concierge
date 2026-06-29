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
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return hash_hmac( 'sha256', $ip . '|' . $ua, wp_salt( 'nonce' ) );
	}

	/**
	 * IP-only hash for rate limiting. Deliberately excludes the User-Agent so a
	 * client cannot reset its rate-limit bucket by rotating the UA header.
	 *
	 * @return string 64-char hex.
	 */
	public static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
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
		$key   = 'ks_concierge_rl_' . self::ip_hash();
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}
}
