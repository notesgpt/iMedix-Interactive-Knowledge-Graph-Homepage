<?php
/**
 * Plugin settings with validated defaults.
 *
 * Credentials are never stored here: the optional Google Sheets API key is
 * read from the IMEDIX_KU_SHEETS_API_KEY constant (wp-config.php) or the
 * environment variable of the same name.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\WordPress;

final class Settings {

	public const OPTION = 'imedix_ku_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// Google Sheet "iMedix Drug Remediation — Master Tracker".
			'spreadsheet_id'    => '1KLBtihRPuRrwAn9VB0a9lVcyg9z-kjZAqvg4UWO0Ivk',
			'source_mode'       => 'csv_export', // csv_export | sheets_api.
			'gid_drugs'         => '975154414',
			'gid_diseases'      => '77683610',
			'gid_redirects'     => '383967448',
			'title_drugs'       => 'Drugs',
			'title_diseases'    => 'Diseases',
			'title_redirects'   => 'Drug redirects 301-410',
			'sheet_host'        => 'www.imedix.com',
			'base_drug'         => '/drugs/',
			'base_disease'      => '/diseases/',
			'validate_wp'       => true,
			'scan_links'        => true,
			'sync_recurrence'   => 'twicedaily',
			'max_drop_ratio'    => 0.5,
			'news_post_type'    => 'news',
			'news_count'        => 12,
			'news_refresh_secs' => 600,
			'graph_budget'      => 640,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Sanitise a submitted settings array.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$d   = self::defaults();
		$in  = is_array( $input ) ? $input : array();
		$out = array();

		$id                    = isset( $in['spreadsheet_id'] ) ? (string) $in['spreadsheet_id'] : $d['spreadsheet_id'];
		$out['spreadsheet_id'] = preg_match( '/^[A-Za-z0-9_-]{20,100}$/', $id ) ? $id : $d['spreadsheet_id'];
		$out['source_mode']    = in_array( $in['source_mode'] ?? '', array( 'csv_export', 'sheets_api' ), true ) ? $in['source_mode'] : $d['source_mode'];

		foreach ( array( 'gid_drugs', 'gid_diseases', 'gid_redirects' ) as $k ) {
			$v         = isset( $in[ $k ] ) ? (string) $in[ $k ] : $d[ $k ];
			$out[ $k ] = ctype_digit( $v ) ? $v : $d[ $k ];
		}
		foreach ( array( 'title_drugs', 'title_diseases', 'title_redirects' ) as $k ) {
			$v         = isset( $in[ $k ] ) ? sanitize_text_field( (string) $in[ $k ] ) : $d[ $k ];
			$out[ $k ] = '' !== $v ? $v : $d[ $k ];
		}
		foreach ( array( 'base_drug', 'base_disease' ) as $k ) {
			$v         = isset( $in[ $k ] ) ? (string) $in[ $k ] : $d[ $k ];
			$out[ $k ] = preg_match( '#^/[a-z0-9-]+(/[a-z0-9-]+)*/$#', $v ) ? $v : $d[ $k ];
		}
		$host              = isset( $in['sheet_host'] ) ? strtolower( trim( (string) $in['sheet_host'] ) ) : $d['sheet_host'];
		$out['sheet_host'] = preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $host ) ? $host : $d['sheet_host'];
		$out['validate_wp']     = ! empty( $in['validate_wp'] );
		$out['scan_links']      = ! empty( $in['scan_links'] );
		$out['sync_recurrence'] = in_array( $in['sync_recurrence'] ?? '', array( 'hourly', 'twicedaily', 'daily' ), true ) ? $in['sync_recurrence'] : $d['sync_recurrence'];
		$out['max_drop_ratio']  = isset( $in['max_drop_ratio'] ) ? max( 0.05, min( 1.0, (float) $in['max_drop_ratio'] ) ) : $d['max_drop_ratio'];

		$pt                       = isset( $in['news_post_type'] ) ? sanitize_key( (string) $in['news_post_type'] ) : $d['news_post_type'];
		$out['news_post_type']    = '' !== $pt ? $pt : $d['news_post_type'];
		$out['news_count']        = isset( $in['news_count'] ) ? max( 4, min( 20, (int) $in['news_count'] ) ) : $d['news_count'];
		$out['news_refresh_secs'] = isset( $in['news_refresh_secs'] ) ? max( 120, min( 3600, (int) $in['news_refresh_secs'] ) ) : $d['news_refresh_secs'];
		$out['graph_budget']      = isset( $in['graph_budget'] ) ? max( 100, min( 1500, (int) $in['graph_budget'] ) ) : $d['graph_budget'];

		return $out;
	}

	public static function api_key(): string {
		if ( defined( 'IMEDIX_KU_SHEETS_API_KEY' ) ) {
			return (string) constant( 'IMEDIX_KU_SHEETS_API_KEY' );
		}
		$env = getenv( 'IMEDIX_KU_SHEETS_API_KEY' );
		return false === $env ? '' : (string) $env;
	}
}
