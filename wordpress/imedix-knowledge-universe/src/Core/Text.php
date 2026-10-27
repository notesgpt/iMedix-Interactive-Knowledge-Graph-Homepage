<?php
/**
 * Slug, label and URL helpers shared by the sync pipeline.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\Core;

final class Text {

	/** Tokens always rendered upper-case in fallback display names. */
	private const UPPER = array( 'er', 'xr', 'xl', 'sr', 'dm', 'pe', 'hct', 'od', 'pm', 'ii', 'iii', 'iv', 'ud', 'cr', 'la', 'hd', 'ds', 'xt', 'ped', 'odt' );

	/** Medical acronyms that appear as trailing slug tokens, e.g. "…-disease-gerd". */
	private const ACRONYMS = array( 'gerd', 'copd', 'pms', 'cvi', 'ed', 'tb', 'uti', 'hiv', 'adhd', 'ibs', 'ocd', 'bph', 'pcos', 'ptsd', 'rsv', 'sti', 'std', 'ckd', 'chf', 'aud' );

	/** Tokens with fixed casing. */
	private const FIXED = array(
		'hcl'  => 'HCl',
		'hbr'  => 'HBr',
		'ml'   => 'mL',
		'mg'   => 'mg',
		'mcg'  => 'mcg',
		'and'  => 'and',
		'of'   => 'of',
		'the'  => 'the',
		'with' => 'with',
		'for'  => 'for',
		'in'   => 'in',
		'as'   => 'as',
		'to'   => 'to',
		'h'    => 'H.',
	);

	/** Eponyms written without an apostrophe in slugs. */
	private const POSSESSIVE = array(
		'raynauds'   => "Raynaud's",
		'parkinsons' => "Parkinson's",
		'alzheimers' => "Alzheimer's",
		'hashimotos' => "Hashimoto's",
		'sjogrens'   => "Sjögren's",
		'bartholins' => "Bartholin's",
		'crohns'     => "Crohn's",
		'addisons'   => "Addison's",
		'graves'     => 'Graves',
		'womans'     => "Woman's",
		'mens'       => "Men's",
	);

	/**
	 * Validate a WordPress-style slug.
	 */
	public static function is_slug( string $slug ): bool {
		return 1 === preg_match( '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $slug ) && strlen( $slug ) <= 200;
	}

	/**
	 * Produce a readable fallback name from a slug.
	 *
	 * Only used when WordPress titles are unavailable (offline builds). In
	 * production the WordPress post title always wins.
	 */
	public static function humanize_slug( string $slug ): string {
		$tokens = array_values( array_filter( explode( '-', strtolower( $slug ) ), 'strlen' ) );
		if ( ! $tokens ) {
			return '';
		}

		// Trailing acronym that repeats the initials of the preceding words,
		// e.g. "chronic-obstructive-pulmonary-disease-copd" → "… (COPD)".
		$acronym = null;
		$count   = count( $tokens );
		if ( $count >= 2 ) {
			$last = $tokens[ $count - 1 ];
			$len  = strlen( $last );
			if ( $len >= 2 && $len <= 5 ) {
				$initials = '';
				if ( $count - 1 >= $len ) {
					foreach ( array_slice( $tokens, $count - 1 - $len, $len ) as $t ) {
						$initials .= $t[0];
					}
				}
				$no_vowels = 0 === preg_match( '/[aeiouy]/', $last );
				if ( $initials === $last || in_array( $last, self::ACRONYMS, true ) || ( $no_vowels && ! in_array( $last, self::UPPER, true ) && ! isset( self::FIXED[ $last ] ) ) ) {
					$acronym = strtoupper( $last );
					array_pop( $tokens );
				}
			}
		}

		$words = array();
		foreach ( $tokens as $i => $t ) {
			if ( isset( self::POSSESSIVE[ $t ] ) ) {
				$words[] = self::POSSESSIVE[ $t ];
			} elseif ( isset( self::FIXED[ $t ] ) && ( 0 !== $i || 'h' === $t ) ) {
				$words[] = self::FIXED[ $t ];
			} elseif ( in_array( $t, self::UPPER, true ) ) {
				$words[] = strtoupper( $t );
			} elseif ( preg_match( '/^\d/', $t ) ) {
				$words[] = $t;
			} else {
				$words[] = ucfirst( $t );
			}
		}

		$name = implode( ' ', $words );
		$name = str_replace( 'H. Pylori', 'H. pylori', $name );
		return null === $acronym ? $name : $name . ' (' . $acronym . ')';
	}

	/**
	 * Normalise a URL or path to a site-relative path with leading and trailing slash.
	 * Returns null for URLs on other hosts or malformed input.
	 */
	public static function to_path( string $url_or_path, string $site_host ): ?string {
		$url_or_path = trim( $url_or_path );
		if ( '' === $url_or_path ) {
			return null;
		}
		if ( '/' === $url_or_path[0] && ( ! isset( $url_or_path[1] ) || '/' !== $url_or_path[1] ) ) {
			$path = $url_or_path;
		} else {
			$parts = parse_url( $url_or_path );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
				return null;
			}
			if ( ! in_array( strtolower( $parts['scheme'] ), array( 'https', 'http' ), true ) ) {
				return null;
			}
			if ( ! self::same_host( $parts['host'], $site_host ) ) {
				return null;
			}
			if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['port'] ) ) {
				return null;
			}
			$path = $parts['path'] ?? '/';
		}
		if ( 1 !== preg_match( '#^/[A-Za-z0-9/_\-]*$#', $path ) || false !== strpos( $path, '//' ) ) {
			return null;
		}
		return rtrim( $path, '/' ) . '/';
	}

	/**
	 * Host comparison ignoring a leading "www.".
	 */
	public static function same_host( string $a, string $b ): bool {
		$norm = static fn( string $h ): string => preg_replace( '/^www\./', '', strtolower( $h ) );
		return $norm( $a ) === $norm( $b );
	}

	/**
	 * Stable, URL-safe key for a label (used for topic IDs).
	 */
	public static function key( string $label ): string {
		$key = strtolower( $label );
		$key = str_replace( array( "'", '’' ), '', $key );
		$key = preg_replace( '/[^a-z0-9]+/', '-', $key );
		return trim( (string) $key, '-' );
	}

	/**
	 * Search-normalised form: lower-case ASCII words separated by single spaces.
	 */
	public static function fold( string $text ): string {
		$text = strtolower( $text );
		$text = strtr(
			$text,
			array(
				'ö' => 'o',
				'ü' => 'u',
				'ä' => 'a',
				'é' => 'e',
				'è' => 'e',
				'ç' => 'c',
				"'" => '',
				'’' => '',
			)
		);
		$text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
		return trim( (string) $text );
	}
}
