<?php
/**
 * Two independent facts about a drug guide, kept apart:
 *
 *  - FDA regulatory status: approved · not FDA-approved in the U.S. · unverified;
 *  - dispensing status:     prescription only · over the counter · unknown.
 *
 * The legacy "drug_status" field mixed both ("Rx", "OTC", "Not Approved" …).
 * Values resolve in this order, and nothing is ever assumed:
 *   1. what an editor set in the two new fields;
 *   2. an FDA label from the signed knowledge graph (an NDA/BLA label backs the
 *      brand page — ANDA labels never do), which verifies approval;
 *   3. explicit words in the legacy field ("Not Approved" → not approved,
 *      "OTC" → over the counter, "Rx"/"Prescription" → prescription only).
 * Prescription status never implies FDA approval, and an active ingredient never does.
 *
 * Output: `[imedix_drug_status]`, the template tag imedix_ku_drug_status(), and
 * optionally the badges above drug guides (setting, off by default — the theme's
 * own panel may show them instead).
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\WordPress;

use Imedix\KnowledgeUniverse\Core\Text;

final class DrugStatus {

	public const FDA_META        = 'imedix_fda_status';
	public const DISPENSING_META = 'imedix_dispensing_status';

	public const FDA = array(
		'approved'     => 'FDA-approved',
		'not_approved' => 'Not FDA-approved in the U.S.',
		'unknown'      => 'Unverified',
	);

	public const DISPENSING = array(
		'rx'      => 'Prescription only',
		'otc'     => 'Over the counter (OTC)',
		'unknown' => 'Dispensing status unknown',
	);

	private const NONCE = 'imedix_ku_drug_status';

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_meta' ), 20 );
		add_shortcode( 'imedix_drug_status', static fn() => self::render( get_the_ID() ) );
		add_filter( 'the_content', array( self::class, 'prepend' ), 8 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'styles' ) );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( self::class, 'meta_box' ) );
			add_action( 'save_post', array( self::class, 'save' ), 10, 2 );
		}
	}

	/** The post type behind the drug base path (e.g. /drugs/ → "drugs"). */
	public static function post_type(): ?string {
		$slug = trim( (string) Settings::get( 'base_drug' ), '/' );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$rewrite = is_array( $type->rewrite ) ? ( $type->rewrite['slug'] ?? '' ) : '';
			if ( $rewrite === $slug || $type->name === $slug ) {
				return $type->name;
			}
		}
		return null;
	}

	public static function register_meta(): void {
		$type = self::post_type();
		if ( null === $type ) {
			return;
		}
		foreach ( array( self::FDA_META => self::FDA, self::DISPENSING_META => self::DISPENSING ) as $key => $allowed ) {
			register_post_meta(
				$type,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => static fn( $v ) => isset( $allowed[ (string) $v ] ) ? (string) $v : '',
					'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
				)
			);
		}
	}

	/**
	 * @return array{fda:string, fda_source:string, fda_label:string, dispensing:string, dispensing_source:string}
	 *   fda_source / dispensing_source: editor | fda_label | legacy | none; fda_label: DailyMed URL when verified by a label.
	 */
	public static function resolve( int $post_id ): array {
		$legacy = self::legacy( $post_id );
		$out    = array(
			'fda'               => 'unknown',
			'fda_source'        => 'none',
			'fda_label'         => '',
			'dispensing'        => 'unknown',
			'dispensing_source' => 'none',
		);

		$set = (string) get_post_meta( $post_id, self::FDA_META, true );
		if ( isset( self::FDA[ $set ] ) && 'unknown' !== $set ) {
			$out['fda']        = $set;
			$out['fda_source'] = 'editor';
		} else {
			$label = self::fda_label( $post_id );
			if ( '' !== $label ) {
				$out['fda']        = 'approved';
				$out['fda_source'] = 'fda_label';
				$out['fda_label']  = $label;
			} elseif ( self::says_not_approved( $legacy ) ) {
				$out['fda']        = 'not_approved';
				$out['fda_source'] = 'legacy';
			} elseif ( preg_match( '/\bfda[\s-]*approved\b/i', $legacy ) ) {
				$out['fda']        = 'approved';
				$out['fda_source'] = 'legacy';
			}
		}

		$set = (string) get_post_meta( $post_id, self::DISPENSING_META, true );
		if ( isset( self::DISPENSING[ $set ] ) && 'unknown' !== $set ) {
			$out['dispensing']        = $set;
			$out['dispensing_source'] = 'editor';
		} elseif ( preg_match( '/\b(otc|over[\s-]*the[\s-]*counter)\b/i', $legacy ) ) {
			$out['dispensing']        = 'otc';
			$out['dispensing_source'] = 'legacy';
		} elseif ( preg_match( '/\b(rx|prescription)\b/i', $legacy ) ) {
			$out['dispensing']        = 'rx';
			$out['dispensing_source'] = 'legacy';
		}
		return $out;
	}

	/** "Not Approved", "Not FDA-approved", "Unapproved", "Unregistered". */
	public static function says_not_approved( string $text ): bool {
		return 1 === preg_match( '/\bnot\s+(?:fda[\s-]*)?approved\b|\bunapproved\b|\bunregistered\b/i', $text );
	}

	/** The legacy combined field, read through ACF when present (field name filterable). */
	public static function legacy( int $post_id ): string {
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
	 * DailyMed URL of an FDA label that backs this drug page in the synced
	 * knowledge graph (NDA/BLA for a brand; ANDA labels are never attached to brands).
	 */
	private static function fda_label( int $post_id ): string {
		$graph = Sync::graph_data();
		$path  = Text::to_path( (string) get_permalink( $post_id ), (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( ! $graph || null === $path ) {
			return '';
		}
		$id = '';
		foreach ( (array) ( $graph['nodes'] ?? array() ) as $n ) {
			if ( 'drug' === ( $n['t'] ?? '' ) && $path === ( $n['p'] ?? '' ) ) {
				$id = (string) $n['id'];
				break;
			}
		}
		foreach ( (array) ( $graph['edges'] ?? array() ) as $e ) {
			if ( '' !== $id && $id === ( $e[0] ?? '' ) && 'kg' === ( $e[2] ?? '' ) && 0 === strpos( (string) ( $e[4] ?? '' ), 'https://dailymed.nlm.nih.gov/' ) ) {
				return (string) $e[4];
			}
		}
		return '';
	}

	/* ------------------------------------------------------------- output */

	public static function render( $post_id ): string {
		$post_id = (int) $post_id;
		if ( ! $post_id || get_post_type( $post_id ) !== self::post_type() ) {
			return '';
		}
		return self::badges( self::resolve( $post_id ) );
	}

	/**
	 * Markup for a resolved status (see resolve()).
	 *
	 * @param array{fda:string, fda_label:string, dispensing:string} $s
	 */
	public static function badges( array $s ): string {
		$tone = array(
			'approved'     => 'ok',
			'not_approved' => 'warn',
			'unknown'      => 'muted',
		)[ $s['fda'] ];
		$icon = array(
			'ok'    => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
			'warn'  => '<path d="M12 4 2.8 20h18.4z"/><path d="M12 10v4M12 17h.01"/>',
			'muted' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8h.01"/>',
		)[ $tone ];

		$html  = '<div class="imx-status" role="group" aria-label="' . esc_attr__( 'Regulatory and dispensing status', 'imedix-ku' ) . '">';
		$html .= '<span class="imx-status__badge imx-status__badge--' . esc_attr( $tone ) . '"><span class="imx-status__key">' . esc_html__( 'FDA status', 'imedix-ku' ) . '</span>';
		$html .= '<svg class="imx-status__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $icon . '</svg>';
		$html .= esc_html( self::FDA[ $s['fda'] ] ) . '</span>';
		if ( '' !== $s['fda_label'] ) {
			$html .= '<a class="imx-status__src" href="' . esc_url( $s['fda_label'] ) . '" target="_blank" rel="noopener noreferrer nofollow">' . esc_html__( 'FDA label', 'imedix-ku' ) . '<span class="screen-reader-text"> ' . esc_html__( '(DailyMed, opens in a new tab)', 'imedix-ku' ) . '</span></a>';
		}
		if ( 'unknown' !== $s['dispensing'] ) {
			$html .= '<span class="imx-status__badge imx-status__badge--plain"><span class="imx-status__key">' . esc_html__( 'Dispensing', 'imedix-ku' ) . '</span>';
			$html .= ( 'rx' === $s['dispensing'] ? '<span class="imx-status__rx" aria-hidden="true">Rx</span>' : '' );
			$html .= esc_html( self::DISPENSING[ $s['dispensing'] ] ) . '</span>';
		}
		return $html . '</div>';
	}

	/** Optional: badges above the guide (Settings → "Show status badges on drug guides"). */
	public static function prepend( $content ) {
		if ( ! Settings::get( 'drug_status_badges' ) || ! is_singular( (string) self::post_type() ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		return self::render( get_the_ID() ) . $content;
	}

	public static function styles(): void {
		if ( ! is_singular( (string) self::post_type() ) ) {
			return;
		}
		wp_register_style( 'imedix-ku-drug-status', false, array(), '1' );
		wp_enqueue_style( 'imedix-ku-drug-status' );
		wp_add_inline_style( 'imedix-ku-drug-status', self::CSS );
	}

	/** Compact, theme-neutral badges (iOS corner scale; AA contrast on white). */
	private const CSS = '.imx-status{display:flex;flex-wrap:wrap;align-items:center;gap:6px 8px;margin:0 0 16px;font-size:13px;line-height:1.35}'
		. '.imx-status__badge{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:10px;font-weight:600;border:1px solid transparent}'
		. '.imx-status__key{font-weight:500;opacity:.8}.imx-status__key::after{content:":"}'
		. '.imx-status__icon{width:14px;height:14px;flex:none;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}'
		. '.imx-status__badge--ok{background:#e8f5ee;color:#12704b}'
		. '.imx-status__badge--warn{background:#fdf1e3;color:#8a4a06}'
		. '.imx-status__badge--muted{background:#f1f4f7;color:#4a5e74}'
		. '.imx-status__badge--plain{background:#fff;color:#112236;border-color:#d5dee8}'
		. '.imx-status__rx{display:inline-grid;place-items:center;min-width:22px;height:18px;padding:0 4px;border-radius:5px;background:#112236;color:#fff;font-size:11px;font-weight:700}'
		. '.imx-status__src{color:#1c5dbf;font-weight:600;text-decoration:underline;text-underline-offset:3px}';

	/* -------------------------------------------------------------- admin */

	public static function meta_box(): void {
		$type = self::post_type();
		if ( null !== $type ) {
			add_meta_box( 'imedix-ku-drug-status', __( 'FDA & dispensing status', 'imedix-ku' ), array( self::class, 'meta_box_html' ), $type, 'side', 'high' );
		}
	}

	public static function meta_box_html( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		$r      = self::resolve( $post->ID );
		$legacy = self::legacy( $post->ID );
		$fields = array(
			self::FDA_META        => array( __( 'FDA regulatory status', 'imedix-ku' ), self::FDA ),
			self::DISPENSING_META => array( __( 'Dispensing status', 'imedix-ku' ), self::DISPENSING ),
		);
		foreach ( $fields as $key => [ $label, $options ] ) {
			$value = (string) get_post_meta( $post->ID, $key, true );
			echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" style="width:100%">';
			echo '<option value="">' . esc_html__( 'Automatic (see below)', 'imedix-ku' ) . '</option>';
			foreach ( $options as $k => $text ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $value, $k, false ) . '>' . esc_html( $text ) . '</option>';
			}
			echo '</select></p>';
		}
		$from = array(
			'editor'    => __( 'set here', 'imedix-ku' ),
			'fda_label' => __( 'verified by an FDA label', 'imedix-ku' ),
			'legacy'    => __( 'from the old Drug Status field', 'imedix-ku' ),
			'none'      => __( 'no source', 'imedix-ku' ),
		);
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: FDA status, 2: its source, 3: dispensing status, 4: its source. */
				__( 'Now shown: %1$s (%2$s); %3$s (%4$s).', 'imedix-ku' ),
				self::FDA[ $r['fda'] ],
				$from[ $r['fda_source'] ],
				self::DISPENSING[ $r['dispensing'] ],
				$from[ $r['dispensing_source'] ]
			)
		) . '</p>';
		if ( '' !== $legacy ) {
			/* translators: %s: value of the old combined field. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Old Drug Status field: "%s". Prescription status never implies FDA approval.', 'imedix-ku' ), $legacy ) ) . '</p>';
		}
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( $post->post_type !== self::post_type() || ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( array( self::FDA_META => self::FDA, self::DISPENSING_META => self::DISPENSING ) as $key => $allowed ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
			if ( isset( $allowed[ $value ] ) ) {
				update_post_meta( $post_id, $key, $value );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}
}

