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
			if ( ! empty( $n['description'] ) ) {
				$c['x'] = $n['description'];
			}
			$nodes[] = $c;
		}
		$edges = array();
		foreach ( $graph['edges'] as $e ) {
			$edges[] = array( $e['source'], $e['target'], $e['type'] );
		}
		return array(
			'schema'      => 'imedix-ku/client@1',
			'generatedAt' => $graph['generatedAt'],
			'publication' => $graph['source']['publication'],
			'nodeTypes'   => $graph['nodeTypes'],
			'edgeTypes'   => $graph['edgeTypes'],
			'counts'      => $graph['counts'],
			'nodes'       => $nodes,
			'edges'       => $edges,
		);
	}
}
