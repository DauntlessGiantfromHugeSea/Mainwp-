<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Repository\ClientRepository;
use NorthLab\Repository\SiteRepository;
use NorthLab\Repository\UpdateRepository;
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
			)
		);
	}

	/**
	 * Updates einspielen — einzeln, gruppiert oder alles.
	 */
	public function apply( Request $request ): void {
		Auth::requireWrite();

		$mode = $request->string( 'mode', 'selected' );

		switch ( $mode ) {
			case 'all':
				$results = UpdateService::applyEverything( Auth::visibleSiteIds() );
				$this->respond( $request, true, $this->summarize( $results ), $this->back( $request, '/updates' ), array( 'results' => $results ) );
				// no break — respond() beendet die Ausführung.

			case 'group':
				$type = $request->string( 'type' );
				$slug = $request->string( 'slug' );

				if ( '' === $type || '' === $slug ) {
					$this->respond( $request, false, 'Typ und Slug fehlen.', $this->back( $request, '/updates' ) );
				}

				$results = UpdateService::applyAcrossSites( $type, $slug, Auth::visibleSiteIds() );
				$this->respond( $request, true, $this->summarize( $results ), $this->back( $request, '/updates' ), array( 'results' => $results ) );

			case 'site':
				$siteId = $request->int( 'site_id' );
				$site   = Auth::canSeeSite( $siteId ) ? SiteRepository::find( $siteId ) : null;

				if ( null === $site ) {
					$this->respond( $request, false, 'Seite nicht gefunden.', $this->back( $request, '/updates' ) );
				}

				$result = UpdateService::apply( $site );

				$this->respond(
					$request,
					$result['ok'],
					$result['ok']
						? sprintf( '%s: %d Update(s) eingespielt, %d fehlgeschlagen.', $site['name'], $result['succeeded'], $result['failed'] )
						: $result['error'],
					$this->back( $request, '/updates' ),
					array( 'results' => $result['results'] )
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

					$result = UpdateService::apply( $site, $items );

					$results[] = array(
						'site'    => (string) $site['name'],
						'success' => $result['ok'] && 0 === $result['failed'],
						'message' => $result['ok']
							? sprintf( '%d von %d', $result['succeeded'], count( $items ) )
							: $result['error'],
					);
				}

				$this->respond( $request, true, $this->summarize( $results ), $this->back( $request, '/updates' ), array( 'results' => $results ) );
		}
	}
}
