<?php
/**
 * Redirect map built from the "Drug redirects 301-410" worksheet and the
 * Diseases "Target (301)" column.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\Core;

final class Redirects {

	private const MAX_HOPS = 5;

	/** @var array<string, array{code:int, target:?string, row:string}> */
	private array $map = array();

	/** @var array<int, array<string, string>> */
	private array $issues = array();

	/**
	 * Register a redirect. Later duplicates are logged and ignored.
	 */
	public function add( string $from, int $code, ?string $to, string $row ): void {
		if ( isset( $this->map[ $from ] ) ) {
			$this->issues[] = array(
				'row'    => $row,
				'path'   => $from,
				'reason' => 'duplicate_redirect',
			);
			return;
		}
		$this->map[ $from ] = array(
			'code'   => $code,
			'target' => $to,
			'row'    => $row,
		);
	}

	public function has( string $path ): bool {
		return isset( $this->map[ $path ] );
	}

	/**
	 * @return array{code:int, target:?string, row:string}|null
	 */
	public function get( string $path ): ?array {
		return $this->map[ $path ] ?? null;
	}

	/**
	 * Follow a redirect chain to its final destination.
	 *
	 * @return array{status:string, path:?string, hops:int}
	 *   status: 'none' (path is not redirected), 'ok', 'gone' (410), 'loop', 'too_long', 'invalid'.
	 */
	public function resolve( string $path ): array {
		if ( ! isset( $this->map[ $path ] ) ) {
			return array(
				'status' => 'none',
				'path'   => $path,
				'hops'   => 0,
			);
		}
		$seen    = array( $path => true );
		$current = $path;
		for ( $hops = 1; $hops <= self::MAX_HOPS; $hops++ ) {
			$entry = $this->map[ $current ];
			if ( 410 === $entry['code'] ) {
				return array(
					'status' => 'gone',
					'path'   => null,
					'hops'   => $hops,
				);
			}
			if ( null === $entry['target'] ) {
				return array(
					'status' => 'invalid',
					'path'   => null,
					'hops'   => $hops,
				);
			}
			$current = $entry['target'];
			if ( isset( $seen[ $current ] ) ) {
				return array(
					'status' => 'loop',
					'path'   => null,
					'hops'   => $hops,
				);
			}
			if ( ! isset( $this->map[ $current ] ) ) {
				return array(
					'status' => 'ok',
					'path'   => $current,
					'hops'   => $hops,
				);
			}
			$seen[ $current ] = true;
		}
		return array(
			'status' => 'too_long',
			'path'   => null,
			'hops'   => self::MAX_HOPS,
		);
	}

	/**
	 * @return string[] All redirected source paths.
	 */
	public function sources(): array {
		return array_keys( $this->map );
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	public function issues(): array {
		return $this->issues;
	}
}
