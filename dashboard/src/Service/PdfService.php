<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Logger;
use NorthLab\Core\Setting;

/**
 * HTML zu PDF — mit dem, was auf dem Server vorhanden ist.
 *
 * Bewusst keine PDF-Bibliothek: das Projekt kommt ohne Composer aus, und ein
 * mitgelieferter PDF-Schreiber koennte das Layout der Berichte nicht
 * wiedergeben. Stattdessen druckt ein Browser oder wkhtmltopdf genau das,
 * was auch am Bildschirm steht.
 *
 * Ist nichts davon da, wird das gesagt und nicht geraten — im Bericht steht
 * ein Druckknopf, mit dem jeder Browser ein PDF erzeugt.
 */
final class PdfService {

	/** Reihenfolge der Suche. Chromium zuerst: es kann modernes CSS. */
	private const KANDIDATEN = array(
		'chromium'         => 'chromium',
		'chromium-browser' => 'chromium',
		'google-chrome'    => 'chromium',
		'google-chrome-stable' => 'chromium',
		'wkhtmltopdf'      => 'wkhtmltopdf',
	);

	/**
	 * Welches Programm benutzt wird — oder null, wenn keines da ist.
	 *
	 * @return array{binary:string,kind:string}|null
	 */
	public static function renderer(): ?array {
		static $gefunden = false;
		static $ergebnis = null;

		if ( $gefunden ) {
			return $ergebnis;
		}

		$gefunden = true;

		// Eine ausdrueckliche Vorgabe schlaegt die Suche.
		$vorgabe = trim( Setting::get( 'pdf_binary', '' ) );

		if ( '' !== $vorgabe && is_executable( $vorgabe ) ) {
			$ergebnis = array(
				'binary' => $vorgabe,
				'kind'   => false !== stripos( $vorgabe, 'wkhtmltopdf' ) ? 'wkhtmltopdf' : 'chromium',
			);
			return $ergebnis;
		}

		foreach ( self::KANDIDATEN as $name => $art ) {
			$pfad = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' ) );

			if ( '' !== $pfad && is_executable( $pfad ) ) {
				$ergebnis = array( 'binary' => $pfad, 'kind' => $art );
				return $ergebnis;
			}
		}

		return $ergebnis;
	}

	public static function available(): bool {
		return null !== self::renderer();
	}

	/**
	 * Was zu tun ist, wenn keines der Programme da ist.
	 */
	public static function hint(): string {
		return 'Für PDF braucht der Server Chromium oder wkhtmltopdf: '
			. 'sudo apt install chromium  (oder: sudo apt install wkhtmltopdf). '
			. 'Ohne das bleibt der Druckknopf im Bericht — damit erzeugt jeder Browser ein PDF.';
	}

	/**
	 * HTML in eine PDF-Datei verwandeln.
	 *
	 * @return array{ok:bool,error:string,bytes:int}
	 */
	public static function fromHtml( string $html, string $target ): array {
		$programm = self::renderer();

		if ( null === $programm ) {
			return self::fail( 'Auf diesem Server ist kein PDF-Erzeuger installiert. ' . self::hint() );
		}

		$arbeit = self::workDir();

		if ( null === $arbeit ) {
			return self::fail( 'Das Arbeitsverzeichnis für die PDF-Erzeugung lässt sich nicht anlegen.' );
		}

		// Der Bericht enthaelt Kundendaten. Die Zwischendatei gehoert deshalb
		// nicht in ein allgemein lesbares /tmp, sondern neben die Anwendung —
		// und nur fuer den eigenen Benutzer lesbar.
		$quelle = $arbeit . '/' . bin2hex( random_bytes( 8 ) ) . '.html';

		if ( false === file_put_contents( $quelle, $html ) ) {
			return self::fail( 'Die Zwischendatei lässt sich nicht schreiben: ' . $quelle );
		}

		chmod( $quelle, 0600 );

		$profil = $arbeit . '/profil-' . bin2hex( random_bytes( 4 ) );

		$befehl = 'chromium' === $programm['kind']
			? self::chromiumCommand( $programm['binary'], $quelle, $target, $profil )
			: self::wkhtmltopdfCommand( $programm['binary'], $quelle, $target );

		$ausgabe = array();
		$code    = 0;

		exec( $befehl . ' 2>&1', $ausgabe, $code );

		@unlink( $quelle );
		self::removeDir( $profil );

		if ( ! is_file( $target ) || filesize( $target ) < 1000 ) {
			@unlink( $target );

			Logger::write( 'warning', 'PDF-Erzeugung fehlgeschlagen', array( 'code' => $code, 'ausgabe' => implode( "\n", $ausgabe ) ) );

			return self::fail(
				sprintf(
					'%s hat kein brauchbares PDF erzeugt (Rückgabewert %d). %s',
					basename( $programm['binary'] ),
					$code,
					self::lastLine( $ausgabe )
				)
			);
		}

		chmod( $target, 0600 );

		return array( 'ok' => true, 'error' => '', 'bytes' => (int) filesize( $target ) );
	}

