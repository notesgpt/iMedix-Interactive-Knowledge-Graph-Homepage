<?php
/**
 * Server-side reader for the tracker worksheets.
 *
 * Two modes:
 *  - csv_export (default): the sheet's "anyone with the link can view" CSV
 *    export. No credentials at all.
 *  - sheets_api: Google Sheets API v4 values:batchGet with an API key defined
 *    in wp-config.php (IMEDIX_KU_SHEETS_API_KEY). The key never reaches the
 *    browser and is never stored in the database.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\WordPress;

use Imedix\KnowledgeUniverse\Core\Csv;

final class SheetSource {

	private const TIMEOUT = 20;

	/**
	 * Fetch all three worksheets.
	 *
	 * @return array{drugs: array, diseases: array, redirects: ?array}
	 * @throws \RuntimeException On any failure of a required worksheet.
	 */
	public static function fetch_all(): array {
		$s = Settings::all();
		if ( 'sheets_api' === $s['source_mode'] ) {
			return self::fetch_api( $s );
		}
		$redirects = null;
		try {
			$redirects = self::fetch_csv( $s['spreadsheet_id'], $s['gid_redirects'] );
		} catch ( \RuntimeException $e ) {
			// Optional worksheet: the builder logs its absence.
			$redirects = null;
		}
		return array(
			'drugs'     => self::fetch_csv( $s['spreadsheet_id'], $s['gid_drugs'] ),
			'diseases'  => self::fetch_csv( $s['spreadsheet_id'], $s['gid_diseases'] ),
			'redirects' => $redirects,
		);
	}

	/**
	 * @return array{header: string[], rows: array<int, string[]>}
	 */
	private static function fetch_csv( string $spreadsheet_id, string $gid ): array {
		$url  = sprintf(
			'https://docs.google.com/spreadsheets/d/%s/export?format=csv&gid=%s',
			rawurlencode( $spreadsheet_id ),
			rawurlencode( $gid )
		);
		$body = self::get( $url, 'text/csv' );
		return Csv::parse( $body );
	}

	/**
	 * @param array<string, mixed> $s Settings.
	 * @return array{drugs: array, diseases: array, redirects: ?array}
	 */
	private static function fetch_api( array $s ): array {
		$key = Settings::api_key();
		if ( '' === $key ) {
			throw new \RuntimeException( 'sheets_api mode selected but IMEDIX_KU_SHEETS_API_KEY is not defined.' );
		}
		$titles = array( $s['title_drugs'], $s['title_diseases'], $s['title_redirects'] );
		$query  = implode(
			'&',
			array_map( static fn( $t ) => 'ranges=' . rawurlencode( "'" . str_replace( "'", "''", $t ) . "'" ), $titles )
		);
		$url    = sprintf(
			'https://sheets.googleapis.com/v4/spreadsheets/%s/values:batchGet?%s&majorDimension=ROWS&valueRenderOption=FORMATTED_VALUE&key=%s',
			rawurlencode( $s['spreadsheet_id'] ),
			$query,
			rawurlencode( $key )
		);
		$data   = json_decode( self::get( $url, 'application/json' ), true );
		if ( ! is_array( $data ) || empty( $data['valueRanges'] ) || ! is_array( $data['valueRanges'] ) ) {
			throw new \RuntimeException( 'Unexpected Sheets API response.' );
		}
		$tables = array();
		foreach ( $data['valueRanges'] as $i => $range ) {
			$values = $range['values'] ?? array();
			if ( ! is_array( $values ) || ! $values ) {
				$tables[ $i ] = null;
				continue;
			}
			$header       = array_map( static fn( $c ) => trim( (string) $c ), array_shift( $values ) );
			$rows         = array();
			foreach ( $values as $row ) {
				$cells = array_map( static fn( $c ) => trim( (string) $c ), (array) $row );
				if ( '' !== implode( '', $cells ) ) {
					$rows[] = $cells;
				}
			}
			$tables[ $i ] = array(
				'header' => $header,
				'rows'   => $rows,
			);
		}
		if ( empty( $tables[0] ) || empty( $tables[1] ) ) {
			throw new \RuntimeException( 'Drugs or Diseases worksheet is empty or missing.' );
		}
		return array(
			'drugs'     => $tables[0],
			'diseases'  => $tables[1],
			'redirects' => $tables[2] ?? null,
		);
	}

	private static function get( string $url, string $expect_type ): string {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 3,
				'limit_response_size' => Csv::MAX_BYTES,
				'headers'             => array( 'Accept' => $expect_type ),
				'user-agent'          => 'iMedix-Knowledge-Universe/' . IMEDIX_KU_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Request failed: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			// Never echo the URL: in API mode it contains the key.
			throw new \RuntimeException( 'Upstream responded with HTTP ' . $code . '.' );
		}
		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( false === stripos( $type, $expect_type ) ) {
			// Typical for a sheet that is no longer shared: Google serves an HTML sign-in page.
			throw new \RuntimeException( 'Unexpected content type "' . sanitize_text_field( $type ) . '" (is the sheet still shared by link?).' );
		}
		return (string) wp_remote_retrieve_body( $response );
	}
}
