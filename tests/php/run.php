<?php
/**
 * Dependency-free test runner for the pure-PHP core (no WordPress, no Composer).
 * Usage: php tests/php/run.php
 */

require __DIR__ . '/../../wordpress/imedix-knowledge-universe/src/autoload.php';

final class T {
	public static int $pass = 0;
	public static array $fail = array();
	public static string $current = '';

	public static function eq( $expected, $actual, string $msg = '' ): void {
		if ( $expected === $actual ) {
			++self::$pass;
			return;
		}
		self::$fail[] = sprintf( "%s: %s\n    expected %s\n    actual   %s", self::$current, $msg, var_export( $expected, true ), var_export( $actual, true ) );
	}

	public static function ok( bool $cond, string $msg ): void {
		self::eq( true, $cond, $msg );
	}

	public static function throws( callable $fn, string $msg ): void {
		try {
			$fn();
			self::$fail[] = self::$current . ': expected exception — ' . $msg;
		} catch ( Throwable $e ) {
			++self::$pass;
		}
	}
}

function test( string $name, callable $fn ): void {
	T::$current = $name;
	try {
		$fn();
	} catch ( Throwable $e ) {
		T::$fail[] = $name . ': uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine();
	}
}

foreach ( glob( __DIR__ . '/*Test.php' ) as $file ) {
	require $file;
}

printf( "%d assertions passed, %d failed\n", T::$pass, count( T::$fail ) );
foreach ( T::$fail as $f ) {
	echo "  ✗ $f\n";
}
exit( T::$fail ? 1 : 0 );
