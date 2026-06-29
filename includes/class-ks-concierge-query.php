<?php
/**
 * Core question-answering pipeline for Kashiwazaki SEO Concierge: embed the
 * question, find candidate pages by cosine similarity, ask the chat model to
 * compose guidance with strict Structured Outputs, and attach self-computed
 * score/lastmod metadata.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_Query
 */
class Ks_Concierge_Query {

	const SCORE_THRESHOLD = 0.35;

	/**
	 * Cache helper.
	 *
	 * @var Ks_Concierge_Cache
	 */
	protected $cache;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->cache = new Ks_Concierge_Cache();
	}

	/**
	 * Answer a visitor question.
	 *
	 * @param string $question     Sanitized question.
	 * @param string $session_hash Visitor session hash.
	 * @param bool   $consent      Whether the visitor consented to logging.
	 * @return array{answer:string,candidates:array,fallback:bool,source:string}
	 */
	public function answer( $question, $session_hash, $consent = true ) {
		$lang = $this->detect_lang( $question );

		// 1. Answer cache.
		$cached = $this->get_cached_answer( $question );
		if ( null !== $cached ) {
			// Still log cache hits so analytics are not undercounted.
			Ks_Concierge_Analytics::log(
				array(
					'question'     => $question,
					'answer'       => isset( $cached['answer'] ) ? (string) $cached['answer'] : '',
					'matched_urls' => wp_list_pluck( isset( $cached['candidates'] ) ? $cached['candidates'] : array(), 'url' ),
					'top_score'    => 0,
					'lang'         => $lang,
					'answered'     => empty( $cached['fallback'] ),
					'session_hash' => $session_hash,
					'consent'      => $consent,
				)
			);
			return $cached;
		}

		// 2. Cost circuit breaker / missing key -> graceful fallback. /ask uses
		// both roles: the embed provider (question vector) and the chat provider
		// (answer). Either being unavailable falls back gracefully.
		if ( ! Ks_Concierge_OpenAI::has_key( 'embed' ) || ! Ks_Concierge_OpenAI::has_key( 'chat' )
			|| Ks_Concierge_OpenAI::is_breaker_open( 'embed' ) || Ks_Concierge_OpenAI::is_breaker_open( 'chat' ) ) {
			return $this->fallback_response( $question, $session_hash, $lang, 'unavailable', $consent );
		}

		// 3. Embed the question with the active model/dims.
		$embed = Ks_Concierge_OpenAI::embed( array( $question ) );
		if ( is_wp_error( $embed ) || empty( $embed['vectors'][0] ) ) {
			return $this->fallback_response( $question, $session_hash, $lang, 'embed_error', $consent );
		}
		$normalized = Ks_Concierge_Embeddings::normalize( $embed['vectors'][0] );
		if ( null === $normalized ) {
			return $this->fallback_response( $question, $session_hash, $lang, 'embed_error', $consent );
		}

		// 4. Cosine similarity search.
		$top_n   = (int) Ks_Concierge_Settings::get( 'candidate_count', 10 );
		$top_n   = max( 1, min( 20, $top_n ) );
		$matches = Ks_Concierge_Embeddings::search( $normalized, $top_n, $lang );

		if ( empty( $matches ) || $matches[0]['score'] < self::SCORE_THRESHOLD ) {
			return $this->low_match_response( $question, $session_hash, $lang, $consent );
		}

		$page_ids = wp_list_pluck( $matches, 'page_id' );
		$pages    = $this->cache->get_pages_by_ids( $page_ids );
		$score_by = array();
		foreach ( $matches as $m ) {
			$score_by[ $m['page_id'] ] = $m['score'];
		}

		$candidate_pages = array();
		foreach ( $matches as $m ) {
			if ( isset( $pages[ $m['page_id'] ] ) ) {
				$candidate_pages[] = $pages[ $m['page_id'] ];
			}
		}
		if ( empty( $candidate_pages ) ) {
			return $this->low_match_response( $question, $session_hash, $lang, $consent );
		}

		// 5. Compose guidance with the chat model.
		$llm = $this->compose_answer( $question, $candidate_pages, $lang );
		if ( is_wp_error( $llm ) ) {
			return $this->fallback_response( $question, $session_hash, $lang, 'chat_error', $consent );
		}

		// 6. Attach self-computed score and lastmod; keep only valid candidate URLs.
		$by_url = array();
		foreach ( $candidate_pages as $page ) {
			$by_url[ $page->url ] = $page;
		}
		$candidates = array();
		$llm_cands  = isset( $llm['candidates'] ) && is_array( $llm['candidates'] ) ? $llm['candidates'] : array();
		foreach ( $llm_cands as $cand ) {
			$url = isset( $cand['url'] ) ? $cand['url'] : '';
			if ( ! isset( $by_url[ $url ] ) ) {
				continue;
			}
			$page         = $by_url[ $url ];
			$candidates[] = array(
				'url'     => $url,
				'title'   => isset( $cand['title'] ) && '' !== $cand['title'] ? $cand['title'] : $page->title,
				'reason'  => isset( $cand['reason'] ) ? $cand['reason'] : '',
				'score'   => isset( $score_by[ (int) $page->id ] ) ? round( (float) $score_by[ (int) $page->id ], 4 ) : null,
				'lastmod' => $page->lastmod,
			);
			if ( count( $candidates ) >= $top_n ) {
				break;
			}
		}

		// With non-strict providers the model may answer without selecting any
		// valid candidate URL. Treat zero valid candidates as a fallback and do
		// not cache the low-confidence answer.
		if ( empty( $candidates ) ) {
			return $this->low_match_response( $question, $session_hash, $lang, $consent );
		}

		$response = array(
			'answer'     => isset( $llm['answer'] ) ? (string) $llm['answer'] : '',
			'candidates' => $candidates,
			'fallback'   => false,
			'source'     => 'ai',
		);

		$this->store_cached_answer( $question, $response );
		Ks_Concierge_Analytics::log(
			array(
				'question'     => $question,
				'answer'       => $response['answer'],
				'matched_urls' => wp_list_pluck( $candidates, 'url' ),
				'top_score'    => $matches[0]['score'],
				'lang'         => $lang,
				'answered'     => ! empty( $candidates ),
				'session_hash' => $session_hash,
				'consent'      => $consent,
			)
		);

		return $response;
	}

	/**
	 * Ask the chat model to compose an answer constrained to candidate URLs.
	 *
	 * @param string             $question Question.
	 * @param array<int,object>  $pages    Candidate page rows.
	 * @param string             $lang     Detected language.
	 * @return array|WP_Error
	 */
	protected function compose_answer( $question, array $pages, $lang ) {
		$urls  = array();
		$lines = array();
		foreach ( $pages as $page ) {
			$urls[]  = $page->url;
			$lines[] = '- ' . $page->title . ' (' . $page->url . '): ' . wp_trim_words( (string) $page->summary, 40, '' );
		}

		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'answer', 'candidates' ),
			'properties'           => array(
				'answer'     => array( 'type' => 'string' ),
				'candidates' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'url', 'title', 'reason' ),
						'properties'           => array(
							'url'    => array(
								'type' => 'string',
								'enum' => array_values( $urls ),
							),
							'title'  => array( 'type' => 'string' ),
							'reason' => array( 'type' => 'string' ),
						),
					),
				),
			),
		);

		$system = (string) Ks_Concierge_Settings::get( 'system_prompt', '' );
		if ( '' === trim( $system ) ) {
			$system = $this->default_system_prompt();
		}
		$system .= "\n" . __( 'Only recommend pages from the provided candidate list. Never invent URLs. If none are relevant, return an empty candidates array. Reply in the same language as the question.', 'kashiwazaki-seo-concierge' );
		// The base prompt is in English and candidate titles/URLs often contain
		// English, which makes the model drift to English on short inputs. Pin the
		// reply language explicitly when the question is detected as Japanese.
		$system .= "\n" . $this->language_directive( $lang );
		// Ensure non-strict providers (json_object/none) emit parseable JSON of
		// the expected shape; the strict-schema path enforces this natively.
		$system .= "\n" . __( 'Respond with a single JSON object only, no prose or code fences, in the form {"answer": string, "candidates": [{"url": string, "title": string, "reason": string}]}. Each url must be exactly one of the candidate URLs above.', 'kashiwazaki-seo-concierge' );

		$user = __( 'Question:', 'kashiwazaki-seo-concierge' ) . ' ' . $question . "\n\n"
			. __( 'Candidate pages:', 'kashiwazaki-seo-concierge' ) . "\n" . implode( "\n", $lines );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);

		return Ks_Concierge_OpenAI::chat_structured( $messages, $schema );
	}

	/**
	 * Default system prompt honoring the selected template.
	 *
	 * @return string
	 */
	protected function default_system_prompt() {
		return __( 'You are a helpful website concierge. Guide the visitor to the most relevant page on this site based only on the provided candidates. Be concise and factual; do not make claims that are not supported by a candidate page.', 'kashiwazaki-seo-concierge' );
	}

	/**
	 * Build a fallback response (no AI answer available).
	 *
	 * @param string $question     Question.
	 * @param string $session_hash Session hash.
	 * @param string $lang         Language.
	 * @param string $reason       Internal reason code.
	 * @param bool   $consent      Whether the visitor consented to logging.
	 * @return array
	 */
	/**
	 * Response for a message with no good page match: greetings, small talk and
	 * off-topic questions. Instead of a cold "not found" notice, ask the chat
	 * model for a short, friendly concierge reply (no page recommendations). Falls
	 * back to the canned notice only if the AI reply is unavailable.
	 *
	 * @param string $question     Visitor question.
	 * @param string $session_hash Visitor session hash.
	 * @param string $lang         Detected language.
	 * @param bool   $consent      Whether the visitor consented to logging.
	 * @return array
	 */
	protected function low_match_response( $question, $session_hash, $lang, $consent = true ) {
		$reply = $this->conversational_reply( $question, $lang );
		if ( null === $reply || '' === $reply ) {
			return $this->fallback_response( $question, $session_hash, $lang, 'low_score', $consent );
		}

		$response = array(
			'answer'     => $reply,
			'candidates' => array(),
			'fallback'   => true,
			'source'     => 'chat',
		);
		// Cache so recurring greetings ("こんにちは" 等) do not spend a call each time.
		$this->store_cached_answer( $question, $response );
		Ks_Concierge_Analytics::log(
			array(
				'question'     => $question,
				'answer'       => $reply,
				'matched_urls' => array(),
				'top_score'    => 0,
				'lang'         => $lang,
				'answered'     => false,
				'session_hash' => $session_hash,
				'consent'      => $consent,
			)
		);
		return $response;
	}

	/**
	 * Ask the chat model for a brief, friendly concierge reply when the visitor's
	 * message does not match a page (greeting / small talk / out-of-scope). The
	 * model is instructed not to invent pages or facts and to steer the visitor
	 * toward this site's topic.
	 *
	 * @param string $question Visitor question.
	 * @param string $lang     Detected language.
	 * @return string|null Reply text, or null when unavailable.
	 */
	/**
	 * Explicit reply-language instruction for the system prompt. The base prompt
	 * is English, so detected Japanese needs a hard directive to stop the model
	 * drifting to English; other languages keep the "match the visitor" rule.
	 *
	 * @param string $lang Detected language code ('ja' or '').
	 * @return string
	 */
	protected function language_directive( $lang ) {
		if ( 'ja' === $lang ) {
			return __( '回答は必ず自然な日本語で書いてください。英語にしないでください。', 'kashiwazaki-seo-concierge' );
		}
		return __( 'Reply strictly in the same language the visitor used.', 'kashiwazaki-seo-concierge' );
	}

	protected function conversational_reply( $question, $lang ) {
		$site   = (string) get_bloginfo( 'name' );
		$system = sprintf(
			/* translators: %s: site name. */
			__( 'You are a friendly concierge chatbot for the website "%s". The visitor sent a message that does not match any specific page on this site. If it is a greeting or small talk, reply warmly and briefly, then invite them to ask about the site\'s topics. If they asked about something this site does not cover, politely say your role is to help with this site\'s content and suggest they ask a related question or use the site search. Keep it to 1-3 short, natural sentences. Always reply in the same language as the visitor. Never invent URLs, facts, or page recommendations.', 'kashiwazaki-seo-concierge' ),
			'' !== $site ? $site : 'this website'
		);
		$system  .= "\n" . $this->language_directive( $lang );
		$messages = array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $question ),
		);
		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'answer', 'candidates' ),
			'properties'           => array(
				'answer'     => array( 'type' => 'string' ),
				'candidates' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		);
		$llm = Ks_Concierge_OpenAI::chat_structured( $messages, $schema, 'ks_concierge_chat' );
		if ( is_wp_error( $llm ) || empty( $llm['answer'] ) ) {
			return null;
		}
		return (string) $llm['answer'];
	}

	protected function fallback_response( $question, $session_hash, $lang, $reason, $consent = true ) {
		$pages       = $this->cache->get_fallback_pages( 3 );
		$candidates  = array();
		foreach ( $pages as $page ) {
			$candidates[] = array(
				'url'     => $page->url,
				'title'   => $page->title,
				'reason'  => '',
				'score'   => null,
				'lastmod' => $page->lastmod,
			);
		}
		// Only mention the suggested pages when there actually are some; otherwise
		// the bare "you might also like" phrasing would point at nothing.
		if ( empty( $candidates ) ) {
			$answer = __( '該当するページが見つかりませんでした。サイト内検索やお問い合わせをご利用ください。', 'kashiwazaki-seo-concierge' );
		} else {
			$answer = __( '該当するページが見つかりませんでした。こちらのページが参考になるかもしれません。サイト内検索やお問い合わせもご利用ください。', 'kashiwazaki-seo-concierge' );
		}

		Ks_Concierge_Analytics::log(
			array(
				'question'     => $question,
				'answer'       => $answer,
				'matched_urls' => wp_list_pluck( $candidates, 'url' ),
				'top_score'    => 0,
				'lang'         => $lang,
				'answered'     => false,
				'session_hash' => $session_hash,
				'consent'      => $consent,
			)
		);

		return array(
			'answer'     => $answer,
			'candidates' => $candidates,
			'fallback'   => true,
			'source'     => $reason,
		);
	}

	/**
	 * Normalize a question for cache keying.
	 *
	 * @param string $question Question.
	 * @return string
	 */
	protected function normalize_question( $question ) {
		$q = function_exists( 'mb_strtolower' ) ? mb_strtolower( $question ) : strtolower( $question );
		$q = preg_replace( '/\s+/u', ' ', $q );
		return trim( (string) $q );
	}

	/**
	 * Cache key hash for a question, bound to the current embedding signature and
	 * chat model so a provider/model change does not serve answers composed in a
	 * previous embedding space or by a different model.
	 *
	 * @param string $question Question.
	 * @return string
	 */
	protected function cache_hash( $question ) {
		$sig   = Ks_Concierge_Embeddings::current_embed_sig();
		$model = (string) Ks_Concierge_Settings::get( 'chat_model', 'gpt-4o-mini' );
		return hash( 'sha256', $this->normalize_question( $question ) . '|' . $sig . '|' . $model );
	}

	/**
	 * Get a cached answer for a question, if not expired.
	 *
	 * @param string $question Question.
	 * @return array|null
	 */
	protected function get_cached_answer( $question ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ks_concierge_cache';
		$hash  = $this->cache_hash( $question );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT answer_json, expires_at FROM {$table} WHERE q_norm_hash = %s", $hash ) );
		if ( ! $row ) {
			return null;
		}
		if ( $row->expires_at && strtotime( $row->expires_at ) < time() ) {
			return null;
		}
		$data = json_decode( (string) $row->answer_json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$data['source'] = 'cache';
		return $data;
	}

	/**
	 * Store an answer in the cache with a TTL.
	 *
	 * @param string $question Question.
	 * @param array  $response Answer payload.
	 * @return void
	 */
	protected function store_cached_answer( $question, array $response ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'ks_concierge_cache';
		$hash    = $this->cache_hash( $question );
		$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (q_norm_hash, question_norm, answer_json, created_at, expires_at)
				 VALUES (%s, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE answer_json = VALUES(answer_json), created_at = VALUES(created_at), expires_at = VALUES(expires_at)",
				$hash,
				$this->normalize_question( $question ),
				wp_json_encode( $response ),
				current_time( 'mysql', true ),
				$expires
			)
		);
		// phpcs:enable
	}

	/**
	 * Detect the language of a question (best effort: Japanese vs other).
	 *
	 * @param string $question Question.
	 * @return string
	 */
	protected function detect_lang( $question ) {
		if ( preg_match( '/[\x{3040}-\x{30ff}\x{4e00}-\x{9fff}]/u', $question ) ) {
			return 'ja';
		}
		return '';
	}
}
