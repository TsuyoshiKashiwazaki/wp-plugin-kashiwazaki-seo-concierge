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
	 * How far below the best match a candidate may score and still take part in
	 * the recency ordering.
	 *
	 * Sorting by date alone would let a barely related page win a "what's new"
	 * question just for having been touched yesterday. The window keeps the
	 * reordering inside the group of pages the question actually reached.
	 */
	const RECENCY_SCORE_WINDOW = 0.15;

	/**
	 * How many extra matches to pull before reordering a recency question.
	 *
	 * The newest page in a series is rarely its closest semantic match: a run of
	 * near identical monthly pages scores as one undifferentiated cluster, so the
	 * current month can sit well outside the candidate slots. Widening the pool
	 * costs one larger page read and nothing in prompt size, since only
	 * candidate_count entries are sent on.
	 */
	const RECENCY_POOL_MULTIPLIER = 3;

	/**
	 * Upper bound for the widened pool.
	 */
	const RECENCY_POOL_MAX = 60;

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
		// The admin sandbox exists to try the current settings, so it must not
		// read or write the shared answer cache: reading would show yesterday's
		// answer instead of the effect of a change, and writing would push an
		// administrator's trial answer to real visitors for up to a day.
		$use_cache = ( 'sandbox' !== $session_hash );

		// 1. Answer cache.
		$cached = $use_cache ? $this->get_cached_answer( $question ) : null;
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
		// Ask through availability_error() rather than testing the conditions
		// inline: it records why the role is unavailable, so a missing key or a
		// tripped cap reaches the settings screen instead of silently turning
		// every answer into the canned notice.
		$unavailable = false;
		foreach ( array( 'embed', 'chat' ) as $ai_role ) {
			if ( null !== Ks_Concierge_OpenAI::availability_error( $ai_role ) ) {
				$unavailable = true;
			}
		}
		if ( $unavailable ) {
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

		// 4. Cosine similarity search. A question about the newest content needs a
		// wider pool than the candidate slots: similarity cannot tell a series of
		// monthly pages apart, so the current month lands anywhere in the cluster.
		$top_n   = (int) Ks_Concierge_Settings::get( 'candidate_count', 10 );
		$top_n   = max( 1, min( 20, $top_n ) );
		$recency = $this->wants_recent( $question );
		$pool    = $recency ? min( self::RECENCY_POOL_MAX, $top_n * self::RECENCY_POOL_MULTIPLIER ) : $top_n;
		$matches = Ks_Concierge_Embeddings::search( $normalized, $pool, $lang );

		// Judged on the closest match, before any reordering: relevance decides
		// whether the site has an answer at all, recency only decides the order.
		if ( empty( $matches ) || $matches[0]['score'] < self::SCORE_THRESHOLD ) {
			return $this->low_match_response( $question, $session_hash, $lang, $consent );
		}

		$page_ids = wp_list_pluck( $matches, 'page_id' );
		$pages    = $this->cache->get_pages_by_ids( $page_ids );
		$score_by = array();
		foreach ( $matches as $m ) {
			$score_by[ $m['page_id'] ] = $m['score'];
		}

		$matches = $recency
			? $this->order_by_recency( $matches, $pages, $top_n )
			: array_slice( $matches, 0, $top_n );

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

		if ( $use_cache ) {
			$this->store_cached_answer( $question, $response );
		}
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
			$urls[] = $page->url;
			// Candidates used to carry no date at all. Retrieval ranks by semantic
			// similarity alone, so a series of near identical monthly pages reaches
			// the model as interchangeable and "the latest report" is answered with
			// whichever one happens to score highest. The last modified date is the
			// only signal that tells them apart.
			$lines[] = '- ' . $page->title . $this->lastmod_tag( $page ) . ' (' . $page->url . '): ' . wp_trim_words( (string) $page->summary, 40, '' );
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
		// Each candidate line ends its title with [YYYY-MM-DD]. Without saying what
		// the bracket means the model reads it as part of the title, so spell out
		// both the meaning and what to do with it when recency is asked for.
		$system .= "\n" . __( 'Each candidate is listed as "- Title [YYYY-MM-DD] (URL): summary", where the bracketed date is when that page was last updated. A candidate without a date has no known update date. When the question asks for the latest, newest, or most recent content, choose the relevant candidate with the most recent date rather than the first one listed, and mention that date in the answer.', 'kashiwazaki-seo-concierge' );
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
	 * Format a candidate's last modified date for the prompt.
	 *
	 * Sitemap entries may omit lastmod, and rows written before the column was
	 * populated can hold a zero date. Emitting an empty or bogus date would let
	 * the model rank a page as old (or new) on made up information, so those
	 * candidates go out without a date and the prompt explains the absence.
	 *
	 * @param object $page Page row.
	 * @return string Bracketed date with a leading space, or an empty string.
	 */
	protected function lastmod_tag( $page ) {
		$timestamp = $this->lastmod_timestamp( $page );
		if ( ! $timestamp ) {
			return '';
		}
		return ' [' . gmdate( 'Y-m-d', $timestamp ) . ']';
	}

	/**
	 * Last modified date of a page row as a timestamp.
	 *
	 * @param object $page Page row.
	 * @return int Timestamp, or 0 when the page carries no usable date.
	 */
	protected function lastmod_timestamp( $page ) {
		$lastmod = isset( $page->lastmod ) ? trim( (string) $page->lastmod ) : '';
		if ( '' === $lastmod || 0 === strpos( $lastmod, '0000-00-00' ) ) {
			return 0;
		}
		$timestamp = strtotime( $lastmod );
		return $timestamp ? (int) $timestamp : 0;
	}

	/**
	 * Whether the question is asking for the newest content.
	 *
	 * @param string $question Visitor question.
	 * @return bool
	 */
	protected function wants_recent( $question ) {
		$question = (string) $question;
		$markers  = array( '最新', '最近', '直近', '新着', '今月', '今週', '先月', '先週', '今年', '新しい順', '一番新しい', 'いちばん新しい', '最も新しい', 'もっとも新しい' );
		foreach ( $markers as $marker ) {
			if ( false !== strpos( $question, $marker ) ) {
				return true;
			}
		}
		// strtolower folds ASCII only, so multibyte input passes through untouched
		// and this works on hosts without the mbstring extension.
		$lower = strtolower( $question );
		foreach ( array( 'latest', 'newest', 'most recent', 'up to date', 'up-to-date', 'this month', 'last month', 'this week' ) as $marker ) {
			if ( false !== strpos( $lower, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reorder matches by last modified date for a question about recent content.
	 *
	 * Similarity ranks a run of near identical monthly pages as one flat cluster,
	 * so the current month can sit far outside the candidate slots and never
	 * reach the model at all. Ordering the cluster by date puts the newest entry
	 * in front while relevance still decides who is in the cluster.
	 *
	 * @param array<int,array> $matches Matches, best score first.
	 * @param array<int,object> $pages  Page rows keyed by id.
	 * @param int              $limit   How many matches to keep.
	 * @return array<int,array>
	 */
	protected function order_by_recency( array $matches, array $pages, $limit ) {
		if ( count( $matches ) < 2 ) {
			return $matches;
		}
		// The closest match keeps its slot. It is the page the question actually
		// hit, and for "the latest X" it is often the index listing every X —
		// worth offering next to the newest entry itself.
		$best  = array_shift( $matches );
		$floor = max( self::SCORE_THRESHOLD, (float) $best['score'] - self::RECENCY_SCORE_WINDOW );

		$dated = array();
		$rest  = array();
		foreach ( $matches as $position => $match ) {
			$page = isset( $pages[ $match['page_id'] ] ) ? $pages[ $match['page_id'] ] : null;
			$time = $page ? $this->lastmod_timestamp( $page ) : 0;
			// A page with no date cannot be ordered by one, and a page far below
			// the best score is only in the pool at all because the search was
			// widened. Both keep their similarity order behind the dated group
			// rather than being dropped.
			if ( ! $time || (float) $match['score'] < $floor ) {
				$rest[] = $match;
				continue;
			}
			$dated[] = array(
				'match'    => $match,
				'time'     => $time,
				'position' => $position,
			);
		}

		usort(
			$dated,
			static function ( $a, $b ) {
				if ( $a['time'] === $b['time'] ) {
					// Same date: fall back on relevance, which is the order the
					// search returned. Bulk regenerated archives share a date, so
					// this decides more rows than it looks like it should.
					return ( $a['position'] < $b['position'] ) ? -1 : 1;
				}
				return ( $a['time'] < $b['time'] ) ? 1 : -1;
			}
		);

		$ordered = array( $best );
		foreach ( $dated as $row ) {
			$ordered[] = $row['match'];
		}
		foreach ( $rest as $match ) {
			$ordered[] = $match;
		}
		return array_slice( $ordered, 0, $limit );
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
		// Skipped for the sandbox, whose trial answers must not reach visitors.
		if ( 'sandbox' !== $session_hash ) {
			$this->store_cached_answer( $question, $response );
		}
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
		// This reply is prose the widget prints as-is — it recommends no pages, so
		// there is nothing to structure. Asking for plain text keeps it working on
		// providers that ignore response_format (Ollama and other OpenAI-compatible
		// endpoints), which would otherwise answer in prose, fail JSON parsing and
		// drop every greeting to the canned "no page found" notice.
		$reply = Ks_Concierge_OpenAI::chat_text( $messages );
		if ( is_wp_error( $reply ) ) {
			return null;
		}
		$reply = trim( (string) $reply );
		return ( '' === $reply ) ? null : $reply;
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
	 * Everything that shapes an answer, as one signature string.
	 *
	 * Binding the cache key to only the model left the rest of the configuration
	 * outside it: changing the service, the endpoint, the tone instructions or
	 * the number of candidates kept returning yesterday's answer for up to a
	 * day, so the setting looked like it had done nothing.
	 *
	 * @return string
	 */
	protected function answer_signature() {
		$parts = array(
			Ks_Concierge_Embeddings::current_embed_sig(),
			Ks_Concierge_Settings::get_provider( 'chat' ),
			(string) Ks_Concierge_Settings::get_api_base( 'chat' ),
			(string) Ks_Concierge_Settings::get( 'chat_model', '' ),
			(string) Ks_Concierge_Settings::get( 'chat_structured_mode', 'auto' ),
			(string) Ks_Concierge_Settings::get( 'system_prompt', '' ),
			(string) Ks_Concierge_Settings::get( 'prompt_template', 'general' ),
			(string) Ks_Concierge_Settings::get( 'candidate_count', 10 ),
		);
		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Cache key hash for a question, bound to the full answer signature so no
	 * configuration change can leave stale answers in circulation.
	 *
	 * @param string $question Question.
	 * @return string
	 */
	protected function cache_hash( $question ) {
		$sig   = $this->answer_signature();
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
