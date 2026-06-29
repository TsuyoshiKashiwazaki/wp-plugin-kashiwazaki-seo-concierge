<?php
/**
 * AI provider relay (Embeddings + Chat Completions), usage accounting and the
 * cost-limit circuit breaker for Kashiwazaki SEO Concierge.
 *
 * Supports OpenAI, GLM (Z.AI), Ollama and any OpenAI-compatible custom endpoint,
 * selected independently for embeddings and chat. The class name is retained for
 * backward compatibility with the loader and hooks.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_OpenAI
 */
class Ks_Concierge_OpenAI {

	const API_BASE = 'https://api.openai.com/v1';

	/**
	 * Approximate per-1K-token pricing in USD (input/output) for estimation.
	 *
	 * @var array<string,array{in:float,out:float}>
	 */
	protected static $pricing = array(
		'text-embedding-3-small' => array(
			'in'  => 0.00002,
			'out' => 0.0,
		),
		'text-embedding-3-large' => array(
			'in'  => 0.00013,
			'out' => 0.0,
		),
		'gpt-4o-mini'            => array(
			'in'  => 0.00015,
			'out' => 0.00060,
		),
		'gpt-4o'                 => array(
			'in'  => 0.0025,
			'out' => 0.0100,
		),
	);

	/**
	 * Approximate per-1K-token pricing for GLM (Z.AI) models (estimates; override
	 * via the ks_concierge_pricing filter for exact billing).
	 *
	 * @var array<string,array{in:float,out:float}>
	 */
	protected static $pricing_zai = array(
		'glm-4.6'     => array(
			'in'  => 0.0006,
			'out' => 0.0022,
		),
		'glm-4.5'     => array(
			'in'  => 0.0006,
			'out' => 0.0022,
		),
		'glm-4.5-air' => array(
			'in'  => 0.0002,
			'out' => 0.0011,
		),
		'embedding-3' => array(
			'in'  => 0.00005,
			'out' => 0.0,
		),
		'embedding-2' => array(
			'in'  => 0.00005,
			'out' => 0.0,
		),
	);

	/**
	 * Resolve per-1K-token pricing for a role's model, or null when the provider
	 * is paid but the price cannot be resolved (e.g. a custom endpoint with no
	 * price set) — the caller then applies the token backstop.
	 *
	 * @param string $role  embed|chat.
	 * @param string $model Model id.
	 * @return array{in:float,out:float,free?:bool}|null
	 */
	protected static function price_for( $role, $model ) {
		$provider = Ks_Concierge_Settings::get_provider( $role );

		if ( Ks_Concierge_Settings::is_local_free( $role ) ) {
			return array(
				'in'   => 0.0,
				'out'  => 0.0,
				'free' => true,
			);
		}

		if ( 'custom' === $provider ) {
			$in  = (float) Ks_Concierge_Settings::get( 'embed' === $role ? 'custom_embed_price_in' : 'custom_chat_price_in', 0 );
			// Embeddings have no output tokens, so only chat carries an out price.
			$out = ( 'embed' === $role ) ? 0.0 : (float) Ks_Concierge_Settings::get( 'custom_chat_price_out', 0 );
			if ( $in <= 0 && $out <= 0 ) {
				return null; // Unresolved: token backstop applies.
			}
			// Custom prices are entered per MILLION tokens; convert to per-1K.
			return array(
				'in'  => $in / 1000,
				'out' => $out / 1000,
			);
		}

		$table = ( 'zai' === $provider ) ? self::$pricing_zai : self::$pricing;
		if ( isset( $table[ $model ] ) ) {
			return $table[ $model ];
		}

		/**
		 * Filter per-1K-token pricing for a provider/model, returning
		 * array{in:float,out:float} to resolve, or null to leave unresolved.
		 *
		 * @param array|null $price    Resolved price or null.
		 * @param string     $provider Provider id.
		 * @param string     $model    Model id.
		 * @param string     $role     embed|chat.
		 */
		$filtered = apply_filters( 'ks_concierge_pricing', null, $provider, $model, $role );
		if ( is_array( $filtered ) && isset( $filtered['in'], $filtered['out'] ) ) {
			return array(
				'in'  => (float) $filtered['in'],
				'out' => (float) $filtered['out'],
			);
		}

		// Unknown model on a known paid provider: conservative non-zero estimate
		// so the USD breaker still engages.
		$is_embed = ( false !== strpos( $model, 'embedding' ) );
		return $is_embed
			? array(
				'in'  => 0.00013,
				'out' => 0.0,
			)
			: array(
				'in'  => 0.0025,
				'out' => 0.0100,
			);
	}

