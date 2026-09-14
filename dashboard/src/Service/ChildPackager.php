<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use RuntimeException;
use ZipArchive;

/**
 * Packt das NorthLab-Child-Plugin als ZIP zum Download.
 *
 * Nutzt ZipArchive, wenn vorhanden, sonst einen eigenen ZIP-Writer —
 * so funktioniert der Download auch ohne die PHP-Erweiterung "zip".
 */
final class ChildPackager {

	private const SOURCE_DIR = 'child-plugin/north-lab-child';

	private const FOLDER = 'north-lab-child';

	public static function sourcePath(): string {
		return NL_RESOURCES . '/' . self::SOURCE_DIR;
	}

	/**
	 * Version aus dem Plugin-Header.
	 */
	public static function version(): string {
		$main = self::sourcePath() . '/north-lab-child.php';

		if ( is_file( $main ) ) {
			$head = (string) file_get_contents( $main, false, null, 0, 2048 );
			if ( preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $head, $m ) ) {
				return trim( $m[1] );
			}
		}

		return NL_VERSION;
	}

	public static function filename(): string {
		return 'northlab-child-' . self::version() . '.zip';
	}

	/**
	 * Pruefsumme des fertigen Pakets.
	 *
	 * Sie wandert signiert ins Manifest; die Kundenseite prueft damit den
	 * Download, bevor sie ihn auspackt.
	 */
	public static function sha256(): string {
		return (string) hash_file( 'sha256', self::build() );
	}

	/**
	 * Erzeugt das Archiv (gecacht) und liefert den Pfad.
	 */
	public static function build( bool $force = false ): string {
		$source = self::sourcePath();

		if ( ! is_dir( $source ) ) {
			throw new RuntimeException( 'Quellverzeichnis des Child-Plugins fehlt: ' . $source );
		}

		$target = NL_STORAGE . '/tmp/' . self::filename();

		if ( ! $force && is_file( $target ) && filemtime( $target ) >= self::newestSourceTime( $source ) ) {
			return $target;
		}

		if ( ! is_dir( dirname( $target ) ) ) {
			mkdir( dirname( $target ), 0750, true );
		}

		$files = self::collect( $source );

		if ( class_exists( ZipArchive::class ) ) {
			self::writeWithZipArchive( $target, $files );
		} else {
			self::writeManually( $target, $files );
		}

		return $target;
	}

	/**
	 * Relativer Pfad im Archiv => absoluter Pfad auf der Platte.
	 *
	 * @return array<string,string>
	 */
	private static function collect( string $source ): array {
		$files = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			/** @var \SplFileInfo $item */
			if ( ! $item->isFile() ) {
				continue;
			}

			$relative = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $source ) ) ), '/' );

			// Entwicklungsartefakte gehören nicht ins Auslieferungspaket.
			if ( preg_match( '#(^|/)(\.git|\.DS_Store|node_modules|\.idea)(/|$)#', $relative ) ) {
				continue;
			}

			$files[ self::FOLDER . '/' . $relative ] = $item->getPathname();
		}

		ksort( $files );

		return $files;
	}

	private static function newestSourceTime( string $source ): int {
		$newest = 0;

		foreach ( self::collect( $source ) as $path ) {
			$newest = max( $newest, (int) filemtime( $path ) );
		}

		return $newest;
	}

	/**
	 * @param array<string,string> $files
	 */
	private static function writeWithZipArchive( string $target, array $files ): void {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $target, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'ZIP-Archiv konnte nicht angelegt werden: ' . $target );
		}

		foreach ( $files as $relative => $absolute ) {
			$zip->addFile( $absolute, $relative );
		}

		$zip->close();
	}

	/**
	 * Minimaler ZIP-Writer (Deflate, keine Verzeichniseinträge) als Fallback.
	 *
	 * @param array<string,string> $files
	 */
	private static function writeManually( string $target, array $files ): void {
		$entries   = '';
		$central   = '';
		$offset    = 0;
		$count     = 0;
		$canDeflate = function_exists( 'gzdeflate' );

		foreach ( $files as $relative => $absolute ) {
			$content = (string) file_get_contents( $absolute );
			$crc     = crc32( $content );
			$sizeRaw = strlen( $content );

			$deflated = $canDeflate ? (string) gzdeflate( $content, 6 ) : $content;
			$method   = ( $canDeflate && strlen( $deflated ) < $sizeRaw ) ? 8 : 0;
			$payload  = 8 === $method ? $deflated : $content;
			$sizeComp = strlen( $payload );

			[ $dosTime, $dosDate ] = self::dosTimestamp( (int) filemtime( $absolute ) );

			$local = "\x50\x4b\x03\x04"
				. pack( 'v', 20 )        // benötigte Version
				. pack( 'v', 0 )         // Flags
				. pack( 'v', $method )
				. pack( 'v', $dosTime )
				. pack( 'v', $dosDate )
				. pack( 'V', $crc )
				. pack( 'V', $sizeComp )
				. pack( 'V', $sizeRaw )
				. pack( 'v', strlen( $relative ) )
				. pack( 'v', 0 )
				. $relative;

			$entries .= $local . $payload;

			$central .= "\x50\x4b\x01\x02"
				. pack( 'v', 0x031E )    // erzeugt unter Unix
				. pack( 'v', 20 )
				. pack( 'v', 0 )
				. pack( 'v', $method )
				. pack( 'v', $dosTime )
				. pack( 'v', $dosDate )
				. pack( 'V', $crc )
				. pack( 'V', $sizeComp )
				. pack( 'V', $sizeRaw )
				. pack( 'v', strlen( $relative ) )
				. pack( 'v', 0 )         // Extra
				. pack( 'v', 0 )         // Kommentar
				. pack( 'v', 0 )         // Datenträger
				. pack( 'v', 0 )         // interne Attribute
				. pack( 'V', 0x81A40000 ) // externe Attribute: 0644
				. pack( 'V', $offset )
				. $relative;

			$offset += strlen( $local ) + $sizeComp;
			$count++;
		}

		$end = "\x50\x4b\x05\x06"
			. pack( 'v', 0 )
			. pack( 'v', 0 )
			. pack( 'v', $count )
			. pack( 'v', $count )
			. pack( 'V', strlen( $central ) )
			. pack( 'V', $offset )
			. pack( 'v', 0 );

		if ( false === file_put_contents( $target, $entries . $central . $end, LOCK_EX ) ) {
			throw new RuntimeException( 'ZIP-Archiv konnte nicht geschrieben werden: ' . $target );
		}
	}

	/**
	 * @return array{0:int,1:int} DOS-Zeit und -Datum.
	 */
	private static function dosTimestamp( int $timestamp ): array {
		$parts = getdate( $timestamp > 315532800 ? $timestamp : time() );

		$time = ( $parts['hours'] << 11 ) | ( $parts['minutes'] << 5 ) | ( (int) ( $parts['seconds'] / 2 ) );
		$date = ( ( $parts['year'] - 1980 ) << 9 ) | ( $parts['mon'] << 5 ) | $parts['mday'];

		return array( $time, $date );
	}
}
