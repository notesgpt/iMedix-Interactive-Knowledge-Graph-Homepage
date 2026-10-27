<?php
/**
 * Signed knowledge-graph claims from the theme's Disease Registry (seeded from
 * the iMedix KG packages: MONDO conditions, FDA SPL sources, verified
 * relations). Read-only.
 *
 * The registry's own gates decide what may be shown: every claim passes
 * through Imedix_DR_GraphPanel::view() — the same code that renders the panel
 * on each disease page — so the homepage never shows a claim, brand, link or
 * source that the disease page itself would not. Nothing is inferred here.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\WordPress;

final class KgSource {

	/** Registry purpose → graph predicate. "maintenance" is maintenance treatment. */
	private const PREDICATES = array(
		'treatment'      => 'treats',
		'maintenance'    => 'treats',
		'prevention'     => 'prevents',
		'risk_reduction' => 'reduces_risk_of',
		'symptom_relief' => 'relieves_symptoms_of',
	);

	/**
	 * @return array<int, array<string, mixed>>|null Claims, or null when the registry is not installed.
	 */
	public static function claims(): ?array {
		if ( ! class_exists( 'Imedix_DR_Repository' ) || ! class_exists( 'Imedix_DR_GraphPanel' ) || ! class_exists( 'Imedix_DR_MedicationsBlock' ) || ! class_exists( 'Imedix_DR_Schema' ) ) {
			return null;
		}
		global $wpdb;
		$pages = $wpdb->get_col(
			'SELECT DISTINCT pc.post_id FROM ' . \Imedix_DR_Schema::table( 'post_conditions' ) . " pc
			 JOIN {$wpdb->posts} p ON p.ID = pc.post_id WHERE p.post_status = 'publish'"
		);
		if ( ! is_array( $pages ) ) {
			return null;
		}
		$host   = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$claims = array();
		foreach ( $pages as $post_id ) {
			$post_id = (int) $post_id;
			$rows    = \Imedix_DR_Repository::panelRows( $post_id );
			$view    = $rows ? \Imedix_DR_GraphPanel::view( $rows ) : null;
			if ( ! $view ) {
				continue;
			}
			// What the disease page shows, by subject key ("i315" substance, "p123" product).
			$shown = array();
			foreach ( $view['groups'] as $group ) {
				foreach ( $group['nodes'] as $node ) {
					$shown[ $node['subject'] ][] = $node;
				}
			}
			$page_path = self::path( (string) get_permalink( $post_id ), $host );
			foreach ( $rows as $r ) {
				$claim     = \Imedix_DR_MedicationsBlock::claim( $r );
				$predicate = self::PREDICATES[ (string) ( $r['purpose'] ?? '' ) ] ?? null;
				$kind      = 'ingredient' === ( $r['subject_type'] ?? '' ) ? 'i' : ( 'product' === ( $r['subject_type'] ?? '' ) ? 'p' : '' );
				$subject   = $kind . (int) ( $r['subject_id'] ?? 0 );
				if ( null === $claim || null === $predicate || '' === $kind || null === $page_path ) {
					continue;
				}
				$node = null;
				foreach ( $shown[ $subject ] ?? array() as $candidate ) {
					if ( in_array( $claim, $candidate['claims'], true ) ) {
						$node = $candidate;
						break;
					}
				}
				if ( null === $node ) {
					continue; // The disease page does not show this claim: neither do we.
				}
				$sources = self::sources( $r, $node );
				if ( ! $sources ) {
					continue;
				}
				$brands = array();
				foreach ( $node['products'] as $product ) {
					$path = self::path( (string) ( $product['url'] ?? '' ), $host );
					if ( null !== $path ) {
						$brands[] = array(
							'name' => (string) $product['title'],
							'path' => $path,
						);
					}
				}
				$claims[] = array(
					'page'      => $page_path,
					'condition' => array(
						'id'   => (int) $r['condition_id'],
						'name' => trim( (string) ( '' !== (string) ( $r['condition_patient_name'] ?? '' ) ? $r['condition_patient_name'] : $r['condition_name'] ) ),
						'role' => (string) ( $r['page_role'] ?? '' ),
					),
					'subject'   => array(
						'kind' => 'i' === $kind ? 'substance' : 'product',
						'id'   => (int) $r['subject_id'],
						'name' => (string) $node['name'],
						'path' => self::path( (string) $node['url'], $host ),
					),
					'predicate' => $predicate,
					'qualifier' => (string) ( \Imedix_DR_MedicationsBlock::qualifier( $r ) ?? '' ),
					'claim'     => $claim,
					'sources'   => $sources,
					'brands'    => $brands,
				);
			}
		}
		return $claims;
	}

	/**
	 * Current US FDA labels behind one relation that the page also shows.
	 *
	 * @param array<string, mixed> $r    Registry row.
	 * @param array<string, mixed> $node GraphPanel node.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sources( array $r, array $node ): array {
		$shown = array_column( $node['sources'], 'url' );
		$out   = array();
		foreach ( (array) ( $r['sources_all'] ?? array() ) as $s ) {
			$url = 'https://dailymed.nlm.nih.gov/dailymed/lookup.cfm?setid=' . (string) ( $s['source_setid'] ?? '' );
			if ( ! in_array( $url, $shown, true ) ) {
				continue;
			}
			$out[] = array(
				'setid'       => (string) $s['source_setid'],
				'version'     => (int) $s['source_version'],
				'effective'   => (string) $s['source_effective'],
				'application' => (string) ( $s['source_application'] ?? '' ),
				'title'       => trim( explode( ' — ', (string) ( $s['source_title'] ?? '' ) )[0] ),
				'url'         => $url,
			);
		}
		return $out;
	}

	private static function path( string $url, string $host ): ?string {
		if ( '' === $url || $host !== (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			return null;
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? trailingslashit( $path ) : null;
	}
}
