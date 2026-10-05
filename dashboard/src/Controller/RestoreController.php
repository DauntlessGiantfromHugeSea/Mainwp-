<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Repository\SiteRepository;
use NorthLab\Service\BackupService;
use NorthLab\Service\Restic;
use NorthLab\Service\RestoreService;

/**
 * In einer Sicherung bloettern und einzelne Dateien herausholen.
 */
final class RestoreController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireLogin();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			Response::notFound( 'Seite nicht gefunden.' );
			return;
		}

		$snapshots = Restic::configured() && Restic::available()
			? Restic::snapshots( BackupService::hostFor( $site ) )
			: array();

		// Neueste zuerst — danach sucht man.
		usort(
			$snapshots,
			static fn( array $a, array $b ): int => strcmp( (string) ( $b['time'] ?? '' ), (string) ( $a['time'] ?? '' ) )
		);

		$snapshot = trim( $request->string( 'snapshot' ) );
		$pfad     = $request->string( 'path' );
		$ansicht  = array( 'ok' => false, 'error' => '', 'entries' => array(), 'crumbs' => array(), 'path' => '' );

		if ( '' !== $snapshot ) {
			$ansicht = RestoreService::browse( $siteId, $snapshot, $pfad );
		}

		$this->view(
			'backups/restore',
			array(
				'site'      => $site,
				'snapshots' => $snapshots,
				'snapshot'  => $snapshot,
				'view'      => $ansicht,
				'ready'     => Restic::configured() && Restic::available(),
			)
		);
	}

	/**
	 * Eine Datei oder einen Ordner herausgeben.
	 */
	public function download( Request $request ): void {
		Auth::requireLogin();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		$snapshot = trim( $request->string( 'snapshot' ) );
		$pfad     = $request->string( 'path' );
		$archiv   = $request->bool( 'archive' );

		$zurueck = '/sites/' . $siteId . '/restore?snapshot=' . rawurlencode( $snapshot );

		if ( '' === $snapshot ) {
			$this->respond( $request, false, 'Es wurde kein Sicherungspunkt angegeben.', $zurueck );
		}

		// Erst beim ersten Stueck werden die Kopfzeilen geschickt. Vorher
		// koennte noch ein Fehler kommen, und dann soll eine Meldung
		// erscheinen und keine halbe Datei im Download-Ordner landen.
		$gestartet = false;
		$name      = RestoreService::filename( $pfad, $archiv );

		$senden = static function ( string $stueck ) use ( &$gestartet, $name, $archiv ): void {
			if ( ! $gestartet ) {
				$gestartet = true;

				while ( ob_get_level() > 0 ) {
					ob_end_clean();
				}

				header( 'Content-Type: ' . ( $archiv ? 'application/x-tar' : 'application/octet-stream' ) );
				header( 'Content-Disposition: attachment; filename="' . $name . '"' );
				header( 'X-Content-Type-Options: nosniff' );
				// Die Laenge steht nicht fest — restic liefert im Fluss.
				header( 'X-Accel-Buffering: no' );
			}

			echo $stueck;
			flush();
		};

		// Grosse Mediatheken dauern. Der Abbruch durch den Browser soll den
		// Lauf beenden, ein Zeitlimit des Webservers aber nicht.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		$ergebnis = RestoreService::download( $siteId, $snapshot, $pfad, $archiv, $senden );

		if ( $gestartet ) {
			exit;
		}

		$this->respond( $request, false, $ergebnis['error'] ?: 'Es kam nichts aus der Sicherung.', $zurueck );
	}
}
