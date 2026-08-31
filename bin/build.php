<?php
/**
 * Build a clean, installable CatalogOps plugin zip.
 *
 * The deploy inputs live outside the public repo — `vendor/` (dev tooling),
 * `assets/dist/` (the built React bundle), and the `freemius/` SDK plus its
 * secret are all gitignored — so a release is assembled rather than checked out.
 * Doing that by hand is error-prone (short/long Windows paths, PowerShell writing
 * non-compliant backslash zip separators, forgetting the no-dev autoloader), so
 * this script does it deterministically:
 *
 *   1. Read the version from the plugin header (the single source of truth).
 *   2. Verify the built bundle is present (optionally run `npm run build` first).
 *   3. Stage an allowlist of runtime files into a temp dir — never a denylist,
 *      so nothing dev-only can leak in.
 *   4. Generate a production (`--no-dev`) Composer autoloader in the staging tree,
 *      leaving the working `vendor/` untouched.
 *   5. Zip via PHP's ZipArchive, which writes spec-compliant forward-slash entries
 *      that WordPress' unzip accepts — under a single top-level `catalogops/` dir.
 *
 * Usage (from the repo root or anywhere):
 *   php bin/build.php [--variant=premium|free] [--build] [--output=PATH]
 *
 *   --variant=premium  (default) Bundle the Freemius SDK + secret, so the zip
 *                      behaves exactly like production and licensing is live —
 *                      the build to test Free/Solo/Studio gating against.
 *   --variant=free     Omit the SDK; License::resolve() falls back to unlimited
 *                      (mirrors the SDK-absent path, e.g. the wp.org build).
 *   --build            Run `npm run build` before packaging, so the bundle is fresh.
 *   --output=PATH      Write the zip here instead of dist/catalogops-<version>.zip.
 *
 * @package CatalogOps\Build
 */

// This is a CLI build tool, not part of the shipped plugin.
if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "build.php must be run from the command line.\n" );
	exit( 1 );
}

$root = dirname( __DIR__ );

/**
 * Print a status line.
 *
 * @param string $msg Message.
 */
function co_say( string $msg ): void {
	fwrite( STDOUT, $msg . "\n" );
}

/**
 * Print a warning (non-fatal).
 *
 * @param string $msg Message.
 */
function co_warn( string $msg ): void {
	fwrite( STDERR, 'WARN: ' . $msg . "\n" );
}

/**
 * Print an error and exit.
 *
 * @param string $msg Message.
 */
function co_fail( string $msg ): void {
	fwrite( STDERR, 'ERROR: ' . $msg . "\n" );
	exit( 1 );
}

/**
 * Parse a `--key=value` / `--flag` argument list into a map. Bare flags map to true.
 *
 * @param array<int, string> $argv Raw argv (script name already shifted off).
 * @return array<string, string|bool>
 */
function co_parse_args( array $argv ): array {
	$args = array();

	foreach ( $argv as $arg ) {
		if ( '--' !== substr( $arg, 0, 2 ) ) {
			continue;
		}

		$arg = substr( $arg, 2 );
		$eq  = strpos( $arg, '=' );

		if ( false === $eq ) {
			$args[ $arg ] = true;
		} else {
			$args[ substr( $arg, 0, $eq ) ] = substr( $arg, $eq + 1 );
		}
	}

	return $args;
}

/**
 * Recursively copy a file or directory.
 *
 * @param string $src Source path.
 * @param string $dst Destination path.
 */
function co_copy( string $src, string $dst ): void {
	if ( is_file( $src ) ) {
		$dir = dirname( $dst );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		if ( ! copy( $src, $dst ) ) {
			co_fail( "Failed to copy $src" );
		}
		return;
	}

	if ( ! is_dir( $src ) ) {
		return;
	}

	if ( ! is_dir( $dst ) ) {
		mkdir( $dst, 0777, true );
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $items as $item ) {
		$target = $dst . DIRECTORY_SEPARATOR . $items->getSubPathName();
		if ( $item->isDir() ) {
			if ( ! is_dir( $target ) ) {
				mkdir( $target, 0777, true );
			}
		} elseif ( ! copy( $item->getPathname(), $target ) ) {
			co_fail( 'Failed to copy ' . $item->getPathname() );
		}
	}
}