	/**
	 * Chromium im Druckmodus.
	 *
	 * --no-sandbox ist noetig, weil Webserver haeufig als root laufen und
	 * Chromium sich dann weigert. Gedruckt wird ausschliesslich eine Datei,
	 * die dieses Panel selbst erzeugt hat — kein fremder Inhalt.
	 *
	 * --user-data-dir: ohne ein beschreibbares Profilverzeichnis bricht
	 * Chromium ab, wenn das Heimatverzeichnis des Webserver-Benutzers nicht
	 * beschreibbar ist. Das ist bei www-data der Normalfall.
	 */
	private static function chromiumCommand( string $binary, string $quelle, string $target, string $profil ): string {
		$flags = array(
			'--headless',
			'--disable-gpu',
			'--no-sandbox',
			'--disable-dev-shm-usage',
			'--no-pdf-header-footer',
			'--run-all-compositor-stages-before-draw',
			'--virtual-time-budget=10000',
			'--user-data-dir=' . escapeshellarg( $profil ),
			'--print-to-pdf=' . escapeshellarg( $target ),
			escapeshellarg( 'file://' . $quelle ),
		);

		return 'timeout 60 ' . escapeshellcmd( $binary ) . ' ' . implode( ' ', $flags );
	}

	private static function wkhtmltopdfCommand( string $binary, string $quelle, string $target ): string {
		return 'timeout 60 ' . escapeshellcmd( $binary )
			. ' --quiet --encoding utf-8 --enable-local-file-access'
			. ' --margin-top 14mm --margin-bottom 14mm --margin-left 12mm --margin-right 12mm '
			. escapeshellarg( $quelle ) . ' ' . escapeshellarg( $target );
	}

	/**
	 * Verzeichnis fuer Zwischendateien.
	 */
	public static function workDir(): ?string {
		$pfad = rtrim( NL_STORAGE, '/' ) . '/tmp/pdf';

		if ( ! is_dir( $pfad ) && ! mkdir( $pfad, 0700, true ) && ! is_dir( $pfad ) ) {
			return null;
		}

		return $pfad;
	}

	/**
	 * @param array<int,string> $zeilen
	 */
	private static function lastLine( array $zeilen ): string {
		$gefiltert = array_values( array_filter( array_map( 'trim', $zeilen ), static fn( string $z ): bool => '' !== $z ) );

		return $gefiltert ? (string) end( $gefiltert ) : '';
	}

	private static function removeDir( string $pfad ): void {
		if ( ! is_dir( $pfad ) ) {
			return;
		}

		exec( 'rm -rf ' . escapeshellarg( $pfad ) );
	}

	/**
	 * @return array{ok:bool,error:string,bytes:int}
	 */
	private static function fail( string $error ): array {
		return array( 'ok' => false, 'error' => $error, 'bytes' => 0 );
	}
}
