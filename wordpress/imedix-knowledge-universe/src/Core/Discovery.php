<?php
/**
 * Selects the server-rendered, crawlable discovery content from the client
 * graph: editorial topic lists and search suggestions.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\Core;

final class Discovery {

	/**
	 * Topic sections with a bounded number of links each.
	 *
	 * @param array<string, mixed> $graph Client graph (ClientPayload::from_graph format).
	 * @return array<int, array{id:string, name:string, total:int, members: array<int, array{name:string, path:string, type:string}>}>
	 */
	public static function topics( array $graph, int $per_topic = 6, int $max_topics = 24 ): array {
		$nodes = self::index( $graph );
		$members = array();
		foreach ( $graph['edges'] ?? array() as $edge ) {
			if ( 'in_topic' === ( $edge[2] ?? '' ) && isset( $nodes[ $edge[0] ], $nodes[ $edge[1] ] ) ) {
				$members[ $edge[1] ][] = $nodes[ $edge[0] ];
			}
		}

		$topics = array();
		foreach ( $members as $topic_id => $list ) {
			usort( $list, array( self::class, 'rank' ) );
			$topics[] = array(
				'id'      => $topic_id,
				'name'    => (string) $nodes[ $topic_id ]['n'],
				'total'   => count( $list ),
				'members' => array_map(
					static fn( $n ) => array(
						'name' => (string) $n['n'],
						'path' => (string) $n['p'],
						'type' => (string) $n['t'],
					),
					array_slice( $list, 0, $per_topic )
				),
			);
		}
		usort(
			$topics,
			static fn( $a, $b ) => ( $b['total'] <=> $a['total'] ) ?: strcmp( $a['name'], $b['name'] )
		);
		return array_slice( $topics, 0, $max_topics );
	}

	/**
	 * Most recently updated guides (by the editorial date in the tracker) to
	 * suggest under the search box. No popularity data exists, so none is implied.
	 *
	 *
	 * @param array<string, mixed> $graph Client graph.
	 * @return array<int, array{name:string, path:string, type:string}>
	 */
	public static function suggestions( array $graph, int $count = 4 ): array {
		$by_type = array(
			'disease' => array(),
			'drug'    => array(),
		);
		foreach ( self::index( $graph ) as $n ) {
			if ( isset( $by_type[ $n['t'] ] ) && 'completed' === ( $n['s'] ?? '' ) && ! empty( $n['p'] ) ) {
				$by_type[ $n['t'] ][] = $n;
			}
		}
		$out = array();
		foreach ( $by_type as $type => $list ) {
			usort(
				$list,
				static fn( $a, $b ) => strcmp( (string) ( $b['u'] ?? '' ), (string) ( $a['u'] ?? '' ) ) ?: self::rank( $a, $b )
			);
			foreach ( array_slice( $list, 0, (int) ceil( $count / 2 ) ) as $n ) {
				$out[] = array(
					'name' => (string) $n['n'],
					'path' => (string) $n['p'],
					'type' => $type,
				);
			}
		}
		return array_slice( $out, 0, $count );
	}

	/**
	 * @param array<string, mixed> $graph
	 * @return array<string, array<string, mixed>>
	 */
	private static function index( array $graph ): array {
		$nodes = array();
		foreach ( $graph['nodes'] ?? array() as $n ) {
			if ( isset( $n['id'], $n['t'], $n['n'] ) ) {
				$nodes[ (string) $n['id'] ] = $n;
			}
		}
		return $nodes;
	}

	/**
	 * Diseases before drugs, completed before pending, better connected first, then A–Z.
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	private static function rank( array $a, array $b ): int {
		$type = ( 'disease' === $b['t'] ) <=> ( 'disease' === $a['t'] );
		$done = ( 'completed' === ( $b['s'] ?? '' ) ) <=> ( 'completed' === ( $a['s'] ?? '' ) );
		return $type ?: $done ?: ( (int) $b['d'] <=> (int) $a['d'] ) ?: strcmp( (string) $a['n'], (string) $b['n'] );
	}
}