	/**
	 * Whether a usable API key (or no-key provider) is configured for a role.
	 *
	 * @param string $role embed|chat.
	 * @return bool
	 */
	public static function has_key( $role = 'chat' ) {
		return Ks_Concierge_Settings::has_key( $role );
	}

	/**
	 * Whether the cost circuit breaker is currently tripped for a role.
	 *
	 * Two gates: (1) the shared USD total cap (embed + chat) preserves the
	 * existing total-spend semantics; (2) a per-role token backstop engages only
	 * when the role's provider is paid but its price is unresolvable. Local/free
	 * providers (Ollama) never trip the breaker.
	 *
	 * @param string $role embed|chat.
	 * @return bool
	 */
	public static function is_breaker_open( $role = 'chat' ) {
		$provider = Ks_Concierge_Settings::get_provider( $role );
		// Absolute call-count cap applies regardless of provider (a runaway loop
		// is bad even against a free local endpoint).
		if ( self::total_requests_tripped() ) {
			return true;
		}
		if ( Ks_Concierge_Settings::is_local_free( $role ) ) {
			return self::total_cost_tripped();
		}

		if ( self::total_cost_tripped() ) {
			return true;
		}

		// Token backstop: only for paid providers with an unresolvable price.
		$model = (string) Ks_Concierge_Settings::get( 'embed' === $role ? 'embeddings_model' : 'chat_model', '' );
		if ( null === self::price_for( $role, $model ) ) {
			$t_daily   = (int) Ks_Concierge_Settings::get( 'token_limit_daily', 0 );
			$t_monthly = (int) Ks_Concierge_Settings::get( 'token_limit_monthly', 0 );
			if ( $t_daily > 0 && self::usage_tokens( $role, gmdate( 'Y-m-d' ) ) >= $t_daily ) {
				return true;
			}
			if ( $t_monthly > 0 && self::usage_tokens_month( $role, gmdate( 'Y-m' ) ) >= $t_monthly ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the shared USD total cap (daily or monthly) is exceeded.
	 *
	 * @return bool
	 */
	protected static function total_cost_tripped() {
		$daily   = (float) Ks_Concierge_Settings::get( 'cost_limit_daily', 0 );
		$monthly = (float) Ks_Concierge_Settings::get( 'cost_limit_monthly', 0 );
		if ( $daily <= 0 && $monthly <= 0 ) {
			return false;
		}
		if ( $daily > 0 && self::usage_cost( gmdate( 'Y-m-d' ) ) >= $daily ) {
			return true;
		}
		if ( $monthly > 0 && self::usage_cost_month( gmdate( 'Y-m' ) ) >= $monthly ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether the absolute API call-count cap (daily or monthly) is exceeded.
	 * A hard runaway backstop independent of price/tokens.
	 *
	 * @return bool
	 */
	protected static function total_requests_tripped() {
		$daily   = (int) Ks_Concierge_Settings::get( 'request_limit_daily', 0 );
		$monthly = (int) Ks_Concierge_Settings::get( 'request_limit_monthly', 0 );
		if ( $daily <= 0 && $monthly <= 0 ) {
			return false;
		}
		if ( $daily > 0 && self::usage_requests( gmdate( 'Y-m-d' ) ) >= $daily ) {
			return true;
		}
		if ( $monthly > 0 && self::usage_requests_month( gmdate( 'Y-m' ) ) >= $monthly ) {
			return true;
		}
		return false;
	}

	/**
	 * Create embeddings for one or more input strings (embed role/provider).
	 *
	 * @param string[] $inputs Input texts.
	 * @return array{vectors:array<int,float[]>}|WP_Error
	 */
	public static function embed( array $inputs ) {
		if ( empty( $inputs ) ) {
			return array( 'vectors' => array() );
		}
		$provider = Ks_Concierge_Settings::get_provider( 'embed' );
		$model    = (string) Ks_Concierge_Settings::get( 'embeddings_model', 'text-embedding-3-small' );
		$dims     = (int) Ks_Concierge_Settings::get( 'embeddings_dims', 1536 );
		$body     = array(
			'model' => $model,
			'input' => array_values( $inputs ),
		);
		// The OpenAI-only "dimensions" parameter (text-embedding-3-*); other
		// providers use fixed-dimension models and reject it.
		if ( 'openai' === $provider && 0 === strpos( $model, 'text-embedding-3' ) && $dims > 0 && 1536 !== $dims ) {
			$body['dimensions'] = $dims;
		}
		$response = self::request( '/embeddings', $body, 'embed' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$vectors = array();
		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			foreach ( $response['data'] as $item ) {
				if ( isset( $item['embedding'] ) && is_array( $item['embedding'] ) ) {
					$vectors[] = array_map( 'floatval', $item['embedding'] );
				}
			}
		}
		$prompt = isset( $response['usage']['prompt_tokens'] )
			? (int) $response['usage']['prompt_tokens']
			: ( isset( $response['usage']['total_tokens'] ) ? (int) $response['usage']['total_tokens'] : 0 );
		self::record_usage( 'embed', $model, $prompt, 0, true );
		return array( 'vectors' => $vectors );
	}

	/**
	 * Call Chat Completions for the chat role/provider, requesting JSON output
	 * according to chat_structured_mode (auto|json_schema|json_object|none).
	 *
	 * @param array  $messages Chat messages.
	 * @param array  $schema   JSON schema definition (without name wrapper).
	 * @param string $name     Schema name.
	 * @return array|WP_Error Decoded object or WP_Error.
	 */
	public static function chat_structured( array $messages, array $schema, $name = 'ks_concierge_answer' ) {
		$provider = Ks_Concierge_Settings::get_provider( 'chat' );
		$model    = (string) Ks_Concierge_Settings::get( 'chat_model', 'gpt-4o-mini' );
		$mode     = (string) Ks_Concierge_Settings::get( 'chat_structured_mode', 'auto' );
		if ( 'auto' === $mode ) {
			$mode = ( 'openai' === $provider ) ? 'json_schema' : 'json_object';
		}

		$body = array(
			'model'    => $model,
			'messages' => $messages,
		);
		if ( 'json_schema' === $mode ) {
			$body['response_format'] = array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => $name,
					'strict' => true,
					'schema' => $schema,
				),
			);
		} elseif ( 'json_object' === $mode ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}
		// 'none': no response_format; the prompt instructs JSON-only output and
		// the caller post-validates candidate URLs and forces a fallback when no
		// valid candidate is returned.

		$response = self::request( '/chat/completions', $body, 'chat' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$prompt     = isset( $response['usage']['prompt_tokens'] ) ? (int) $response['usage']['prompt_tokens'] : 0;
		$completion = isset( $response['usage']['completion_tokens'] ) ? (int) $response['usage']['completion_tokens'] : 0;
		self::record_usage( 'chat', $model, $prompt, $completion, true );

		if ( ! empty( $response['choices'][0]['message']['refusal'] ) ) {
			return new WP_Error( 'ks_concierge_refusal', __( 'The model declined to answer.', 'kashiwazaki-seo-concierge' ) );
		}
		$content = isset( $response['choices'][0]['message']['content'] ) ? $response['choices'][0]['message']['content'] : '';
		$parsed  = self::decode_json_lenient( (string) $content );
		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'ks_concierge_parse', __( 'Could not parse the AI response.', 'kashiwazaki-seo-concierge' ) );
		}
		return $parsed;
	}

	/**
	 * Decode a JSON object that may be wrapped in prose or a code fence (common
	 * with non-strict providers). Returns null when no object can be decoded.
	 *
	 * @param string $content Raw model content.
	 * @return array|null
	 */
	protected static function decode_json_lenient( $content ) {
		$content = trim( $content );
		$direct  = json_decode( $content, true );
		if ( is_array( $direct ) ) {
			return $direct;
		}
		$start = strpos( $content, '{' );
		$end   = strrpos( $content, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$slice  = substr( $content, $start, $end - $start + 1 );
			$parsed = json_decode( $slice, true );
			if ( is_array( $parsed ) ) {
				return $parsed;
			}
		}
		return null;
	}

	/**
	 * Perform an authenticated POST request to the role's provider endpoint.
	 *
	 * @param string $endpoint Path beginning with a slash.
	 * @param array  $body     Request payload.
	 * @param string $role     embed|chat.
	 * @return array|WP_Error Decoded JSON array on success.
	 */
	protected static function request( $endpoint, array $body, $role ) {
		// Hard cost guard at the single HTTP chokepoint: no matter which caller
		// (or buggy/looping code path) reaches here, a tripped daily/monthly cap
		// or token backstop blocks the paid request before it is sent.
		if ( self::is_breaker_open( $role ) ) {
			return new WP_Error( 'ks_concierge_cost_limit', __( 'AI spending limit reached; request blocked.', 'kashiwazaki-seo-concierge' ) );
		}

		$provider = Ks_Concierge_Settings::get_provider( $role );
		$base     = Ks_Concierge_Settings::get_api_base( $role );
		$key      = Ks_Concierge_Settings::get_api_key( $role );
		if ( '' === $key && ! Ks_Concierge_Settings::is_local_free( $role ) ) {
			return new WP_Error( 'ks_concierge_no_key', __( 'AI API key is not configured.', 'kashiwazaki-seo-concierge' ) );
		}

		$default_timeout = ( 'embed' === $role ) ? 30 : 60;
		/**
		 * Filter the AI request timeout (seconds). Local models may need longer
		 * on first load.
		 *
		 * @param int    $timeout Default timeout.
		 * @param string $role    embed|chat.
		 */
		$timeout = (int) apply_filters( 'ks_concierge_ai_timeout', $default_timeout, $role );

		$headers = array( 'Content-Type' => 'application/json' );
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		$args = array(
			'timeout' => $timeout,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		);

		$attempts = 0;
		$result   = null;
		while ( $attempts < 2 ) {
			$attempts++;
			// Count every actual call attempt against the call-count cap, before
			// the send, so failed responses (4xx/5xx) and retries also count and
			// cannot bypass the cap in a failure loop.
			self::record_request();
			$result = wp_remote_post( $base . $endpoint, $args );
			if ( ! is_wp_error( $result ) ) {
				$code = (int) wp_remote_retrieve_response_code( $result );
				if ( $code >= 200 && $code < 300 ) {
					break;
				}
				if ( $code >= 400 && $code < 500 && 429 !== $code ) {
					return new WP_Error( 'ks_concierge_http_' . $code, self::error_message( $result ) );
				}
			}
			if ( $attempts < 2 ) {
				usleep( 400000 );
			}
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$code = (int) wp_remote_retrieve_response_code( $result );
		$data = json_decode( wp_remote_retrieve_body( $result ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new WP_Error( 'ks_concierge_http_' . $code, self::error_message( $result ) );
		}
		return $data;
	}

	/**
	 * Extract a human-readable error message from a response.
	 *
	 * @param array|WP_Error $result HTTP result.
	 * @return string
	 */
	protected static function error_message( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		$data = json_decode( wp_remote_retrieve_body( $result ), true );
		if ( isset( $data['error']['message'] ) ) {
			return (string) $data['error']['message'];
		}
		return __( 'AI API request failed.', 'kashiwazaki-seo-concierge' );
	}

	/**
	 * Record token usage and write-time estimated cost for the current UTC day.
	 *
	 * Cost is computed in nano-dollars (1e-9 USD) integers at the price in effect
	 * now, so it is exact for tiny requests and correct across mid-period price
	 * or provider changes.
	 *
	 * @param string $role              embed|chat.
	 * @param string $model             Model id (empty when unknown/error).
	 * @param int    $prompt_tokens     Input (prompt) tokens.
	 * @param int    $completion_tokens Output (completion) tokens.
	 * @param bool   $has_usage         Whether token counts were received.
	 * @return void
	 */
	/**
	 * Increment the call-count for today by one. Called for every actual API
	 * call attempt (including failed responses and retries) so the call-count
	 * cap cannot be bypassed by a loop of failing requests.
	 *
	 * @return void
	 */
	protected static function record_request() {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_usage';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day, requests) VALUES (%s, 1) ON DUPLICATE KEY UPDATE requests = requests + 1",
				gmdate( 'Y-m-d' )
			)
		);
	}

	protected static function record_usage( $role, $model, $prompt_tokens, $completion_tokens, $has_usage ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_usage';
		$day   = gmdate( 'Y-m-d' );

		// Local/free providers (Ollama) are exempt from cost and token caps, so
		// neither cost nor tokens are accumulated for them.
		$local_free = Ks_Concierge_Settings::is_local_free( $role );

		$cost_nano = 0;
		if ( $has_usage && '' !== $model && ! $local_free ) {
			$price = self::price_for( $role, $model );
			if ( is_array( $price ) && empty( $price['free'] ) ) {
				$usd       = ( ( $prompt_tokens / 1000 ) * $price['in'] ) + ( ( $completion_tokens / 1000 ) * $price['out'] );
				$cost_nano = (int) round( $usd * 1000000000 );
			}
		}
		$count_tokens = ( $has_usage && ! $local_free );
		$total        = (int) $prompt_tokens + (int) $completion_tokens;
		$embed_tokens = ( 'embed' === $role && $count_tokens ) ? $total : 0;
		$chat_tokens  = ( 'chat' === $role && $count_tokens ) ? $total : 0;
		$embed_nano   = ( 'embed' === $role ) ? $cost_nano : 0;
		$chat_nano    = ( 'chat' === $role ) ? $cost_nano : 0;
		$est_cost     = $cost_nano / 1000000000;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day, embed_tokens, chat_tokens, requests, est_cost_usd, cost_embed_nano, cost_chat_nano)
				 VALUES (%s, %d, %d, 0, %f, %d, %d)
				 ON DUPLICATE KEY UPDATE
				 embed_tokens = embed_tokens + VALUES(embed_tokens),
				 chat_tokens = chat_tokens + VALUES(chat_tokens),
				 est_cost_usd = est_cost_usd + VALUES(est_cost_usd),
				 cost_embed_nano = cost_embed_nano + VALUES(cost_embed_nano),
				 cost_chat_nano = cost_chat_nano + VALUES(cost_chat_nano)",
				$day,
				(int) $embed_tokens,
				(int) $chat_tokens,
				$est_cost,
				(int) $embed_nano,
				(int) $chat_nano
			)
		);
		// phpcs:enable
	}

	/**
	 * Total estimated cost (USD) for a single UTC day, from nano-dollar columns.
	 *
	 * @param string $day Y-m-d.
	 * @return float
	 */
	public static function usage_cost( $day ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_usage';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$val = $wpdb->get_var( $wpdb->prepare( "SELECT (cost_embed_nano + cost_chat_nano) FROM {$table} WHERE day = %s", $day ) );
		return (float) $val / 1000000000;
	}

	/**
	 * Total estimated cost (USD) for a UTC month, from nano-dollar columns.
	 *
	 * @param string $month Y-m.
	 * @return float
	 */
	public static function usage_cost_month( $month ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_usage';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$val = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(cost_embed_nano + cost_chat_nano) FROM {$table} WHERE day LIKE %s", $wpdb->esc_like( $month ) . '%' ) );
		return (float) $val / 1000000000;
	}

	/**
	 * Total number of paid API calls (embeddings + chat) for a single UTC day.
	 *
	 * @param string $day Y-m-d.
	 * @return int
	 */
	public static function usage_requests( $day ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_usage';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$val = $wpdb->get_var( $wpdb->prepare( "SELECT requests FROM {$table} WHERE day = %s", $day ) );
		return (int) $val;
	}

	/**
	 * Total number of paid API calls (embeddings + chat) for a UTC month.
	 *
	 * @param string $month Y-m.
	 * @return int
	 */
	public static function usage_requests_month( $month ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_usage';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$val = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(requests) FROM {$table} WHERE day LIKE %s", $wpdb->esc_like( $month ) . '%' ) );
		return (int) $val;
	}

	/**
	 * Role token total for a single UTC day.
	 *
	 * @param string $role embed|chat.
	 * @param string $day  Y-m-d.
	 * @return int
	 */
	public static function usage_tokens( $role, $day ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'ks_concierge_usage';
		$column = ( 'embed' === $role ) ? 'embed_tokens' : 'chat_tokens';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$val = $wpdb->get_var( $wpdb->prepare( "SELECT {$column} FROM {$table} WHERE day = %s", $day ) );
		return (int) $val;
	}

	/**
	 * Role token total for a UTC month.
	 *
	 * @param string $role  embed|chat.
	 * @param string $month Y-m.
	 * @return int
	 */
	public static function usage_tokens_month( $role, $month ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'ks_concierge_usage';
		$column = ( 'embed' === $role ) ? 'embed_tokens' : 'chat_tokens';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$val = $wpdb->get_var( $wpdb->prepare( "SELECT SUM({$column}) FROM {$table} WHERE day LIKE %s", $wpdb->esc_like( $month ) . '%' ) );
		return (int) $val;
	}
}
