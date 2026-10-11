<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Repository\ClientRepository;
use NorthLab\Repository\SiteRepository;
use NorthLab\Repository\UpdateRepository;
use NorthLab\Service\Scheduler;
use NorthLab\Service\UpdateQueue;
use NorthLab\Service\UpdateService;

final class UpdateController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireLogin();

		$filters = array(
			'client_id'       => $request->int( 'client_id' ),
			'type'            => $request->string( 'type' ),
			'search'          => $request->string( 'q' ),
			'include_ignored' => $request->bool( 'ignored' ),
			'site_ids'        => Auth::visibleSiteIds(),
		);

		$this->view(
			'updates/index',
			array(
				'grouped'  => UpdateRepository::grouped( $filters ),
				'rows'     => UpdateRepository::query( $filters ),
				'counts'   => UpdateRepository::countsByType( Auth::visibleSiteIds() ),
				'clients'  => ClientRepository::options(),
				'filters'  => $filters,
				'sites'    => SiteRepository::all( array( 'has_updates' => true, 'site_ids' => Auth::visibleSiteIds() ) ),
				'excludes' => UpdateService::excludes(),
				'queue'    => UpdateQueue::status( Auth::visibleSiteIds() ),
			)
		);
	}

	/**
	 * Updates vormerken — einzeln, gruppiert oder alles.
	 *
	 * Eingespielt wird nicht hier. Ein Update dauert auf der Kundenseite
	 * Sekunden bis Minuten je Paket; lief das im Webaufruf, hing der Browser
	 * so lange, ein PHP-Arbeiter war belegt, und bei "alles einspielen" ueber
	 * neun Seiten lief die Anfrage ins Zeitlimit — danach wusste niemand, was
	 * durchgelaufen war. Jetzt macht es der Zeitplaner, genau wie bei den
	 * Sicherungen, und die Seite zeigt den Fortschritt.
	 */
	public function apply( Request $request ): void {
		Auth::requireWrite();

		if ( ! Scheduler::isCronHealthy() ) {
			$this->respond(
				$request,
				false,
				'Der Zeitplaner läuft nicht — ein vorgemerktes Update würde liegen bleiben. '
					. 'Bitte erst den Cron-Aufruf in Ordnung bringen.',
				$this->back( $request, '/updates' )
			);
		}

		$mode = $request->string( 'mode', 'selected' );

		switch ( $mode ) {
			case 'all':
				$results = UpdateQueue::requestMany( $this->sitesWithUpdates() );
				$this->respond( $request, true, $this->summarize( $results ), $this->back( $request, '/updates' ), array( 'results' => $results ) );
				// no break — respond() beendet die Ausführung.

			case 'group':
				$type = $request->string( 'type' );
				$slug = $request->string( 'slug' );

				if ( '' === $type || '' === $slug ) {
					$this->respond( $request, false, 'Typ und Slug fehlen.', $this->back( $request, '/updates' ) );
				}

				$results = array();

				foreach ( UpdateRepository::query( array( 'type' => $type, 'site_ids' => Auth::visibleSiteIds() ) ) as $row ) {
					if ( (string) $row['slug'] !== $slug ) {
						continue;
					}

					$ergebnis  = UpdateQueue::request( (int) $row['site_id'], array( array( 'type' => $type, 'slug' => $slug ) ) );
					$results[] = array(
						'site'    => (string) $row['site_name'],
						'success' => $ergebnis['ok'],
						'message' => $ergebnis['ok'] ? 'vorgemerkt' : $ergebnis['error'],
					);
				}

				$this->respond( $request, true, $this->summarize( $results ), $this->back( $request, '/updates' ), array( 'results' => $results ) );

			case 'site':
				$siteId = $request->int( 'site_id' );
				$site   = Auth::canSeeSite( $siteId ) ? SiteRepository::find( $siteId ) : null;

				if ( null === $site ) {
					$this->respond( $request, false, 'Seite nicht gefunden.', $this->back( $request, '/updates' ) );
				}

				$ergebnis = UpdateQueue::request( $siteId );

				$this->respond(
					$request,
					$ergebnis['ok'],
					$ergebnis['ok']
						? sprintf( '%s ist vorgemerkt — der Zeitplaner spielt die Updates gleich ein.', $site['name'] )
						: $ergebnis['error'],
					$this->back( $request, '/updates' )
				);

			case 'selected':
			default:
				$selection = $request->arrayOfStrings( 'items' );

				if ( ! $selection ) {
					$this->respond( $request, false, 'Bitte mindestens ein Update auswählen.', $this->back( $request, '/updates' ) );
				}

				$bySite = array();

				// Auswahlformat: "<site_id>|<type>|<slug>"
				foreach ( $selection as $entry ) {
					$parts = explode( '|', $entry, 3 );
					if ( 3 !== count( $parts ) ) {
						continue;
					}
					$bySite[ (int) $parts[0] ][] = array( 'type' => $parts[1], 'slug' => $parts[2] );
				}

				$results = array();

				foreach ( $bySite as $siteId => $items ) {
					$site = Auth::canSeeSite( (int) $siteId ) ? SiteRepository::find( (int) $siteId ) : null;
					if ( null === $site ) {
						continue;
					}

					$ergebnis = UpdateQueue::request( (int) $siteId, $items );

					$results[] = array(
						'site'    => (string) $site['name'],
						'success' => $ergebnis['ok'],
						'message' => $ergebnis['ok'] ? sprintf( '%d vorgemerkt', count( $items ) ) : $ergebnis['error'],
					);
				}

				$this->respond( $request, true, $this->summarize( $results ), $this->back( $request, '/updates' ), array( 'results' => $results ) );
		}
	}

	/**
	 * Stand der Warteschlange — die Seite fragt das in Abstaenden nach.
	 */
	public function status( Request $request ): void {
		Auth::requireLogin();

		Response::json( UpdateQueue::status( Auth::visibleSiteIds() ) );
	}

	/**
	 * Alle sichtbaren Seiten, auf denen etwas offen ist.
	 *
	 * @return array<int,int>
	 */
	private function sitesWithUpdates(): array {
		$ids = array();

		foreach ( SiteRepository::all( array( 'has_updates' => true, 'site_ids' => Auth::visibleSiteIds() ) ) as $site ) {
			if ( empty( $site['is_paused'] ) ) {
				$ids[] = (int) $site['id'];
			}
		}

		return $ids;
	}
}
