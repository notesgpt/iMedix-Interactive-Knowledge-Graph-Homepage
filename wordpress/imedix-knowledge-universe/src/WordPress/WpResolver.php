<?php
/**
 * Confirms each tracker entity against WordPress: the post must exist at the
 * canonical path and be published. WordPress is the source of truth for live
 * publication status, titles and destinations.
 *
 * Read-only: never writes to posts.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\WordPress;

use Imedix\KnowledgeUniverse\Core\ContentLinks;
use Imedix\KnowledgeUniverse\Core\Text;

final class WpResolver {

	private bool $scan_links;

	private string $host;

	private string $sheet_host;

	public function __construct( bool $scan_links, string $sheet_host ) {
		$this->scan_links = $scan_links;
		$this->host       = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$this->sheet_host = $sheet_host;
	}

	/**
	 * @return array{status: string, title: string, excerpt: ?string, links: string[], path: string}|null
	 */
	public function __invoke( string $type, string $slug, string $path ): ?array {
		// url_to_postid() understands every public post type's rewrite rules, so
		// the resolver does not need to know the CPT names used for drugs/diseases.
		$post_id = url_to_postid( home_url( $path ) );
		if ( ! $post_id ) {
			return null;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$permalink_path = Text::to_path( (string) get_permalink( $post ), $this->host );

		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$excerpt = null;
		if ( '' !== trim( $post->post_excerpt ) ) {
			$excerpt = wp_html_excerpt( html_entity_decode( wp_strip_all_tags( $post->post_excerpt ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 220, '…' );
		}

		return array(
			'status'   => (string) $post->post_status,
			'title'    => trim( $title ),
			'excerpt'  => $excerpt,
			'links'    => $this->scan_links ? $this->links( (string) $post->post_content ) : array(),
			// Active ingredient(s) as written in the drug guide, e.g. "Amoxicillin + Clavulanate potassium".
			'active'   => 'drug' === $type ? trim( wp_strip_all_tags( (string) get_post_meta( $post->ID, 'product_info_activeingredient', true ) ) ) : '',
			'path'     => null === $permalink_path ? $path : $permalink_path,
			// Editorial categories (e.g. the "Drug Categories" taxonomy) for guides the tracker does not categorise.
			'categories' => $this->categories( $post ),
			// The guide's own regulatory status field, e.g. "Not Approved" for imported products.
			'approval' => 'drug' === $type ? $this->approval( $post->ID ) : '',
		);
	}

	/**
	 * Term names from the post type's public, hierarchical taxonomies.
	 *
	 * @return string[]
	 */
	private function categories( \WP_Post $post ): array {
		$names = array();
		foreach ( get_object_taxonomies( $post, 'objects' ) as $tax ) {
			if ( ! $tax->public || ! $tax->hierarchical ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax->name );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$names[] = html_entity_decode( (string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Regulatory status as written on the guide. The field name is filterable
	 * (`imedix_ku_approval_fields`); ACF fields are read through ACF when present.
	 */
	private function approval( int $post_id ): string {
		foreach ( (array) apply_filters( 'imedix_ku_approval_fields', array( 'drug_status' ) ) as $field ) {
			$value = function_exists( 'get_field' ) ? get_field( (string) $field, $post_id, false ) : get_post_meta( $post_id, (string) $field, true );
			if ( is_array( $value ) ) {
				$value = implode( ' ', array_map( 'strval', $value ) );
			}
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( wp_strip_all_tags( $value ) );
			}
		}
		return '';
	}

	/**
	 * Internal links, whether written with this site's host or the production host.
	 *
	 * @return string[]
	 */
	private function links( string $html ): array {
		$links = ContentLinks::extract( $html, $this->host );
		if ( ! Text::same_host( $this->host, $this->sheet_host ) ) {
			$links = array_values( array_unique( array_merge( $links, ContentLinks::extract( $html, $this->sheet_host ) ) ) );
		}
		return $links;
	}
}
