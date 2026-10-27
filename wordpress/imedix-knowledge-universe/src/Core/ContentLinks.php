<?php
/**
 * Extracts internal links from WordPress post content.
 *
 * Produces the "links_to" edge type: an iMedix article that links to another
 * entity's article. This is an editorial navigation fact taken from published
 * content, not a clinical claim.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\Core;

final class ContentLinks {

	/** Ignore pathological documents. */
	private const MAX_HTML_BYTES = 2097152;

	/**
	 * @return string[] Unique site-relative paths ("/drugs/x/") linked from the HTML.
	 */
	public static function extract( string $html, string $site_host ): array {
		if ( '' === $html || strlen( $html ) > self::MAX_HTML_BYTES ) {
			return array();
		}
		if ( ! preg_match_all( '/<a\s[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is', $html, $m ) ) {
			return array();
		}
		$paths = array();
		foreach ( $m[2] as $href ) {
			$href = html_entity_decode( trim( $href ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			// Drop query string and fragment before normalising.
			$href = (string) preg_replace( '/[?#].*$/s', '', $href );
			$path = Text::to_path( $href, $site_host );
			if ( null !== $path ) {
				$paths[ $path ] = true;
			}
		}
		return array_keys( $paths );
	}
}
