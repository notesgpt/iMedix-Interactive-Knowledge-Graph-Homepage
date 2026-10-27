<?php
/**
 * Builds the normalised Knowledge Universe graph from the tracker worksheets.
 *
 * Responsibilities:
 *  - decide entity eligibility from Status / Decision fields and the redirect map;
 *  - validate slugs and canonical URLs;
 *  - deduplicate and assign stable IDs;
 *  - optionally confirm live publication status through a resolver (WordPress);
 *  - derive non-clinical edges (editorial topics, in-content links);
 *  - log every rejected record with a reason code.
 *
 * Pure PHP: no WordPress functions, so it runs in tests and the standalone CLI.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\Core;

final class GraphBuilder {

	public const SCHEMA = 'imedix-ku/graph@1';

	public const DRUG_COLUMNS = array(
		'num'          => '#',
		'slug'         => 'Drug slug',
		'url'          => 'URL',
		'status'       => 'Status',
		'batch'        => 'Batch',
		'note'         => 'Note',
		'completed_on' => 'Completed on',
		'category'     => 'Disease Category',
		'registration' => 'Registration',
	);

	public const DISEASE_COLUMNS = array(
		'num'        => '#',
		'slug'       => 'Disease slug',
		'url'        => 'URL',
		'decision'   => 'Decision',
		'target'     => 'Target',
		'cluster'    => 'Cluster',
		'standard'   => 'Standard',
		'status'     => 'Status',
		'note'       => 'Note',
		'updated_on' => 'Updated on',
	);

	public const REDIRECT_COLUMNS = array(
		'from'   => 'Old URL',
		'code'   => 'Code',
		'target' => 'Target',
		'check'  => 'Target check',
	);

	/**
	 * Drug "Status" values → eligibility. Values not listed are rejected as
	 * unknown so that new workflow states are reviewed before they appear.
	 *
	 * - Completed: remediated to the current editorial standard.
	 * - Pending:   legacy article that is live but not yet remediated. Not shown
	 *              on the homepage: only Completed drugs are included.
	 * - Retired:   post set to draft and 301/410-redirected → never linked.
	 * - Not needed / To create: no live page exists.
	 */
	private const DRUG_STATUS = array(
		'completed'  => array( true, 'completed' ),
		'pending'    => array( false, 'status_pending' ),
		'retired'    => array( false, 'status_retired' ),
		'not needed' => array( false, 'status_not_needed' ),
		'to create'  => array( false, 'status_to_create' ),
	);

	/** @var array<string, mixed> */
	private array $options;

	/** @var array<string, array<string, mixed>> */
	private array $nodes = array();

	/** @var array<string, array<string, string>> */
	private array $edges = array();

	/** @var array<string, string> path => node id */
	private array $by_path = array();

	/** @var array<int, array<string, string>> */
	private array $rejected = array();

	/** @var array<int, array<string, string>> */
	private array $warnings = array();

	/** @var array<string, string[]> node id => linked paths (from resolver) */
	private array $content_links = array();

	/** @var array<string, array<string, mixed>> FDA label sources of the KG claims, by "setid_vN" */
	private array $kg_sources = array();

	/** @var array<string, array<int, array{label:string, base:string}>> drug id => active ingredients */
	private array $actives = array();

	private int $unrelated_links = 0;

	private Redirects $redirects;

	/**
	 * @param array<string, mixed> $options {
	 *   @type string        $site_url          Origin used for output URLs (home_url() in WordPress).
	 *   @type string        $sheet_host        Host the tracker's URL column refers to (default www.imedix.com);
	 *                                          lets staging sites validate production URLs from the sheet.
	 *   @type array         $base_paths        type => base path, e.g. ['drug' => '/drugs/', 'disease' => '/diseases/']
	 *   @type callable|null $resolver          fn(string $type, string $slug, string $path): ?array
	 *                                          Returns null when no live post exists, or
	 *                                          ['status' => 'publish'|..., 'title' => ?, 'excerpt' => ?, 'links' => ?string[]].
	 *   @type string        $spreadsheet_id    Recorded in the output for provenance.
	 *   @type string        $generated_at      ISO-8601 timestamp (injected for deterministic tests).
	 * }
	 */
	public function __construct( array $options = array() ) {
		$this->options   = array_merge(
			array(
				'site_url'       => 'https://www.imedix.com',
				'sheet_host'     => 'www.imedix.com',
				'base_paths'     => array(
					'drug'    => '/drugs/',
					'disease' => '/diseases/',
				),
				'resolver'       => null,
				// Paths listed in the site's XML sitemaps. When given, they alone decide what is
				// published; tracker statuses then only label entities. null = tracker rules.
				'published_paths' => null,
				// Signed KG claims from the iMedix Disease Registry (KgSource::claims()); null = none.
				'kg_claims'      => null,
				'spreadsheet_id' => '',
				'generated_at'   => gmdate( 'c' ),
				'source_hash'    => '',
			),
			$options
		);
		if ( is_array( $this->options['published_paths'] ) ) {
			$this->options['published_paths'] = array_fill_keys( $this->options['published_paths'], true );
		}
		$this->redirects = new Redirects();
	}

	/**
	 * @param array{header: string[], rows: array<int, string[]>} $drugs
	 * @param array{header: string[], rows: array<int, string[]>} $diseases
	 * @param array{header: string[], rows: array<int, string[]>}|null $redirects
	 * @return array{graph: array<string, mixed>, report: array<string, mixed>}
	 */
	public function build( array $drugs, array $diseases, ?array $redirects ): array {
		$drug_rows    = Csv::map_columns( $drugs, self::DRUG_COLUMNS, array( 'slug', 'status' ) );
		$disease_rows = Csv::map_columns( $diseases, self::DISEASE_COLUMNS, array( 'slug', 'decision' ) );

		if ( null !== $redirects ) {
			$this->load_redirects( Csv::map_columns( $redirects, self::REDIRECT_COLUMNS, array( 'from', 'code' ) ) );
		} else {
			$this->warn( 'Redirects', '', 'redirect_sheet_unavailable', 'Redirect worksheet unavailable; relying on Status/Decision fields only.' );
		}
		// Retired drugs document their redirect in the Note column ("301 -> /drugs/x/ …").
		foreach ( $drug_rows as $row ) {
			if ( 'retired' !== strtolower( trim( $row['status'] ) ) ) {
				continue;
			}
			$from = $this->entity_path( 'drug', strtolower( trim( $row['slug'] ) ) );
			if ( null === $from || $this->redirects->has( $from ) ) {
				continue;
			}
			if ( preg_match( '#^\s*301\s*(?:->|→)\s*(/[a-z0-9/_-]+/)#i', $row['note'], $m ) ) {
				$this->redirects->add( $from, 301, Text::to_path( $m[1], $this->host() ), 'Drugs:' . $row['_row'] . ' (note)' );
			} elseif ( preg_match( '#^\s*410\b#', $row['note'] ) ) {
				$this->redirects->add( $from, 410, null, 'Drugs:' . $row['_row'] . ' (note)' );
			}
		}
		// Diseases marked for 301 carry their own target.
		foreach ( $disease_rows as $row ) {
			if ( '' !== $row['target'] && 'keep' !== strtolower( $row['decision'] ) ) {
				$from = $this->entity_path( 'disease', $row['slug'] );
				$to   = Text::to_path( $row['target'], $this->host() );
				if ( null !== $from && ! $this->redirects->has( $from ) ) {
					$this->redirects->add( $from, 301, $to, 'Diseases:' . $row['_row'] );
				}
			}
		}

		foreach ( $drug_rows as $row ) {
			$this->add_drug( $row );
		}
		foreach ( $disease_rows as $row ) {
			$this->add_disease( $row );
		}

		$this->add_aliases();
		$this->add_ingredients();
		$this->add_content_link_edges();
		$this->add_kg();
		$this->finalise_degrees();

		return array(
			'graph'  => $this->graph(),
			'report' => $this->report( count( $drug_rows ), count( $disease_rows ) ),
		);
	}

	/* --------------------------------------------------------------------- */

	/**
	 * @param array<int, array<string, string>> $rows
	 */
	private function load_redirects( array $rows ): void {
		foreach ( $rows as $row ) {
			$from = Text::to_path( $row['from'], $this->host() );
			$code = (int) $row['code'];
			if ( null === $from || ! in_array( $code, array( 301, 302, 308, 410 ), true ) ) {
				$this->reject( 'Redirects', $row['_row'], $row['from'], 'invalid_redirect', 'Unparseable path or status code ' . $row['code'] );
				continue;
			}
			$to = null;
			if ( 410 !== $code ) {
				$to = Text::to_path( $row['target'], $this->host() );
				if ( null === $to ) {
					$this->reject( 'Redirects', $row['_row'], $row['from'], 'invalid_redirect_target', 'Target "' . $row['target'] . '" is not a site path' );
					continue;
				}
			}
			$this->redirects->add( $from, $code, $to, 'Redirects:' . $row['_row'] );
		}
		foreach ( $this->redirects->issues() as $issue ) {
			$this->warn( 'Redirects', $issue['row'], $issue['reason'], $issue['path'] );
		}
	}

	/**
	 * @param array<string, string> $row
	 */
	private function add_drug( array $row ): void {
		$status = strtolower( trim( $row['status'] ) );
		if ( $this->sitemap_mode() ) {
			$code = 'completed' === $status ? 'completed' : 'pending'; // Label only: the sitemap decides.
		} elseif ( ! isset( self::DRUG_STATUS[ $status ] ) ) {
			$this->reject( 'Drugs', $row['_row'], $row['slug'], 'status_unknown', 'Status "' . $row['status'] . '"' );
			return;
		} else {
			[ $eligible, $code ] = self::DRUG_STATUS[ $status ];
			if ( ! $eligible ) {
				$this->reject( 'Drugs', $row['_row'], $row['slug'], $code, $row['note'] );
				return;
			}
		}
		// Topics come from "Disease Category"; Batch is the fallback.
		$topics = Topics::parse( $row['category'] );
		$source = 'Drugs.Category';
		if ( ! $topics ) {
			$topics = Topics::parse( $row['batch'] );
			$source = 'Drugs.Batch';
		}
		$this->add_entity( 'drug', 'Drugs', $row, $code, $row['status'], $topics, $source, $row['completed_on'] );
		// Products without FDA/EMA registration stay searchable but are never featured on the homepage.
		$id = 'drug:' . strtolower( trim( $row['slug'] ) );
		if ( isset( $this->nodes[ $id ] ) && 'unregistered' === strtolower( trim( $row['registration'] ) ) ) {
			$this->nodes[ $id ]['registered'] = false;
		}
	}

	/**
	 * @param array<string, string> $row
	 */
	private function add_disease( array $row ): void {
		$raw_status = trim( $row['status'] );
		$s          = strtolower( $raw_status );
		if ( ! $this->sitemap_mode() ) {
			$decision = strtolower( trim( $row['decision'] ) );
			if ( 'keep' !== $decision ) {
				$this->reject( 'Diseases', $row['_row'], $row['slug'], 'decision_not_keep', 'Decision "' . $row['decision'] . '"' );
				return;
			}
			// Without a sitemap, only guides that passed editorial review are shown.
			if ( 0 !== strpos( $s, 'completed' ) ) {
				$this->reject( 'Diseases', $row['_row'], $row['slug'], 'status_not_completed', 'Status "' . $raw_status . '"' );
				return;
			}
		}
		$editorial = 0 === strpos( $s, 'completed' ) ? 'completed' : 'pending';
		$this->add_entity( 'disease', 'Diseases', $row, $editorial, $raw_status, Topics::parse( $row['cluster'] ), 'Diseases.Cluster', $row['updated_on'] );
	}

	/**
	 * @param array<string, string> $row
	 * @param string[]              $topics
	 * @param string                $updated Editorial date from the sheet (YYYY-MM-DD) or ''.
	 */
	private function add_entity( string $type, string $sheet, array $row, string $editorial, string $status_label, array $topics, string $topic_source, string $updated = '' ): void {
		$slug = strtolower( trim( $row['slug'] ) );
		if ( ! Text::is_slug( $slug ) || false !== strpos( $slug, '__trashed' ) ) {
			$this->reject( $sheet, $row['_row'], $row['slug'], 'invalid_slug', '' );
			return;
		}
		$expected = $this->entity_path( $type, $slug );
		if ( $this->sitemap_mode() && ! isset( $this->options['published_paths'][ $expected ] ) ) {
			$this->reject( $sheet, $row['_row'], $slug, 'not_in_sitemap', $expected );
			return;
		}

		// URL column must agree with the slug; an empty URL falls back to the canonical pattern.
		$url_derived = false;
		if ( '' === trim( $row['url'] ) ) {
			$url_derived = true;
			$this->warn( $sheet, $row['_row'], 'url_missing_derived', $expected );
		} else {
			$path = Text::to_path( $row['url'], $this->host() );
			if ( null === $path ) {
				$this->reject( $sheet, $row['_row'], $slug, 'url_invalid', $row['url'] );
				return;
			}
			if ( $path !== $expected ) {
				$this->reject( $sheet, $row['_row'], $slug, 'url_slug_mismatch', $row['url'] . ' ≠ ' . $expected );
				return;
			}
		}

		$id = $type . ':' . $slug;
		if ( isset( $this->nodes[ $id ] ) ) {
			$this->reject( $sheet, $row['_row'], $slug, 'duplicate', 'First seen on row ' . $this->nodes[ $id ]['_row'] );
			return;
		}

		// Never link to a URL that the redirect sheet says is moved or gone.
		$redirect = $this->redirects->resolve( $expected );
		if ( 'none' !== $redirect['status'] ) {
			$this->reject( $sheet, $row['_row'], $slug, 'redirected', $redirect['status'] . ( $redirect['path'] ? ' → ' . $redirect['path'] : '' ) );
			return;
		}

		$publication = 'unverified';
		$name        = Text::humanize_slug( $slug );
		$description = null;
		$resolver    = $this->options['resolver'];
		if ( is_callable( $resolver ) ) {
			$live = $resolver( $type, $slug, $expected );
			if ( null === $live ) {
				$this->reject( $sheet, $row['_row'], $slug, 'wp_not_found', $expected );
				return;
			}
			if ( 'publish' !== ( $live['status'] ?? '' ) ) {
				$this->reject( $sheet, $row['_row'], $slug, 'wp_not_published', 'post_status=' . ( $live['status'] ?? '?' ) );
				return;
			}
			if ( isset( $live['path'] ) && $live['path'] !== $expected ) {
				// The post lives elsewhere (slug changed); link only to verified canonical URLs.
				$this->reject( $sheet, $row['_row'], $slug, 'wp_canonical_mismatch', $expected . ' → ' . $live['path'] );
				return;
			}
			$publication = 'published';
			if ( ! empty( $live['title'] ) ) {
				$name = (string) $live['title'];
			}
			if ( ! empty( $live['excerpt'] ) ) {
				$description = (string) $live['excerpt'];
			}
			if ( ! empty( $live['links'] ) && is_array( $live['links'] ) ) {
				$this->content_links[ $id ] = $live['links'];
			}
			if ( ! empty( $live['active'] ) && is_string( $live['active'] ) ) {
				$this->actives[ $id ] = self::split_actives( $live['active'] );
			}
		}

		$topic_keys = array();
		foreach ( $topics as $label ) {
			$topic_keys[] = $this->ensure_topic( $label, $topic_source );
		}

		$this->nodes[ $id ] = array(
			'_row'        => $sheet . ':' . $row['_row'],
			'id'          => $id,
			'type'        => $type,
			'name'        => $name,
			'slug'        => $slug,
			'url'         => rtrim( $this->options['site_url'], '/' ) . $expected,
			'status'      => array(
				'editorial'   => $editorial,
				'label'       => $status_label,
				'publication' => $publication,
				'updated'     => preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $updated ) ) ? trim( $updated ) : null,
			),
			'category'    => $topic_keys ? $topic_keys[0] : $type,
			'degree'      => 0,
			'aliases'     => array(),
			'description' => $description,
			'urlDerived'  => $url_derived,
		);
		$this->by_path[ $expected ] = $id;

		foreach ( $topic_keys as $key ) {
			$this->add_edge( $id, 'topic:' . $key, 'in_topic', $topic_source );
		}
	}

	private function ensure_topic( string $label, string $source ): string {
		$key = Text::key( $label );
		$id  = 'topic:' . $key;
		if ( ! isset( $this->nodes[ $id ] ) ) {
			$this->nodes[ $id ] = array(
				'_row'     => '',
				'id'       => $id,
				'type'     => 'topic',
				'name'     => $label,
				'slug'     => $key,
				'url'      => null,
				'status'   => null,
				'category' => $key,
				'degree'   => 0,
				'sources'  => array(),
			);
		}
		$this->nodes[ $id ]['sources'][ $source ] = true;
		return $key;
	}

	private function add_edge( string $source, string $target, string $type, string $provenance ): void {
		$key = $type . '|' . $source . '|' . $target;
		if ( ! isset( $this->edges[ $key ] ) ) {
			$this->edges[ $key ] = array(
				'source'     => $source,
				'target'     => $target,
				'type'       => $type,
				'provenance' => $provenance,
			);
		}
	}

	/**
	 * Retired slugs that 301 to a live entity become search aliases of that
	 * entity, so a search for "Accupril" reaches the quinapril page.
	 */
	private function add_aliases(): void {
		foreach ( $this->redirects->sources() as $from ) {
			$resolved = $this->redirects->resolve( $from );
			if ( 'ok' !== $resolved['status'] || ! isset( $this->by_path[ $resolved['path'] ] ) ) {
				continue;
			}
			$segments = explode( '/', trim( $from, '/' ) );
			$alias    = end( $segments );
			if ( ! is_string( $alias ) || ! Text::is_slug( $alias ) || ctype_digit( str_replace( '-', '', $alias ) ) || false !== strpos( $alias, '__' ) ) {
				continue;
			}
			$id    = $this->by_path[ $resolved['path'] ];
			$label = Text::humanize_slug( preg_replace( '/-\d+$/', '', $alias ) );
			if ( Text::fold( $label ) === Text::fold( $this->nodes[ $id ]['name'] ) ) {
				continue;
			}
			$this->nodes[ $id ]['aliases'][ $label ] = true;
		}
		foreach ( $this->nodes as $id => $node ) {
			if ( isset( $node['aliases'] ) ) {
				$aliases = array_keys( $node['aliases'] );
				sort( $aliases );
				$this->nodes[ $id ]['aliases'] = $aliases;
			}
		}
	}

	/**
	 * Active ingredients from the drug guides. Each drug carries its ingredient
	 * names (shown next to the brand, and searchable); an ingredient with its own
	 * published iMedix page (in the sitemap) becomes a node the drug `contains`.
	 * Unregistered products carry the names but are never connected.
	 */
	private function add_ingredients(): void {
		$base  = '/' . trim( (string) ( $this->options['base_paths']['ingredient'] ?? '/ingredients/' ), '/' ) . '/';
		$pages = array();
		foreach ( array_keys( (array) $this->options['published_paths'] ) as $path ) {
			if ( preg_match( '#^' . preg_quote( $base, '#' ) . '([a-z0-9-]+)/$#', (string) $path, $m ) ) {
				$pages[ $m[1] ] = $path;
			}
		}
		foreach ( $this->actives as $drug => $list ) {
			if ( ! isset( $this->nodes[ $drug ] ) ) {
				continue;
			}
			$this->nodes[ $drug ]['actives'] = array_column( $list, 'label' );
			// Each ingredient with its published iMedix page (from the ingredients sitemap), or '' when it has none.
			$this->nodes[ $drug ]['active_paths'] = array_map( static fn( $a ) => $pages[ Text::key( $a['base'] ) ] ?? '', $list );
			$aliases                         = (array) $this->nodes[ $drug ]['aliases'];
			foreach ( $list as $active ) {
				// "Sertraline" finds Zoloft.
				if ( Text::fold( $active['base'] ) !== Text::fold( $this->nodes[ $drug ]['name'] ) ) {
					$aliases[] = ucfirst( $active['base'] );
				}
				$slug = Text::key( $active['base'] );
				if ( ! isset( $pages[ $slug ] ) || false === ( $this->nodes[ $drug ]['registered'] ?? true ) ) {
					continue;
				}
				$id = 'ingredient:' . $slug;
				if ( ! isset( $this->nodes[ $id ] ) ) {
					$this->nodes[ $id ] = array(
						'_row'     => '',
						'id'       => $id,
						'type'     => 'ingredient',
						'name'     => ucfirst( $active['base'] ),
						'slug'     => $slug,
						'url'      => rtrim( $this->options['site_url'], '/' ) . $pages[ $slug ],
						'status'   => null,
						'category' => 'ingredient',
						'degree'   => 0,
						'aliases'  => array(),
					);
				}
				$this->add_edge( $drug, $id, 'contains', 'Active ingredient in the iMedix drug guide' );
			}
			$aliases = array_values( array_unique( $aliases ) );
			sort( $aliases );
			$this->nodes[ $drug ]['aliases'] = $aliases;
		}
	}

	/**
	 * "Amoxicillin + Clavulanate potassium" → [{label: "Amoxicillin", base: "amoxicillin"},
	 * {label: "Clavulanate potassium", base: "clavulanate"}].
	 *
	 * @return array<int, array{label:string, base:string}>
	 */
	private static function split_actives( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/\s*(?:\+|\/|,|;|\band\b|&)\s*/i', $raw ) as $part ) {
			$label = trim( (string) preg_replace( '/\s+/', ' ', $part ) );
			$base  = trim( (string) preg_replace( '/\b(hydrochloride|dihydrochloride|hcl|hyclate|sodium|potassium|calcium|magnesium|besylate|maleate|tartrate|succinate|citrate|phosphate|sulfate|sulphate|mesylate|medoxomil|monohydrate|dihydrate|anhydrous|acetate|fumarate|bromide|disodium)\b/i', '', strtolower( $label ) ) );
			$base  = trim( (string) preg_replace( '/\s+/', ' ', $base ) );
			if ( strlen( $base ) >= 4 ) {
				$out[ $base ] = array(
					'label' => ucfirst( $label ),
					'base'  => $base,
				);
			}
		}
		return array_values( $out );
	}

	/**
	 * Signed knowledge-graph claims (iMedix Disease Registry, US FDA labels only):
	 * Condition (MONDO) described on a disease page; Substance or product →
	 * Condition as treats / prevents / reduces_risk_of / relieves_symptoms_of,
	 * each with its qualifier, verbatim claim and the labels that support it;
	 * Substance → Brand (has_brand). Only what the registry signed — nothing is
	 * inferred. An ANDA label never supports a product-level (brand) claim: it is
	 * evidence for the substance only.
	 */
	private function add_kg(): void {
		$claims = $this->options['kg_claims'];
		if ( ! is_array( $claims ) ) {
			return;
		}
		$site = rtrim( (string) $this->options['site_url'], '/' );
		foreach ( $claims as $c ) {
			$page = $this->by_path[ (string) ( $c['page'] ?? '' ) ] ?? null;
			if ( null === $page || 'disease' !== $this->nodes[ $page ]['type'] ) {
				continue; // The disease page is not published (not in the sitemap).
			}
			$is_substance = 'substance' === ( $c['subject']['kind'] ?? '' );
			$sources      = array_values(
				array_filter(
					(array) ( $c['sources'] ?? array() ),
					static fn( $s ) => $is_substance || 0 !== stripos( (string) ( $s['application'] ?? '' ), 'ANDA' )
				)
			);
			if ( ! $sources ) {
				continue;
			}

			$condition = 'condition:' . (int) $c['condition']['id'];
			if ( ! isset( $this->nodes[ $condition ] ) ) {
				$this->nodes[ $condition ] = array(
					'_row'     => '',
					'id'       => $condition,
					'type'     => 'condition',
					'name'     => (string) $c['condition']['name'],
					'slug'     => (string) (int) $c['condition']['id'],
					'url'      => $this->nodes[ $page ]['url'],
					'status'   => null,
					'category' => 'condition',
					'degree'   => 0,
					'aliases'  => array(),
				);
			}
			$this->add_edge( $condition, $page, 'described_on', 'iMedix disease registry' );

			$path = (string) ( $c['subject']['path'] ?? '' );
			if ( $is_substance ) {
				$slug    = preg_match( '#^/ingredients/([a-z0-9-]+)/$#', $path, $m ) ? $m[1] : '';
				$subject = '' !== $slug ? 'ingredient:' . $slug : 'substance:' . (int) $c['subject']['id'];
				if ( ! isset( $this->nodes[ $subject ] ) ) {
					$this->nodes[ $subject ] = array(
						'_row'     => '',
						'id'       => $subject,
						'type'     => 'ingredient',
						'name'     => (string) $c['subject']['name'],
						'slug'     => '' !== $slug ? $slug : (string) (int) $c['subject']['id'],
						'url'      => '' !== $slug ? $site . $path : null,
						'status'   => null,
						'category' => 'ingredient',
						'degree'   => 0,
						'aliases'  => array(),
					);
				}
			} else {
				$subject = $this->by_path[ $path ] ?? null;
				if ( null === $subject || 'drug' !== $this->nodes[ $subject ]['type'] || false === ( $this->nodes[ $subject ]['registered'] ?? true ) ) {
					continue;
				}
			}

			$key = (string) $c['predicate'] . '|' . $subject . '|' . $condition;
			$this->add_edge( $subject, $condition, (string) $c['predicate'], 'FDA label (iMedix disease registry)' );
			foreach ( $sources as $s ) {
				$this->kg_sources[ $s['setid'] . '_v' . (int) $s['version'] ] = $s;
			}
			$this->edges[ $key ]['claims'][] = array(
				'claim'        => (string) $c['claim'],
				'qualifier'    => (string) ( $c['qualifier'] ?? '' ),
				'supported_by' => array_map( static fn( $s ) => $s['setid'] . '_v' . (int) $s['version'], $sources ),
			);

			if ( $is_substance ) {
				foreach ( (array) ( $c['brands'] ?? array() ) as $brand ) {
					$drug = $this->by_path[ (string) ( $brand['path'] ?? '' ) ] ?? null;
					if ( null !== $drug && 'drug' === $this->nodes[ $drug ]['type'] && false !== ( $this->nodes[ $drug ]['registered'] ?? true ) ) {
						$this->add_edge( $subject, $drug, 'has_brand', 'iMedix disease registry (products verified against the label)' );
					}
				}
			}
		}
	}

	/**
	 * An article link becomes a relationship only when both guides share an
	 * iMedix category: "related reading" and navigation links between unrelated
	 * guides are not medical relationships and are left out.
	 */
	private function add_content_link_edges(): void {
		$topics = array();
		foreach ( $this->edges as $edge ) {
			if ( 'in_topic' === $edge['type'] ) {
				$topics[ $edge['source'] ][ $edge['target'] ] = true;
			}
		}
		foreach ( $this->content_links as $source => $paths ) {
			foreach ( $paths as $path ) {
				$resolved = $this->redirects->resolve( (string) $path );
				$path     = 'ok' === $resolved['status'] ? $resolved['path'] : $path;
				if ( ! isset( $this->by_path[ $path ] ) || $this->by_path[ $path ] === $source ) {
					continue;
				}
				$target = $this->by_path[ $path ];
				// Unregistered products relate to nothing: mentions of them (e.g. interaction warnings) are not relationships.
				if ( false === ( $this->nodes[ $source ]['registered'] ?? true ) || false === ( $this->nodes[ $target ]['registered'] ?? true )
					|| ! array_intersect_key( $topics[ $source ] ?? array(), $topics[ $target ] ?? array() ) ) {
					++$this->unrelated_links;
					continue;
				}
				$this->add_edge( $source, $target, 'links_to', 'WordPress post_content' );
			}
		}
		if ( $this->unrelated_links ) {
			$this->warn( 'Graph', '', 'links_without_shared_category', $this->unrelated_links . ' article links skipped (no shared category)' );
		}
	}

	private function finalise_degrees(): void {
		foreach ( $this->edges as $edge ) {
			++$this->nodes[ $edge['source'] ]['degree'];
			++$this->nodes[ $edge['target'] ]['degree'];
		}
		// Topics that ended up with a single member are not useful groupings —
		// except iMedix categories, which are always shown.
		foreach ( $this->nodes as $id => $node ) {
			if ( 'topic' === $node['type'] && $node['degree'] < 2 && ! in_array( $node['name'], Topics::CATEGORIES, true ) ) {
				$this->warn( 'Graph', '', 'topic_dropped_single_member', $node['name'] );
				foreach ( $this->edges as $k => $edge ) {
					if ( $edge['target'] === $id ) {
						--$this->nodes[ $edge['source'] ]['degree'];
						if ( $this->nodes[ $edge['source'] ]['category'] === $node['slug'] ) {
							$this->nodes[ $edge['source'] ]['category'] = $this->nodes[ $edge['source'] ]['type'];
						}
						unset( $this->edges[ $k ] );
					}
				}
				unset( $this->nodes[ $id ] );
			}
		}
	}

	/* --------------------------------------------------------------------- */

	/**
	 * @return array<string, mixed>
	 */
	private function graph(): array {
		$nodes = array();
		foreach ( $this->nodes as $node ) {
			unset( $node['_row'] );
			if ( isset( $node['sources'] ) ) {
				$node['sources'] = array_keys( $node['sources'] );
				sort( $node['sources'] );
			}
			if ( array_key_exists( 'description', $node ) && null === $node['description'] ) {
				unset( $node['description'] );
			}
			if ( isset( $node['urlDerived'] ) && ! $node['urlDerived'] ) {
				unset( $node['urlDerived'] );
			}
			$nodes[] = $node;
		}
		usort( $nodes, static fn( $a, $b ) => strcmp( $a['id'], $b['id'] ) );

		$edges = array_values( $this->edges );
		usort( $edges, static fn( $a, $b ) => strcmp( $a['type'] . $a['source'] . $a['target'], $b['type'] . $b['source'] . $b['target'] ) );

		$counts = array(
			'drug'       => 0,
			'disease'    => 0,
			'topic'      => 0,
			'ingredient' => 0,
			'condition'  => 0,
		);
		foreach ( $nodes as $node ) {
			++$counts[ $node['type'] ];
		}

		return array(
			'schema'      => self::SCHEMA,
			'generatedAt' => $this->options['generated_at'],
			'source'      => array(
				'spreadsheetId' => $this->options['spreadsheet_id'],
				'hash'          => $this->options['source_hash'],
				'publication'   => is_callable( $this->options['resolver'] ) ? 'wordpress' : 'unverified',
			),
			'nodeTypes'   => array(
				'drug'    => array(
					'label'  => 'Drug',
					'plural' => 'Drugs',
				),
				'disease' => array(
					'label'  => 'Disease',
					'plural' => 'Diseases',
				),
				'topic'   => array(
					'label'  => 'Editorial topic',
					'plural' => 'Editorial topics',
				),
				'ingredient' => array(
					'label'  => 'Active ingredient',
					'plural' => 'Active ingredients',
				),
				'condition'  => array(
					'label'  => 'Condition',
					'plural' => 'Conditions',
				),
			),
			'edgeTypes'   => array(
				'in_topic' => array(
					'label'      => 'Grouped under editorial topic',
					'clinical'   => false,
					'provenance' => 'Google Sheet "iMedix Drug Remediation — Master Tracker": Drugs."Disease Category" (Batch as fallback) and Diseases.Cluster, resolved to the iMedix category list.',
				),
				'links_to' => array(
					'label'      => 'Linked from the iMedix article',
					'clinical'   => false,
					'provenance' => 'Internal links in the published WordPress article body, kept only between guides that share an iMedix category.',
				),
				'treats'               => array(
					'label'      => 'FDA-approved to treat',
					'clinical'   => true,
					'provenance' => 'FDA label (US), signed in the iMedix Disease Registry; from FDA labels, not a complete list.',
				),
				'prevents'             => array(
					'label'      => 'FDA-approved to prevent',
					'clinical'   => true,
					'provenance' => 'FDA label (US), signed in the iMedix Disease Registry; from FDA labels, not a complete list.',
				),
				'reduces_risk_of'      => array(
					'label'      => 'FDA-approved to reduce the risk of',
					'clinical'   => true,
					'provenance' => 'FDA label (US), signed in the iMedix Disease Registry; from FDA labels, not a complete list.',
				),
				'relieves_symptoms_of' => array(
					'label'      => 'FDA-approved to relieve symptoms of',
					'clinical'   => true,
					'provenance' => 'FDA label (US), signed in the iMedix Disease Registry; from FDA labels, not a complete list.',
				),
				'has_brand'            => array(
					'label'      => 'Brand on iMedix',
					'clinical'   => false,
					'provenance' => 'Products verified against the label in the iMedix Disease Registry.',
				),
				'described_on'         => array(
					'label'      => 'Covered by the iMedix guide',
					'clinical'   => false,
					'provenance' => 'iMedix Disease Registry: condition (MONDO) ↔ disease page.',
				),
				'contains' => array(
					'label'      => 'Contains the active ingredient',
					'clinical'   => false,
					'provenance' => 'Active ingredient field of the iMedix drug guide, linked to the published iMedix ingredient page.',
				),
			),
			'counts'      => $counts,
			'nodes'       => $nodes,
			'edges'       => $edges,
			// supported_by targets: FDA labels (US) behind the KG claims, keyed "setid_vN".
			'kgSources'   => (object) $this->kg_sources,
			'kgNote'      => 'From FDA labels, not a complete list.',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function report( int $drug_rows, int $disease_rows ): array {
		$reasons = array();
		foreach ( $this->rejected as $r ) {
			$reasons[ $r['reason'] ] = ( $reasons[ $r['reason'] ] ?? 0 ) + 1;
		}
		ksort( $reasons );
		return array(
			'generatedAt' => $this->options['generated_at'],
			'input'       => array(
				'drugRows'    => $drug_rows,
				'diseaseRows' => $disease_rows,
				'redirects'   => count( $this->redirects->sources() ),
			),
			'rejectedByReason' => $reasons,
			'rejected'    => $this->rejected,
			'warnings'    => $this->warnings,
		);
	}

	private function sitemap_mode(): bool {
		return is_array( $this->options['published_paths'] );
	}

	private function reject( string $sheet, string $row, string $slug, string $reason, string $detail ): void {
		$this->rejected[] = compact( 'sheet', 'row', 'slug', 'reason', 'detail' );
	}

	private function warn( string $sheet, string $row, string $reason, string $detail ): void {
		$this->warnings[] = compact( 'sheet', 'row', 'reason', 'detail' );
	}

	private function entity_path( string $type, string $slug ): ?string {
		$base = $this->options['base_paths'][ $type ] ?? null;
		return null === $base ? null : '/' . trim( $base, '/' ) . '/' . $slug . '/';
	}

	/** Host that URLs in the sheet must point to. */
	private function host(): string {
		return (string) $this->options['sheet_host'];
	}
}
