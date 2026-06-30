<?php
/**
 * Settings storage, defaults and API key encryption for Kashiwazaki SEO Concierge.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_Settings
 *
 * Central access point for plugin options. Stores the option schema, provides
 * typed getters, and handles best-effort at-rest encryption of the OpenAI API key.
 */
class Ks_Concierge_Settings {

	const OPTION_KEY = 'ks_concierge_settings';

	/**
	 * Cached settings array.
	 *
	 * @var array<string,mixed>|null
	 */
	protected static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'api_key_cipher'     => '',
			'sitemap_url'        => '',
			'llms_txt_url'       => '',
			'llms_txt_enabled'   => true,
			// Provider selection (embeddings / chat are independent). Empty
			// api_base means the provider default is used. Backward compatible:
			// both default to OpenAI and reuse the legacy api_key_cipher.
			'embed_provider'        => 'openai',
			'embed_api_base'        => '',
			'embed_api_key_cipher'  => '',
			'chat_provider'         => 'openai',
			'chat_api_base'         => '',
			'chat_api_key_cipher'   => '',
			'chat_structured_mode'  => 'auto',
			// Custom-provider per-million-token prices (USD). 0 = unresolved, in
			// which case the token backstop applies (see cost limits below).
			'custom_embed_price_in'  => 0.0,
			'custom_chat_price_in'   => 0.0,
			'custom_chat_price_out'  => 0.0,
			'custom_embed_paid'      => true,
			'custom_chat_paid'       => true,
			'chat_model'         => 'gpt-4o-mini',
			'embeddings_model'   => 'text-embedding-3-small',
			'embeddings_dims'    => 1536,
			'candidate_count'    => 10,
			'reindex_interval'   => 'daily',
			'tab_label'          => '',
			'widget_title'       => '',
			'bot_avatar'         => '',
			'accent_color'       => '#1e73be',
			'initial_message'    => '',
			'suggest_chips'      => array(),
			'display_condition'  => 'all',
			'system_prompt'      => '',
			'prompt_template'    => 'general',
			'exclude_rules'      => '',
			'priority_rules'     => '',
			'reachability_check' => true,
			'log_ip'             => true,
			'trust_cloudflare'   => false,
			'trusted_proxies'    => '',
			'rate_limit'         => 20,
			'rate_window'        => 60,
			'max_question_len'   => 500,
			'blocklist'          => '',
			'pii_mode'           => 'mask',
			'log_retention_days' => 90,
			'consent_required'   => false,
			// Non-zero defaults so a fresh install on a public endpoint is not
			// exposed to unbounded OpenAI charges. Admins can raise or disable (0).
			'cost_limit_daily'   => 5.0,
			'cost_limit_monthly' => 50.0,
			// Non-zero token backstop for paid providers whose per-token price is
			// not resolvable (e.g. a custom endpoint with no price set), so such a
			// provider is never left effectively uncapped. 0 disables (admin opt).
			'token_limit_daily'   => 200000,
			'token_limit_monthly' => 5000000,
			// Absolute cap on the number of paid API calls (embeddings + chat) per
			// UTC day / month. A hard runaway backstop independent of price: even if
			// a loop or misconfiguration drives many calls, this stops them. Set
			// generously so a normal full-site reindex is not blocked; the USD cap
			// above remains the primary spend guarantee. 0 disables (admin opt).
			'request_limit_daily'   => 10000,
			'request_limit_monthly' => 200000,
			'ga4_measurement_id' => '',
		);
	}

	/**
	 * Get all settings, merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION_KEY, array() );
			$stored      = is_array( $stored ) ? $stored : array();
			self::$cache = wp_parse_args( $stored, self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Persist the settings array (already sanitized by the caller).
	 *
	 * @param array<string,mixed> $values Settings to store.
	 * @return void
	 */
	public static function update( array $values ) {
		update_option( self::OPTION_KEY, $values, false );
		self::$cache = null;
	}

	/**
	 * Default API base URL for a provider and role.
	 *
	 * @param string $provider openai|zai|ollama|custom.
	 * @param string $role     embed|chat.
	 * @return string
	 */
	public static function provider_default_base( $provider, $role ) {
		switch ( $provider ) {
			case 'zai':
				return ( 'embed' === $role )
					? 'https://api.z.ai/api/paas/v4'
					: 'https://api.z.ai/api/coding/paas/v4';
			case 'ollama':
				// Ollama Cloud (ollama.com). A local Ollama user can override this
				// with http://localhost:11434/v1 (detected as local/free by host).
				return 'https://ollama.com/v1';
			case 'openai':
			case 'custom':
			default:
				return 'https://api.openai.com/v1';
		}
	}

	/**
	 * Resolve the effective API base URL for a role.
	 *
	 * @param string $role embed|chat.
	 * @return string
	 */
	public static function get_api_base( $role ) {
		$provider = self::get_provider( $role );
		$base     = (string) self::get( ( 'embed' === $role ) ? 'embed_api_base' : 'chat_api_base', '' );
		$base     = trim( $base );
		if ( '' === $base ) {
			$base = self::provider_default_base( $provider, $role );
		}
		return untrailingslashit( $base );
	}

	/**
	 * Resolve the provider for a role.
	 *
	 * @param string $role embed|chat.
	 * @return string openai|zai|ollama|custom.
	 */
	public static function get_provider( $role ) {
		$key      = ( 'embed' === $role ) ? 'embed_provider' : 'chat_provider';
		$provider = (string) self::get( $key, 'openai' );
		return in_array( $provider, array( 'openai', 'zai', 'ollama', 'custom' ), true ) ? $provider : 'openai';
	}

	/**
	 * Whether a host is a loopback address (the request never leaves the box, so
	 * it needs no API key and incurs no metered cost).
	 *
	 * @param string $base Base URL.
	 * @return bool
	 */
	public static function base_host_is_local( $base ) {
		$host = (string) wp_parse_url( (string) $base, PHP_URL_HOST );
		$host = strtolower( trim( $host, '[]' ) );
		return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	/**
	 * Whether a role's endpoint is local/free (no key required, cost caps do not
	 * apply). This is decided by the endpoint host, not the provider name: a
	 * loopback Ollama is free/keyless, but Ollama Cloud (api host) needs an API
	 * key and is metered like any other remote provider.
	 *
	 * @param string $role embed|chat.
	 * @return bool
	 */
	public static function is_local_free( $role ) {
		return self::base_host_is_local( self::get_api_base( $role ) );
	}

	/**
	 * Backward-compatible provider-only check. Treats Ollama as local/free only
	 * by name; callers that know the role should prefer is_local_free( $role ).
	 *
	 * @param string $provider Provider id.
	 * @return bool
	 */
	public static function provider_is_local_free( $provider ) {
		return 'ollama' === $provider;
	}

	/**
	 * Resolve the effective API key for a role.
	 *
	 * Resolution order (P = the role's provider):
	 *   1. role constant (KS_CONCIERGE_EMBED_API_KEY / KS_CONCIERGE_CHAT_API_KEY)
	 *   2. shared constant KS_CONCIERGE_API_KEY — ONLY when P = openai
	 *   3. role cipher (embed_api_key_cipher / chat_api_key_cipher)
	 *   4. shared api_key_cipher — ONLY when P = openai
	 *
	 * The openai-only gate on steps 2 and 4 prevents an OpenAI key from ever
	 * being sent to a GLM/Ollama/custom endpoint.
	 *
	 * @param string $role embed|chat.
	 * @return string Empty string when no key is configured.
	 */
	public static function get_api_key( $role = 'chat' ) {
		$provider     = self::get_provider( $role );
		$role_const   = ( 'embed' === $role ) ? 'KS_CONCIERGE_EMBED_API_KEY' : 'KS_CONCIERGE_CHAT_API_KEY';
		$role_cipher  = ( 'embed' === $role ) ? 'embed_api_key_cipher' : 'chat_api_key_cipher';

		// 1. Role-specific constant (applies to this role for any provider).
		if ( defined( $role_const ) && '' !== (string) constant( $role_const ) ) {
			return (string) constant( $role_const );
		}
		// 2. Shared legacy constant — openai only.
		if ( 'openai' === $provider && defined( 'KS_CONCIERGE_API_KEY' ) && '' !== (string) constant( 'KS_CONCIERGE_API_KEY' ) ) {
			return (string) constant( 'KS_CONCIERGE_API_KEY' );
		}
		// 3. Role-specific cipher.
		$cipher = (string) self::get( $role_cipher, '' );
		if ( '' !== $cipher ) {
			$plain = self::decrypt( $cipher );
			if ( false !== $plain && '' !== $plain ) {
				return $plain;
			}
		}
		// 4. Shared legacy cipher — openai only.
		if ( 'openai' === $provider ) {
			$shared = (string) self::get( 'api_key_cipher', '' );
			if ( '' !== $shared ) {
				$plain = self::decrypt( $shared );
				return ( false === $plain ) ? '' : $plain;
			}
		}
		return '';
	}

	/**
	 * Whether a usable API key (or no-key-needed provider) is configured for a role.
	 *
	 * Local providers (Ollama) need no key, so they are always "ready".
	 *
	 * @param string $role embed|chat.
	 * @return bool
	 */
	public static function has_key( $role = 'chat' ) {
		if ( self::is_local_free( $role ) ) {
			return true;
		}
		return '' !== self::get_api_key( $role );
	}

	/**
	 * Whether the role's API key is injected through a wp-config constant.
	 *
	 * @param string $role embed|chat.
	 * @return bool
	 */
	public static function api_key_is_constant( $role = 'chat' ) {
		$role_const = ( 'embed' === $role ) ? 'KS_CONCIERGE_EMBED_API_KEY' : 'KS_CONCIERGE_CHAT_API_KEY';
		if ( defined( $role_const ) && '' !== (string) constant( $role_const ) ) {
			return true;
		}
		return 'openai' === self::get_provider( $role )
			&& defined( 'KS_CONCIERGE_API_KEY' )
			&& '' !== (string) constant( 'KS_CONCIERGE_API_KEY' );
	}

	/**
	 * Derive a 32-byte encryption key from the WordPress salts.
	 *
	 * @return string Raw 32-byte key.
	 */
	protected static function derive_key() {
		$salt = wp_salt( 'secure_auth' );
		return hash_hmac( 'sha256', 'ks_concierge_api_key', $salt, true );
	}

	/**
	 * Encrypt a plaintext API key for at-rest storage.
	 *
	 * Note: this is best-effort obfuscation to avoid storing the key in
	 * plaintext. It does not protect against an attacker with simultaneous
	 * read access to both wp-config.php and the database.
	 *
	 * @param string $plain Plaintext value.
	 * @return string Base64( nonce . ciphertext ) or empty string on failure.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}
		try {
			$key    = self::derive_key();
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, $key );
		} catch ( Exception $e ) {
			return '';
		}
		return base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypt a stored API key.
	 *
	 * @param string $stored Base64( nonce . ciphertext ).
	 * @return string|false Plaintext or false on failure.
	 */
	public static function decrypt( $stored ) {
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return false;
		}
		$raw = base64_decode( (string) $stored, true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::derive_key() );
		} catch ( Exception $e ) {
			return false;
		}
		return ( false === $plain ) ? false : $plain;
	}
}
