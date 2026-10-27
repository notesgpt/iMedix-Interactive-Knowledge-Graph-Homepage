<?php
/**
 * Resolves the tracker's free-text "Cluster" (Diseases), "Disease Category"
 * and "Batch" (Drugs) columns to the fixed iMedix category list.
 *
 * These are editorial groupings maintained by the iMedix team. They are
 * navigation aids, never clinical relationships.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\Core;

final class Topics {

	/**
	 * The fixed iMedix category list. "Other" is recognised in the tracker but
	 * never used as a topic (see parse()).
	 */
	public const CATEGORIES = array(
		'Erectile Dysfunction',
		'Allergies',
		'Anti Fungal',
		'Anti Viral',
		'Antibiotics',
		'Anxiety',
		'Arthritis',
		'Asthma',
		'Birth Control',
		'Blood Pressure',
		'Cholesterol Lowering',
		'Depression',
		'Diabetes',
		'Gastrointestinal',
		'Hair Loss',
		'Heart Disease',
		'Herbals',
		"Men's Health",
		'Muscle Relaxant',
		'Other',
		'Pain Relief',
		'Skincare',
		'Sleep Aid',
		'Quit Smoking',
		'Weight Loss',
		"Women's Health",
	);

	/**
	 * Tracker spellings that differ from the category names. Documented in
	 * docs/DATA-PIPELINE.md.
	 */
	private const ALIASES = array(
		'ed'                => 'Erectile Dysfunction',
		'heart'             => 'Heart Disease',
		'cardiovascular'    => 'Heart Disease',
		'analgesics'        => 'Pain Relief',
		"men's"             => "Men's Health",
		"woman's"           => "Women's Health",
		"woman's health"    => "Women's Health", // Tracker spelling.
		'antifungal'        => 'Anti Fungal',
		'antiviral'         => 'Anti Viral',
		'hiv'               => 'Anti Viral',
		'hepatitis c'       => 'Anti Viral',
		'cholesterol'       => 'Cholesterol Lowering',
		'smoking cessation' => 'Quit Smoking',
		'contraception'     => 'Birth Control',
	);

	/**
	 * Resolves a tracker label to a category, or '' when it is not one.
	 */
	public static function category( string $label ): string {
		$norm = strtolower( trim( (string) preg_replace( '/\s+/', ' ', str_replace( '’', "'", $label ) ) ) );
		if ( isset( self::ALIASES[ $norm ] ) ) {
			return self::ALIASES[ $norm ];
		}
		foreach ( self::CATEGORIES as $cat ) {
			if ( strtolower( $cat ) === $norm ) {
				return $cat;
			}
		}
		return '';
	}

	/**
	 * "Other" and unrecognised labels give no category: a catch-all is not a
	 * grouping, is never shown on the homepage and never relates two guides.
	 *
	 * @return string[] Categories (deduplicated, in source order).
	 */
	public static function parse( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}
		// "Batch A - Cardiovascular" → "Cardiovascular".
		$raw = (string) preg_replace( '/^batch\s+[a-z0-9]+\s*[-–:]\s*/i', '', $raw );

		// A parenthesis may name the real category: "Other (Antiviral / Hepatitis C)".
		// Editorial annotations — "(v1)", "(hub)", "(rename → …)" — match nothing and are dropped.
		$noted = array();
		if ( preg_match_all( '/\(([^)]*)\)/u', $raw, $m ) ) {
			foreach ( $m[1] as $inner ) {
				foreach ( preg_split( '#\s*/\s*#', $inner ) as $part ) {
					$cat = self::category( $part );
					if ( '' !== $cat ) {
						$noted[ $cat ] = true;
					}
				}
			}
		}
		$raw = (string) preg_replace( '/\([^)]*\)/u', '', $raw );

		$labels = array();
		foreach ( preg_split( '#\s*/\s*#', $raw ) as $part ) {
			if ( '' === trim( $part ) ) {
				continue;
			}
			$cat = self::category( $part );
			if ( '' !== $cat ) {
				$labels[ $cat ] = true;
			}
		}
		$labels += $noted;
		unset( $labels['Other'] );
		return array_keys( $labels );
	}
}
