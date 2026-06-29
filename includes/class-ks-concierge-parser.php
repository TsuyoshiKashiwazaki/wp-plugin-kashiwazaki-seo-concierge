<?php
/**
 * Data-source parsing for Kashiwazaki SEO Concierge: sitemap.xml, llms.txt
 * (lightweight Markdown subset) and per-URL metadata extraction.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_Parser
 */
class Ks_Concierge_Parser {

	/**
	 * Whether the last parse_sitemap() call could not fetch every part (e.g. a
	 * child sitemap in an index failed). Used to suppress reconciliation so a
	 * partial fetch does not deactivate pages from the missing part.
	 *
	 * @var bool
	 */
	public $sitemap_incomplete = false;

	/**
	 * Parse a sitemap.xml URL into a list of entries.
	 *
	 * Supports sitemap index files (one level of nesting).
	 *
	 * @param string $url Sitemap URL.
	 * @return array<int,array{url:string,lastmod:?string}>
	 */
	public function parse_sitemap( $url ) {
		$this->sitemap_incomplete = false;
		$entries                  = array();
		$xml                      = $this->fetch_xml( $url );
		if ( null === $xml ) {
			$this->sitemap_incomplete = true;
			return $entries;
		}

		if ( isset( $xml->sitemap ) ) {
			foreach ( $xml->sitemap as $sitemap ) {
				$loc = isset( $sitemap->loc ) ? esc_url_raw( trim( (string) $sitemap->loc ) ) : '';
				if ( '' === $loc ) {
					continue;
				}
				$child = $this->fetch_xml( $loc );
				if ( null === $child ) {
					// A child sitemap failed to load; the collection is partial.
					$this->sitemap_incomplete = true;
					continue;
				}
				$entries = array_merge( $entries, $this->extract_urlset( $child ) );
			}
			return $this->dedupe( $entries );
		}

		return $this->dedupe( $this->extract_urlset( $xml ) );
	}

	/**
	 * Extract <url> entries from a urlset SimpleXML node.
	 *
	 * @param SimpleXMLElement $xml Parsed XML.
	 * @return array<int,array{url:string,lastmod:?string}>
	 */
	protected function extract_urlset( $xml ) {
		$entries = array();
		if ( ! isset( $xml->url ) ) {
			return $entries;
		}
		foreach ( $xml->url as $node ) {
			$loc = isset( $node->loc ) ? esc_url_raw( trim( (string) $node->loc ) ) : '';
			if ( '' === $loc ) {
				continue;
			}
			$lastmod = isset( $node->lastmod ) ? $this->normalize_date( (string) $node->lastmod ) : null;
			$entries[] = array(
				'url'     => $loc,
				'lastmod' => $lastmod,
			);
		}
		return $entries;
	}

	/**
	 * Parse an llms.txt file (Markdown subset: H1 title, H2 sections, links with
	 * optional descriptions).
	 *
	 * @param string $url llms.txt URL.
	 * @return array<int,array{url:string,title:string,summary:string}>
	 */
	public function parse_llms_txt( $url ) {
		$body = $this->fetch_body( $url );
		if ( '' === $body ) {
			return array();
		}
		$entries = array();
		$section = '';
		$lines   = preg_split( '/\r\n|\r|\n/', $body );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^##\s+(.*)$/', $line, $m ) ) {
				$section = trim( $m[1] );
				continue;
			}
			// Markdown link list item: - [Title](URL): description
			if ( preg_match( '/\[([^\]]+)\]\(([^)]+)\)\s*:?\s*(.*)$/', $line, $m ) ) {
				$title = trim( $m[1] );
				$loc   = esc_url_raw( trim( $m[2] ) );
				$desc  = trim( $m[3] );
				if ( '' === $loc ) {
					continue;
				}
				$summary = $desc;
				if ( '' !== $section ) {
					$summary = '' === $desc ? $section : $section . ' — ' . $desc;
				}
				$entries[] = array(
					'url'     => $loc,
					'title'   => $title,
					'summary' => $summary,
				);
			}
		}
		return $entries;
	}

	/**
	 * Fetch and extract title/summary from a page URL.
	 *
	 * @param string $url Page URL.
	 * @return array{title:string,summary:string}|null Null on fetch failure.
	 */
	public function fetch_page_meta( $url ) {
		if ( ! $this->is_safe_url( $url ) ) {
			return null;
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'            => 15,
				'redirection'        => 2,
				'reject_unsafe_urls' => true,
				'user-agent'         => 'Kashiwazaki SEO Concierge/' . KS_CONCIERGE_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}
		$html = wp_remote_retrieve_body( $response );
		if ( '' === $html ) {
			return null;
		}
		$title = '';
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
			$title = wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}
		$summary = '';
		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/is', $html, $m ) ) {
			$summary = wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}
		if ( '' === $summary ) {
			$summary = $this->extract_text_excerpt( $html );
		}
		return array(
			'title'   => trim( $title ),
			'summary' => trim( $summary ),
		);
	}

	/**
	 * Build a short plain-text excerpt from page HTML body.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	protected function extract_text_excerpt( $html ) {
		$html = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', ' ', $html );
		$html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', ' ', $html );
		$text = wp_strip_all_tags( $html );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );
		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, 300 );
		} else {
			$text = substr( $text, 0, 300 );
		}
		return $text;
	}

	/**
	 * Fetch a URL body as a string.
	 *
	 * @param string $url URL.
	 * @return string Empty string on failure.
	 */
	protected function fetch_body( $url ) {
		if ( ! $this->is_safe_url( $url ) ) {
			return '';
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'            => 15,
				'redirection'        => 2,
				'reject_unsafe_urls' => true,
				'user-agent'         => 'Kashiwazaki SEO Concierge/' . KS_CONCIERGE_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return '';
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Fetch and parse a URL as XML.
	 *
	 * @param string $url URL.
	 * @return SimpleXMLElement|null
	 */
	protected function fetch_xml( $url ) {
		$body = $this->fetch_body( $url );
		if ( '' === $body ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return ( false === $xml ) ? null : $xml;
	}

	/**
	 * Guard against SSRF: only allow http(s) URLs that do not resolve to
	 * private, loopback or reserved IP ranges.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	protected function is_safe_url( $url ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return false;
		}
		/**
		 * Filter whether a data-source URL host is allowed to be fetched.
		 *
		 * @param bool   $allowed Whether the host is allowed.
		 * @param string $host    Host name.
		 */
		if ( false === apply_filters( 'ks_concierge_allow_fetch_host', true, $host ) ) {
			return false;
		}
		// Always allow the site's own host, so the plugin can index its own
		// sitemap/llms.txt on localhost, staging or intranet installs even when
		// that host resolves to a private/loopback address.
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! empty( $site_host ) && strtolower( $host ) === strtolower( $site_host ) ) {
			return true;
		}
		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
		// gethostbyname returns the input unchanged on resolution failure.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Normalize a date string to MySQL DATETIME (UTC) or null.
	 *
	 * @param string $value Date string.
	 * @return string|null
	 */
	protected function normalize_date( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Deduplicate entries by URL, keeping the first lastmod seen.
	 *
	 * @param array<int,array{url:string,lastmod:?string}> $entries Entries.
	 * @return array<int,array{url:string,lastmod:?string}>
	 */
	protected function dedupe( $entries ) {
		$seen = array();
		$out  = array();
		foreach ( $entries as $entry ) {
			if ( isset( $seen[ $entry['url'] ] ) ) {
				continue;
			}
			$seen[ $entry['url'] ] = true;
			$out[]                 = $entry;
		}
		return $out;
	}
}
