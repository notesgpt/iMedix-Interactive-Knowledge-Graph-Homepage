<?php
/**
 * Parses the tracker's free-text "Cluster" (Diseases) and "Batch" (Drugs)
 * columns into editorial topic labels.
 *
 * These are editorial work groupings maintained by the iMedix team. They are
 * navigation aids, never clinical relationships.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\Core;

final class Topics {

	/**
	 * Explicit label normalisation. Kept deliberately small and documented in
	 * docs/DATA-PIPELINE.md; anything not listed is used verbatim.
	 */
	private const ALIASES = array(
		'ed'             => 'Erectile Dysfunction',
		'heart'          => 'Heart Disease',
		"men's"          => "Men's Health",
		"woman's health" => "Women's Health",
		"woman's"        => "Women's Health",
	);

	/** Labels that carry no navigational meaning. */
	private const IGNORED = array( 'other', '' );

	/**
	 * @return string[] Topic labels (deduplicated, in source order).
	 */
	public static function parse( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}
		// "Batch A - Cardiovascular" → "Cardiovascular".
		$raw = (string) preg_replace( '/^batch\s+[a-z0-9]+\s*[-–:]\s*/i', '', $raw );
		// Drop editorial annotations: "(v1)", "(hub)", "(rename → …)", "(linked from …)".
		$raw = (string) preg_replace( '/\([^)]*\)/u', '', $raw );

		$labels = array();
		foreach ( preg_split( '#\s*/\s*#', $raw ) as $part ) {
			$part = trim( (string) preg_replace( '/\s+/', ' ', $part ) );
			$norm = strtolower( str_replace( '’', "'", $part ) );
			if ( in_array( $norm, self::IGNORED, true ) ) {
				continue;
			}
			$label            = self::ALIASES[ $norm ] ?? str_replace( '’', "'", $part );
			$labels[ $label ] = true;
		}
		return array_keys( $labels );
	}
}