/**
 * Recursively delete a directory.
 *
 * @param string $dir Directory to remove.
 */
function co_rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() );
		} else {
			unlink( $item->getPathname() );
		}
	}

	rmdir( $dir );
}

/**
 * Add a staged directory tree to a zip under a base prefix, using forward-slash
 * entry names (ZIP-spec compliant, which WordPress' unzip requires).
 *
 * @param ZipArchive $zip    Open archive.
 * @param string     $dir    Directory to add.
 * @param string     $prefix Entry-name prefix (e.g. "catalogops").
 */
function co_zip_dir( ZipArchive $zip, string $dir, string $prefix ): void {
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $items as $item ) {
		$rel   = str_replace( DIRECTORY_SEPARATOR, '/', $items->getSubPathName() );
		$entry = $prefix . '/' . $rel;

		if ( $item->isDir() ) {
			$zip->addEmptyDir( $entry );
		} else {
			$zip->addFile( $item->getPathname(), $entry );
		}
	}
}

// --- Parse arguments -------------------------------------------------------

$args = co_parse_args( array_slice( $argv, 1 ) );

if ( isset( $args['help'] ) || isset( $args['h'] ) ) {
	co_say( 'Usage: php bin/build.php [--variant=premium|free] [--build] [--output=PATH]' );
	co_say( '' );
	co_say( '  --variant=premium  (default) bundle the Freemius SDK + secret (licensing live)' );
	co_say( '  --variant=free     omit the SDK (License resolves to unlimited)' );
	co_say( '  --build            run `npm run build` before packaging' );
	co_say( '  --output=PATH      output zip path (default dist/catalogops-<version>.zip)' );
	exit( 0 );
}

$variant = isset( $args['variant'] ) ? (string) $args['variant'] : 'premium';

if ( ! in_array( $variant, array( 'premium', 'free' ), true ) ) {
	co_fail( "Unknown --variant '$variant' (expected premium or free)." );
}

// --- Read the version from the plugin header -------------------------------

$main = $root . '/catalogops.php';

if ( ! is_readable( $main ) ) {
	co_fail( "catalogops.php not found at $main" );
}

if ( ! preg_match( '/^\s*\*?\s*Version:\s*(.+)$/mi', (string) file_get_contents( $main ), $m ) ) {
	co_fail( 'Could not read the Version header from catalogops.php.' );
}

$version = trim( $m[1] );
co_say( "CatalogOps build — version $version, variant $variant" );

// --- Optionally build the front-end bundle ---------------------------------

if ( isset( $args['build'] ) ) {
	co_say( 'Running `npm run build`…' );
	$code = 0;
	passthru( 'npm run build --prefix ' . escapeshellarg( $root ), $code );
	if ( 0 !== $code ) {
		co_fail( '`npm run build` failed.' );
	}
}

// --- Verify the built bundle is present ------------------------------------

foreach ( array( 'assets/dist/admin.js', 'assets/dist/admin.asset.php' ) as $required ) {
	if ( ! is_readable( $root . '/' . $required ) ) {
		co_fail( "Missing $required. Run `npm run build` (or pass --build) first." );
	}
}

// --- Stage the runtime allowlist -------------------------------------------

$stage_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'catalogops-build-' . getmypid();
$stage      = $stage_root . DIRECTORY_SEPARATOR . 'catalogops';

co_rrmdir( $stage_root );
mkdir( $stage, 0777, true );

// Files and directories that ship at runtime. An allowlist, so tests/, node_modules/,
// assets/src/, and dot-dirs can never leak into a release.
$include = array(
	'catalogops.php',
	'uninstall.php',
	'readme.txt',
	'src',
	'languages',
	'assets/dist',
	'assets/menu-icon.svg',
);

