<?php
/**
 * PSR-4 style autoloader for the plugin namespace (no Composer needed on the server).
 *
 * @package Imedix\KnowledgeUniverse
 */

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Imedix\\KnowledgeUniverse\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
