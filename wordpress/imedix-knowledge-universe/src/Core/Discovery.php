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
	 * Health areas for the library: the iMedix categories from the tracker, then
	 * each condition in an area brings the medicines FDA-approved for it according
	 * to the signed iMedix knowledge graph (`kg`, from FDA labels — not a complete
	 * list). Nothing is inferred.
	 * Every area with at least one guide is shown.
	 *
	 * @param array<string, mixed> $graph Client graph (ClientPayload::from_graph format).
	 * @return array<int, array{key:string, icon:string, name:string, total:int, drugs: array{count:int, items: array<int, array{name:string, path:string}>}, diseases: array{count:int, items: array<int, array{name:string, path:string}>}}>
	 */
	public static function areas( array $graph, int $per_type = 4, int $min_total = 1 ): array {
		$nodes = self::index( $graph );
		$topic_area = array();
		foreach ( self::AREAS as $key => $area ) {
			foreach ( $area[2] as $topic ) {
				$topic_area[ 'topic:' . $topic ] = $key;
			}
		}

		$members = array();
		foreach ( $graph['edges'] ?? array() as $edge ) {
			if ( 'in_topic' !== ( $edge[2] ?? '' ) || ! isset( $nodes[ $edge[0] ], $nodes[ $edge[1] ] ) ) {
				continue;
			}
			$key = $topic_area[ $edge[1] ] ?? substr( (string) $edge[1], strlen( 'topic:' ) );
			if ( ! isset( self::AREAS[ $key ] ) ) {
				continue; // Only the fixed iMedix categories are shown.
			}
			$members[ $key ][ $edge[0] ] = true;
		}

		// One direction only: a condition in the area brings the medicines approved
		// for it (signed KG). A medicine never pulls other conditions in, so
		// a drug with two categories cannot drag a condition into the wrong area.
		$approved = array();
		foreach ( $graph['edges'] ?? array() as $edge ) {
			if ( 'kg' === ( $edge[2] ?? '' ) && isset( $nodes[ $edge[0] ], $nodes[ $edge[1] ] ) ) {
				$approved[ $edge[1] ][ $edge[0] ] = array( (string) ( $edge[3] ?? '' ), (string) ( $edge[4] ?? '' ) );
			}
		}
		// The claim and FDA label that put a medicine in an area (first condition wins).
		$evidence = array();
		foreach ( $members as $key => $set ) {
			foreach ( array_keys( $set ) as $id ) {
				if ( 'disease' === $nodes[ $id ]['t'] ) {
					foreach ( $approved[ $id ] ?? array() as $drug => $claim ) {
						$members[ $key ][ $drug ]   = true;
						$evidence[ $key ][ $drug ] ??= $claim;
					}
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
			$ev   = $evidence[ $key ] ?? array();
			$list = static function ( array $items ) use ( $take, $ev ): array {
				// Medicines with an FDA label behind them first.
				usort( $items, static fn( $a, $b ) => ( isset( $ev[ $b['id'] ] ) <=> isset( $ev[ $a['id'] ] ) ) ?: self::rank( $a, $b ) );
				return array(
					'count' => count( $items ),
					'items' => array_map(
						static fn( $n ) => array(
							'name'    => (string) $n['n'],
							'path'    => (string) $n['p'],
							'active'  => (string) ( $n['g'] ?? '' ),
							'actives' => array_map(
								static fn( $a ) => array(
									'label' => (string) $a[0],
									'path'  => (string) ( $a[1] ?? '' ),
								),
								(array) ( $n['gl'] ?? ( empty( $n['g'] ) ? array() : array( array( $n['g'], '' ) ) ) )
							),
							'claim'   => (string) ( $ev[ $n['id'] ][0] ?? '' ),
							'source'  => (string) ( $ev[ $n['id'] ][1] ?? '' ),
						),
						array_slice( $items, 0, $take )
					),
				);
			};
			$areas[] = array(
				'key'      => $key,
				'icon'     => self::AREAS[ $key ][1] ?? $key,
				'name'     => self::AREAS[ $key ][0],
				'total'    => $total,
				'drugs'    => $list( $by_type['drug'] ),
				'diseases' => $list( $by_type['disease'] ),
			);
		}
		// Alphabetical: readers scan for their area by name.
		usort( $areas, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
		return $areas;
	}

	/**
	 * Reader-facing health areas: key => [label, icon, tracker groupings].
	 * Editorial navigation only (documented in docs/DATA-PIPELINE.md); it says
	 * nothing about which medicine treats which condition.
	 */
	private const AREAS = array(
		'erectile-dysfunction' => array( 'Erectile Dysfunction', 'erectile-dysfunction', array( 'erectile-dysfunction' ) ),
		'allergies'            => array( 'Allergies', 'asthma', array( 'allergies' ) ),
		'anti-fungal'          => array( 'Anti Fungal', 'antibiotics', array( 'anti-fungal' ) ),
		'anti-viral'           => array( 'Anti Viral', 'antibiotics', array( 'anti-viral' ) ),
		'antibiotics'          => array( 'Antibiotics', 'antibiotics', array( 'antibiotics' ) ),
		'anxiety'              => array( 'Anxiety', 'depression', array( 'anxiety' ) ),
		'arthritis'            => array( 'Arthritis', 'arthritis', array( 'arthritis' ) ),
		'asthma'               => array( 'Asthma', 'asthma', array( 'asthma' ) ),
		'birth-control'        => array( 'Birth Control', 'womens-health', array( 'birth-control' ) ),
		'blood-pressure'       => array( 'Blood Pressure', 'blood-pressure', array( 'blood-pressure' ) ),
		'cholesterol-lowering' => array( 'Cholesterol Lowering', 'cardio', array( 'cholesterol-lowering' ) ),
		'depression'           => array( 'Depression', 'depression', array( 'depression' ) ),
		'diabetes'             => array( 'Diabetes', 'diabetes', array( 'diabetes' ) ),
		'gastrointestinal'     => array( 'Gastrointestinal', 'gastrointestinal', array( 'gastrointestinal' ) ),
		'hair-loss'            => array( 'Hair Loss', 'skincare', array( 'hair-loss' ) ),
		'heart-disease'        => array( 'Heart Disease', 'heart-disease', array( 'heart-disease' ) ),
		'herbals'              => array( 'Herbals', 'skincare', array( 'herbals' ) ),
		'mens-health'          => array( "Men's Health", 'mens-health', array( 'mens-health' ) ),
		'muscle-relaxant'      => array( 'Muscle Relaxant', 'arthritis', array( 'muscle-relaxant' ) ),
		'pain-relief'          => array( 'Pain Relief', 'pain-relief', array( 'pain-relief' ) ),
		'skincare'             => array( 'Skincare', 'skincare', array( 'skincare' ) ),
		'sleep-aid'            => array( 'Sleep Aid', 'depression', array( 'sleep-aid' ) ),
		'quit-smoking'         => array( 'Quit Smoking', 'cardio', array( 'quit-smoking' ) ),
		'weight-loss'          => array( 'Weight Loss', 'diabetes', array( 'weight-loss' ) ),
		'womans-health'        => array( "Woman's Health", 'womens-health', array( 'womans-health' ) ),
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
			// Unregistered products are reachable through search only, never featured.
			if ( isset( $n['id'], $n['t'], $n['n'] ) && 0 !== ( $n['r'] ?? 1 ) ) {
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