// The premium build bundles the licensing backend; the free build omits it.
if ( 'premium' === $variant ) {
	$include[] = 'freemius';
	$include[] = 'freemius-secret.php';
}

$sdk_bundled = false;

foreach ( $include as $rel ) {
	$src = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );

	if ( ! file_exists( $src ) ) {
		// The SDK and its secret are gitignored; warn but don't fail — the plugin
		// runs without them (License resolves to unlimited).
		if ( 'freemius' === $rel || 'freemius-secret.php' === $rel ) {
			co_warn( "$rel not found — the premium build will behave as SDK-absent (unlimited)." );
			continue;
		}
		if ( 'readme.txt' === $rel ) {
			continue;
		}
		co_fail( "Required path missing: $rel" );
	}

	if ( 'freemius' === $rel ) {
		$sdk_bundled = true;
	}

	co_copy( $src, $stage . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel ) );
}

co_say( 'Staged runtime files.' );

// --- Generate a production autoloader --------------------------------------

// The plugin refuses to boot without vendor/autoload.php. It has no runtime
// third-party deps (composer require = php only), so a --no-dev dump yields a
// tiny autoloader for the plugin's own classes and none of the dev tooling.
copy( $root . '/composer.json', $stage . '/composer.json' );

// A bare `composer` must stay unquoted: on Windows the composer.bat shim uses
// %~dp0 to find composer.phar, and wrapping the command name in quotes breaks
// that (it then looks for composer.phar in the cwd). Only an explicit path
// override is quoted, since it may contain spaces.
$composer_bin = getenv( 'COMPOSER_BIN' );
$composer     = $composer_bin ? escapeshellarg( $composer_bin ) : 'composer';
$cmd          = $composer
	. ' dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction'
	. ' --working-dir=' . escapeshellarg( $stage ) . ' 2>&1';

$lines = array();
$code  = 0;
exec( $cmd, $lines, $code );

if ( 0 === $code && is_readable( $stage . '/vendor/autoload.php' ) ) {
	co_say( 'Generated production autoloader (composer --no-dev).' );
} else {
	// Fallback: no composer on PATH. Copy the working autoloader as-is; since
	// there are no runtime deps, the plugin's own classmap still resolves.
	co_warn( 'composer dump-autoload unavailable; copying the existing vendor autoloader.' );

	if ( ! is_readable( $root . '/vendor/autoload.php' ) ) {
		co_fail( 'No vendor/autoload.php to fall back to. Run `composer install` first.' );
	}

	co_copy( $root . '/vendor/autoload.php', $stage . '/vendor/autoload.php' );
	co_copy( $root . '/vendor/composer', $stage . '/vendor/composer' );
}

// composer.json is a build input, not a runtime file.
unlink( $stage . '/composer.json' );

// --- Zip -------------------------------------------------------------------

$suffix   = 'free' === $variant ? '-free' : '';
$dist_dir = $root . '/dist';

if ( isset( $args['output'] ) ) {
	$out = (string) $args['output'];
} else {
	$out = $dist_dir . '/catalogops-' . $version . $suffix . '.zip';
}

$out_dir = dirname( $out );
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0777, true );
}

if ( file_exists( $out ) ) {
	unlink( $out );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $out, ZipArchive::CREATE ) ) {
	co_fail( "Could not create zip at $out" );
}

co_zip_dir( $zip, $stage, 'catalogops' );
$entries = $zip->numFiles;
$zip->close();

// --- Clean up + report -----------------------------------------------------

co_rrmdir( $stage_root );

$size_mb = round( filesize( $out ) / 1048576, 2 );

co_say( '' );
co_say( 'Built ' . $out );
co_say( "  variant:     $variant" . ( 'premium' === $variant ? ( $sdk_bundled ? ' (Freemius SDK bundled)' : ' (SDK absent → unlimited)' ) : '' ) );
co_say( "  entries:     $entries" );
co_say( "  size:        {$size_mb} MB" );
co_say( '  install:     WP Admin → Plugins → Add New → Upload Plugin' );
