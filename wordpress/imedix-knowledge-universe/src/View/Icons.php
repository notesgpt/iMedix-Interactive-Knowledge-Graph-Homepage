<?php
/**
 * Inline SVG icons for editorial topics (one consistent line style: 24 px grid,
 * 1.75 stroke, round caps). Icons are decorative — the topic name is always
 * visible next to them — so they are aria-hidden.
 *
 * Pure PHP (no WordPress), so the preview renderer can use it too.
 *
 * @package Imedix\KnowledgeUniverse
 */

namespace Imedix\KnowledgeUniverse\View;

final class Icons {

	/** Topic key => SVG inner markup. Unknown topics fall back to the topic diamond. */
	private const TOPICS = array(
		'analgesics'           => '<path d="M13 3 5 13.5h6L10 21l8-10.5h-6z"/>',
		'pain-relief'          => '<path d="M13 3 5 13.5h6L10 21l8-10.5h-6z"/>',
		'antibiotics'          => '<path d="M10.6 3.9a5 5 0 0 1 7.1 7.1l-6.7 6.7a5 5 0 0 1-7.1-7.1z"/><path d="m7.6 7.6 7 7"/>',
		'arthritis'            => '<path d="M8 3v5a4 4 0 0 0 8 0V3"/><path d="M8 21v-4a4 4 0 0 1 8 0v4"/>',
		'asthma'               => '<path d="M12 4v7"/><path d="M12 11c-1.5 0-2.5-2-4.5-3.5C5 7.5 3 10.5 3 14.5 3 17.6 4.8 20 7 20c3 0 5-2.2 5-5z"/><path d="M12 11c1.5 0 2.5-2 4.5-3.5 2.5 0 4.5 3 4.5 7 0 3.1-1.8 5.5-4 5.5-3 0-5-2.2-5-5z"/>',
		'blood-pressure'       => '<path d="M4 17a8 8 0 1 1 16 0"/><path d="m12 17 4.5-5"/><circle cx="12" cy="17" r="1.2"/>',
		'cardiovascular'       => '<path d="M12 20s-7.5-4.6-7.5-10.4A4.2 4.2 0 0 1 12 7.2a4.2 4.2 0 0 1 7.5 2.4C19.5 15.4 12 20 12 20z"/><path d="M6 12.5h2.6l1.6-2.5 2.6 4.6 1.6-2.1H18"/>',
		'heart-disease'        => '<path d="M12 20s-7.5-4.6-7.5-10.4A4.2 4.2 0 0 1 12 7.2a4.2 4.2 0 0 1 7.5 2.4C19.5 15.4 12 20 12 20z"/>',
		'depression'           => '<path d="M7.5 17.5a4 4 0 0 1-.6-7.9 5.2 5.2 0 0 1 10-1 3.5 3.5 0 0 1 .6 8.9z"/><path d="M9 20.5v.5M12 20.5v.5M15 20.5v.5"/>',
		'diabetes'             => '<path d="M12 3s6 6.4 6 11a6 6 0 0 1-12 0c0-4.6 6-11 6-11z"/><path d="M9.5 14.5a2.5 2.5 0 0 0 2.5 2.5"/>',
		'erectile-dysfunction' => '<circle cx="10" cy="14" r="5"/><path d="m13.6 10.4 6-6M15 4.4h4.6V9"/>',
		'mens-health'          => '<circle cx="10" cy="14" r="5"/><path d="m13.6 10.4 6-6M15 4.4h4.6V9"/>',
		'womens-health'        => '<circle cx="12" cy="9" r="5"/><path d="M12 14v7M9 18h6"/>',
		'gastrointestinal'     => '<path d="M9 3v3.5A3.5 3.5 0 0 1 5.5 10 2.5 2.5 0 0 0 3 12.5v.5A7 7 0 0 0 10 20h1.5a8.5 8.5 0 0 0 8.5-8.5V9a4 4 0 0 0-8 0v1"/>',
		'skincare'             => '<path d="m12 3 1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8z"/><path d="m18 15 .9 2.1L21 18l-2.1.9L18 21l-.9-2.1L15 18l2.1-.9z"/>',
		'cardio'               => '<path d="M5 12h3l2-3 3 6 2-3h4"/>',
	);

	private const FALLBACK = '<path d="M12 3.5 20.5 12 12 20.5 3.5 12z"/>';

	public static function topic( string $key, string $class = 'ku-topic-icon' ): string {
		$inner = self::TOPICS[ $key ] ?? self::FALLBACK;
		return '<svg class="ku-icon ' . htmlspecialchars( $class, ENT_QUOTES ) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $inner . '</svg>';
	}
}
