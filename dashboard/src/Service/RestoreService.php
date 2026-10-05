<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;

/**
 * In einem Sicherungspunkt bloettern und einzelne Dateien herausholen.
 *
 * Bewusst nur lesend. Eine ganze Seite per Knopfdruck zurueckzuschreiben
 * waere der eine Knopf, bei dem ein Fehlklick den Kunden kostet — dafuer
 * braucht es Zwischenschritte, die es hier noch nicht gibt.
 *
 * Zwei Dinge haengen hier an der Sicherheit:
 *
 *  1. Ein Sicherungspunkt gehoert zu einer Seite. Wer Seite A sehen darf,
 *     darf nicht die Sicherung von Seite B herunterladen — darum wird jeder
 *     Punkt gegen den Wirtsnamen der Seite geprueft.
 *  2. Der Pfad kommt aus der Adresszeile. Ohne Pruefung liesse sich damit
 *     aus dem Sicherungspunkt heraus auf alles zeigen, was der Server
 *     sonst noch gesichert hat.
 */
final class RestoreService {

	/**
	 * Gehoert dieser Sicherungspunkt zu dieser Seite?
	 *
	 * @param array<string,mixed> $site
	 */
	public static function belongsToSite( array $site, string $snapshot ): bool {
		if ( ! preg_match( '/^[a-f0-9]{8,64}$/i', $snapshot ) ) {
			return false;
		}

		foreach ( Restic::snapshots( BackupService::hostFor( $site ) ) as $eintrag ) {
			$id    = (string) ( $eintrag['id'] ?? '' );
			$kurz  = (string) ( $eintrag['short_id'] ?? '' );

			if ( 0 === strcasecmp( $snapshot, $id ) || 0 === strcasecmp( $snapshot, $kurz ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Einen vom Benutzer gegebenen Pfad auf den Sicherungspunkt abbilden.
	 *
	 * Rueckgabe ist der volle Pfad im Sicherungspunkt — oder null, wenn der
	 * Pfad aus der Wurzel herausfuehrt.
	 */
	public static function resolve( string $root, string $relative ): ?string {
		$root     = rtrim( $root, '/' );
		$relative = trim( str_replace( '\\', '/', $relative ) );
		$relative = ltrim( $relative, '/' );

		if ( '' === $relative ) {
			return $root;
		}

		$teile = array();

		foreach ( explode( '/', $relative ) as $stueck ) {
			if ( '' === $stueck || '.' === $stueck ) {
				continue;
			}

			// Kein Aufstieg. Ein ".." heisst hier nicht "eine Ebene hoeher",
			// sondern "jemand versucht etwas" — darum abweisen statt
			// stillschweigend zu klettern.
			if ( '..' === $stueck ) {
				return null;
			}

			$teile[] = $stueck;
		}

		if ( ! $teile ) {
			return $root;
		}

		return $root . '/' . implode( '/', $teile );
	}

	/**
	 * Eine Ebene auflisten.
	 *
	 * @return array{ok:bool,error:string,root:string,path:string,entries:array<int,array<string,mixed>>,crumbs:array<int,array<string,string>>}
	 */
	public static function browse( int $siteId, string $snapshot, string $relative ): array {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return self::fail( 'Seite nicht gefunden.' );
		}

		if ( ! self::belongsToSite( $site, $snapshot ) ) {
			return self::fail( 'Dieser Sicherungspunkt gehört nicht zu dieser Seite.' );
		}

		$root = Restic::snapshotRoot( $snapshot );

		if ( null === $root ) {
			return self::fail( 'Der Sicherungspunkt lässt sich nicht lesen.' );
		}

		$voll = self::resolve( $root, $relative );

		if ( null === $voll ) {
			return self::fail( 'Dieser Pfad führt aus dem Sicherungspunkt heraus.' );
		}

		$liste = Restic::ls( $snapshot, $voll );

		if ( ! $liste['ok'] ) {
			return self::fail( $liste['error'] );
		}

		// Die Pfade kuerzen: der Kunde sieht "wp-content/uploads" und nicht
		// "/var/www/northlab/storage/backups/site-7/wp-content/uploads".
		$entries = array();

		foreach ( $liste['entries'] as $eintrag ) {
			$eintrag['relative'] = ltrim( substr( (string) $eintrag['path'], strlen( $root ) ), '/' );
			$entries[]           = $eintrag;
		}

		return array(
			'ok'      => true,
			'error'   => '',
			'root'    => $root,
			'path'    => ltrim( substr( $voll, strlen( $root ) ), '/' ),
			'entries' => $entries,
			'crumbs'  => self::crumbs( ltrim( substr( $voll, strlen( $root ) ), '/' ) ),
		);
	}

	/**
	 * Pfadstuecke fuer die Navigation.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function crumbs( string $relative ): array {
		$crumbs = array( array( 'name' => 'Sicherung', 'path' => '' ) );
		$bisher = array();

		foreach ( explode( '/', trim( $relative, '/' ) ) as $stueck ) {
			if ( '' === $stueck ) {
				continue;
			}

			$bisher[]  = $stueck;
			$crumbs[]  = array( 'name' => $stueck, 'path' => implode( '/', $bisher ) );
		}

		return $crumbs;
	}

	/**
	 * Eine Datei oder einen Ordner herausgeben.
	 *
	 * @param callable $onChunk fn(string): void — wird erst aufgerufen, wenn
	 *                          wirklich Inhalt kommt.
	 * @return array{ok:bool,error:string,bytes:int,filename:string}
	 */
	public static function download( int $siteId, string $snapshot, string $relative, bool $asArchive, callable $onChunk ): array {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return self::downloadFail( 'Seite nicht gefunden.' );
		}

		if ( ! self::belongsToSite( $site, $snapshot ) ) {
			return self::downloadFail( 'Dieser Sicherungspunkt gehört nicht zu dieser Seite.' );
		}

		$root = Restic::snapshotRoot( $snapshot );

		if ( null === $root ) {
			return self::downloadFail( 'Der Sicherungspunkt lässt sich nicht lesen.' );
		}

		$voll = self::resolve( $root, $relative );

		if ( null === $voll ) {
			return self::downloadFail( 'Dieser Pfad führt aus dem Sicherungspunkt heraus.' );
		}

		$name = self::filename( $relative, $asArchive );

		ActivityRepository::log(
			'site.restore',
			sprintf( 'Aus der Sicherung geholt: %s', '' === $relative ? '(ganze Sicherung)' : $relative ),
			array( 'site_id' => $siteId, 'context' => array( 'snapshot' => $snapshot ) )
		);

		$ergebnis = Restic::dump( $snapshot, $voll, $asArchive ? 'tar' : null, $onChunk );

		return array(
			'ok'       => $ergebnis['ok'],
			'error'    => $ergebnis['error'],
			'bytes'    => $ergebnis['bytes'],
			'filename' => $name,
		);
	}

	/**
	 * Dateiname fuer den Download.
	 */
	public static function filename( string $relative, bool $asArchive ): string {
		$basis = basename( rtrim( str_replace( '\\', '/', $relative ), '/' ) );

		if ( '' === $basis || '.' === $basis ) {
			$basis = 'sicherung';
		}

		// Alles, was in einem Content-Disposition stoeren koennte, faellt raus.
		$basis = (string) preg_replace( '/[^\w.\- ]+/u', '_', $basis );
		$basis = trim( (string) preg_replace( '/\s+/', ' ', $basis ) );

		if ( '' === $basis ) {
			$basis = 'sicherung';
		}

		return $asArchive ? $basis . '.tar' : $basis;
	}

	/**
	 * @return array{ok:bool,error:string,root:string,path:string,entries:array<int,mixed>,crumbs:array<int,mixed>}
	 */
	private static function fail( string $error ): array {
		return array( 'ok' => false, 'error' => $error, 'root' => '', 'path' => '', 'entries' => array(), 'crumbs' => array() );
	}

	/**
	 * @return array{ok:bool,error:string,bytes:int,filename:string}
	 */
	private static function downloadFail( string $error ): array {
		return array( 'ok' => false, 'error' => $error, 'bytes' => 0, 'filename' => '' );
	}
}
