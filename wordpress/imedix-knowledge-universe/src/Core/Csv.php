<?php
/**
 * Minimal, strict CSV reader for Google Sheets CSV exports.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\Core;

final class Csv {

	/** Hard cap on accepted input size (bytes). The tracker is ~150 KB. */
	public const MAX_BYTES = 5242880;

	/**
	 * Parse CSV text into a header row plus data rows.
	 *
	 * @param string $text Raw CSV.
	 * @return array{header: string[], rows: array<int, string[]>}
	 * @throws \InvalidArgumentException When input is empty or oversized.
	 */
	public static function parse( string $text ): array {
		if ( strlen( $text ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'CSV input exceeds size limit.' );
		}
		// Strip UTF-8 BOM.
		if ( 0 === strncmp( $text, "\xEF\xBB\xBF", 3 ) ) {
			$text = substr( $text, 3 );
		}
		if ( '' === trim( $text ) ) {
			throw new \InvalidArgumentException( 'CSV input is empty.' );
		}

		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $text );
		rewind( $handle );

		$header = null;
		$rows   = array();
		while ( false !== ( $cells = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
			if ( array( null ) === $cells ) {
				continue; // Blank line.
			}
			$cells = array_map( static fn( $c ) => trim( (string) $c ), $cells );
			if ( null === $header ) {
				$header = $cells;
				continue;
			}
			if ( '' === implode( '', $cells ) ) {
				continue;
			}
			$rows[] = $cells;
		}
		fclose( $handle );

		if ( null === $header ) {
			throw new \InvalidArgumentException( 'CSV input has no header row.' );
		}

		return array(
			'header' => $header,
			'rows'   => $rows,
		);
	}

	/**
	 * Map rows to associative arrays using canonical column keys.
	 *
	 * Column headers in the tracker carry annotations (e.g. "Note (Verified = …)"
	 * or "Target check (2026-10-18)"), so a column is matched when its header
	 * starts with the expected label, case-insensitively.
	 *
	 * @param array{header: string[], rows: array<int, string[]>} $table   Parsed CSV.
	 * @param array<string, string>                              $columns Canonical key => header label prefix.
	 * @param string[]                                           $required Canonical keys that must exist.
	 * @return array<int, array<string, string>> Rows keyed by canonical key; each row also gets '_row' (1-based sheet row).
	 * @throws \UnexpectedValueException When a required column is missing.
	 */
	public static function map_columns( array $table, array $columns, array $required ): array {
		$index = array();
		foreach ( $columns as $key => $label ) {
			foreach ( $table['header'] as $i => $header ) {
				if ( 0 === stripos( $header, $label ) ) {
					$index[ $key ] = $i;
					break;
				}
			}
		}
		$missing = array_diff( $required, array_keys( $index ) );
		if ( $missing ) {
			throw new \UnexpectedValueException( 'Missing required column(s): ' . implode( ', ', $missing ) );
		}

		$out = array();
		foreach ( $table['rows'] as $n => $cells ) {
			$row = array( '_row' => (string) ( $n + 2 ) );
			foreach ( $columns as $key => $label ) {
				$row[ $key ] = isset( $index[ $key ], $cells[ $index[ $key ] ] ) ? $cells[ $index[ $key ] ] : '';
			}
			$out[] = $row;
		}
		return $out;
	}
}
