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
	 * @return array<int, array{id:string, key:string, name:string, total:int, drugs:int, diseases:int, members: array<int, array{name:string, path:string, type:string}>}>
	 */
	public static function topics( array $graph, int $per_topic = 10, int $max_topics = 24 ): array {
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
				'key'     => substr( $topic_id, strlen( 'topic:' ) ),
				'total'   => count( $list ),
				'drugs'   => count( array_filter( $list, static fn( $n ) => 'drug' === $n['t'] ) ),
				'diseases' => count( array_filter( $list, static fn( $n ) => 'disease' === $n['t'] ) ),
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
	 * Health areas for the library: the tracker's editorial groupings (drug
	 * "Batch", disease "Cluster") merged into reader-facing areas, then enriched
	 * with the real links between published articles — a disease guide that links
	 * to a drug guide (or the reverse) brings that guide into the area. Nothing is
	 * inferred: every member comes from the tracker or from WordPress content.
	 * Areas with fewer than three guides are left out.
	 *
	 * @param array<string, mixed> $graph Client graph (ClientPayload::from_graph format).
	 * @return array<int, array{key:string, icon:string, name:string, total:int, drugs: array{count:int, items: array<int, array{name:string, path:string}>}, diseases: array{count:int, items: array<int, array{name:string, path:string}>}}>
	 */
	public static function areas( array $graph, int $per_type = 4, int $min_total = 3 ): array {
		$nodes = self::index( $graph );
		$topic_area = array();
		foreach ( self::AREAS as $key => $area ) {
			foreach ( $area[2] as $topic ) {
				$topic_area[ 'topic:' . $topic ] = $key;
			}
		}

		$members = array();
		$labels  = array();
		foreach ( $graph['edges'] ?? array() as $edge ) {
			if ( 'in_topic' !== ( $edge[2] ?? '' ) || ! isset( $nodes[ $edge[0] ], $nodes[ $edge[1] ] ) ) {
				continue;
			}
			$key = $topic_area[ $edge[1] ] ?? substr( (string) $edge[1], strlen( 'topic:' ) );
			if ( ! isset( self::AREAS[ $key ] ) ) {
				$labels[ $key ] = (string) $nodes[ $edge[1] ]['n']; // A new tracker grouping: shown as is.
			}
			$members[ $key ][ $edge[0] ] = true;
		}

		// Real article links between drugs and diseases (WordPress post_content).
		$links = array();
		foreach ( $graph['edges'] ?? array() as $edge ) {
			if ( 'links_to' === ( $edge[2] ?? '' ) && isset( $nodes[ $edge[0] ], $nodes[ $edge[1] ] ) && $nodes[ $edge[0] ]['t'] !== $nodes[ $edge[1] ]['t'] ) {
				$links[ $edge[0] ][ $edge[1] ] = true;
				$links[ $edge[1] ][ $edge[0] ] = true;
			}
		}
		foreach ( $members as $key => $set ) {
			foreach ( array_keys( $set ) as $id ) {
				foreach ( array_keys( $links[ $id ] ?? array() ) as $linked ) {
					$members[ $key ][ $linked ] = true;
				}
			}
		}

		$areas = array();
		foreach ( $members as $key => $set ) {
			$by_type = array(
				'drug'    => array(),
				'disease' => array(),
			);
			foreach ( array_keys( $set ) as $id ) {
				$n = $nodes[ $id ];
				if ( isset( $by_type[ $n['t'] ] ) && ! empty( $n['p'] ) ) {
					$by_type[ $n['t'] ][] = $n;
				}
			}
			$total = count( $by_type['drug'] ) + count( $by_type['disease'] );
			if ( $total < $min_total ) {
				continue;
			}
			// One type only: the card gets all its rows.
			$take = ( $by_type['drug'] && $by_type['disease'] ) ? $per_type : $per_type * 2;
			$list = static function ( array $items ) use ( $take ): array {
				usort( $items, array( self::class, 'rank' ) );
				return array(
					'count' => count( $items ),
					'items' => array_map(
						static fn( $n ) => array(
							'name' => (string) $n['n'],
							'path' => (string) $n['p'],
						),
						array_slice( $items, 0, $take )
					),
				);
			};
			$areas[] = array(
				'key'      => $key,
				'icon'     => self::AREAS[ $key ][1] ?? $key,
				'name'     => self::AREAS[ $key ][0] ?? $labels[ $key ],
				'total'    => $total,
				'drugs'    => $list( $by_type['drug'] ),
				'diseases' => $list( $by_type['disease'] ),
			);
		}
		usort( $areas, static fn( $a, $b ) => ( $b['total'] <=> $a['total'] ) ?: strcmp( $a['name'], $b['name'] ) );
		return $areas;
	}

	/**
	 * Reader-facing health areas: key => [label, icon, tracker groupings].
	 * Editorial navigation only (documented in docs/DATA-PIPELINE.md); it says
	 * nothing about which medicine treats which condition.
	 */
	private const AREAS = array(
		'heart'       => array( 'Heart & Blood Pressure', 'cardiovascular', array( 'cardiovascular', 'blood-pressure', 'heart-disease' ) ),
		'pain'        => array( 'Pain & Arthritis', 'analgesics', array( 'analgesics', 'pain-relief', 'arthritis' ) ),
		'diabetes'    => array( 'Diabetes', 'diabetes', array( 'diabetes' ) ),
		'infections'  => array( 'Infections', 'antibiotics', array( 'antibiotics' ) ),
		'mental'      => array( 'Mental Health', 'depression', array( 'depression' ) ),
		'womens'      => array( "Women's Health", 'womens-health', array( 'womens-health' ) ),
		'mens'        => array( "Men's & Sexual Health", 'mens-health', array( 'mens-health', 'erectile-dysfunction' ) ),
		'digestive'   => array( 'Digestive Health', 'gastrointestinal', array( 'gastrointestinal' ) ),
		'skin'        => array( 'Skin', 'skincare', array( 'skincare' ) ),
		'respiratory' => array( 'Respiratory', 'asthma', array( 'asthma' ) ),
	);

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
