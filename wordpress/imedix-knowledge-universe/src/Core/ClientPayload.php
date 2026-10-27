<?php
/**
 * Browser payload derived from the full graph.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\Core;

final class ClientPayload {

	/**
	 * Strip the graph to what the browser needs: site-relative paths, no
	 * internal bookkeeping.
	 *
	 * @param array<string, mixed> $graph
	 * @return array<string, mixed>
	 */
	public static function from_graph( array $graph ): array {
		$nodes = array();
		foreach ( $graph['nodes'] as $n ) {
			$c = array(
				'id'   => $n['id'],
				't'    => $n['type'],
				'n'    => $n['name'],
				'c'    => $n['category'],
				'd'    => $n['degree'],
			);
			if ( ! empty( $n['url'] ) ) {
				$c['p'] = (string) parse_url( $n['url'], PHP_URL_PATH );
			}
			if ( ! empty( $n['status']['editorial'] ) ) {
				$c['s'] = $n['status']['editorial'];
			}
			if ( ! empty( $n['status']['updated'] ) ) {
				$c['u'] = $n['status']['updated'];
			}
			if ( ! empty( $n['aliases'] ) ) {
				$c['a'] = $n['aliases'];
			}
			if ( ! empty( $n['actives'] ) ) {
				$c['g'] = implode( ' + ', $n['actives'] ); // Active ingredient(s), shown next to the brand.
				if ( array_filter( (array) ( $n['active_paths'] ?? array() ) ) ) {
					// [label, ingredient page path or ''] per ingredient.
					$c['gl'] = array_map( null, $n['actives'], (array) $n['active_paths'] );
				}
			}
			if ( false === ( $n['registered'] ?? true ) ) {
				$c['r'] = 0; // Unregistered product: search only, never featured.
			}
			if ( ! empty( $n['description'] ) ) {
				$c['x'] = $n['description'];
			}
			$nodes[] = $c;
		}
		$edges = array();
		foreach ( $graph['edges'] as $e ) {
			$edges[] = array( $e['source'], $e['target'], $e['type'] );
		}
		foreach ( self::kg_links( $graph['edges'], (array) ( $graph['kgSources'] ?? array() ) ) as $pair ) {
			// [medicine, disease page, 'kg', claim, FDA label URL or ''].
			$edges[] = array( $pair[0], $pair[1], 'kg', $pair[2], $pair[3] );
		}
		return array(
			'schema'      => 'imedix-ku/client@1',
			'generatedAt' => $graph['generatedAt'],
			'publication' => $graph['source']['publication'],
			'nodeTypes'   => $graph['nodeTypes'],
			'edgeTypes'   => $graph['edgeTypes'],
			'counts'      => $graph['counts'],
			'kgNote'      => (string) ( $graph['kgNote'] ?? '' ),
			'nodes'       => $nodes,
			'edges'       => $edges,
		);
	}

	/** KG claim types (subject → condition). */
	public const KG_PREDICATES = array( 'treats', 'prevents', 'reduces_risk_of', 'relieves_symptoms_of' );

	/**
	 * Convenience pairs for the homepage: a medicine guide → the disease page that
	 * covers a condition it is FDA-approved for. Read straight off the signed chain
	 * (substance or product → condition → described_on page; substance → has_brand
	 * brand); nothing new is claimed.
	 *
	 * Each pair carries the verbatim claim and the FDA label behind it. A brand is
	 * only ever shown with an NDA/BLA label: an ANDA label supports the substance,
	 * never a brand, so a brand backed only by ANDA evidence gets no source link.
	 *
	 * @param array<int, array<string, mixed>> $edges
	 * @param array<string, mixed>             $sources kgSources, keyed "setid_vN".
	 * @return array<int, array{0:string, 1:string, 2:string, 3:string}>
	 */
	public static function kg_links( array $edges, array $sources = array() ): array {
		$page   = array();
		$brands = array();
		$claims = array();
		foreach ( $edges as $e ) {
			if ( 'described_on' === $e['type'] ) {
				$page[ $e['source'] ][] = $e['target'];
			} elseif ( 'has_brand' === $e['type'] ) {
				$brands[ $e['source'] ][] = $e['target'];
			} elseif ( in_array( $e['type'], self::KG_PREDICATES, true ) ) {
				$claims[] = array( $e['source'], $e['target'], $e['claims'][0] ?? array() );
			}
		}
		$out = array();
		foreach ( $claims as [ $subject, $condition, $claim ] ) {
			$labels = array();
			foreach ( (array) ( $claim['supported_by'] ?? array() ) as $key ) {
				$src = (array) ( $sources[ $key ] ?? array() );
				if ( ! empty( $src['url'] ) ) {
					$labels[] = $src;
				}
			}
			$nda = array_values( array_filter( $labels, static fn( $l ) => 0 !== stripos( (string) ( $l['application'] ?? '' ), 'ANDA' ) ) );
			foreach ( $page[ $condition ] ?? array() as $disease ) {
				foreach ( array_merge( array( $subject ), $brands[ $subject ] ?? array() ) as $from ) {
					$url = $from === $subject ? (string) ( $labels[0]['url'] ?? '' ) : (string) ( $nda[0]['url'] ?? '' );
					if ( ! isset( $out[ $from . '>' . $disease ] ) || ( '' === $out[ $from . '>' . $disease ][3] && '' !== $url ) ) {
						$out[ $from . '>' . $disease ] = array( $from, $disease, (string) ( $claim['claim'] ?? '' ), $url );
					}
				}
			}
		}
		return array_values( $out );
	}
}
