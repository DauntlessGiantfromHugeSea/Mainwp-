<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Core\Session;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\ClientRepository;
use NorthLab\Repository\SiteRepository;
use NorthLab\Repository\UpdateRepository;
use NorthLab\Repository\UptimeRepository;
use NorthLab\Service\ChildPackager;
use NorthLab\Service\BrandingService;
use NorthLab\Service\ChildFeature;
use NorthLab\Service\ChildPluginService;
use NorthLab\Service\MaintenanceModeService;
use NorthLab\Service\MaintenanceService;
use NorthLab\Service\SiteService;
use NorthLab\Service\SyncService;
use NorthLab\Service\UpdateService;

final class SiteController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireLogin();

		$filters = array(
			'client_id'      => $request->int( 'client_id' ),
			'status'         => $request->string( 'status' ),
			'uptime_status'  => $request->string( 'uptime' ),
			'site_type'      => $request->string( 'type' ),
			'search'         => $request->string( 'q' ),
			'tag'            => $request->string( 'tag' ),
			'has_updates'    => $request->bool( 'updates' ),
			'site_ids'       => Auth::visibleSiteIds(),
			'orderby'        => $request->string( 'orderby', 'name' ),
			'order'          => $request->string( 'order', 'ASC' ),
		);

		$this->view(
			'sites/index',
			array(
				'sites'   => SiteRepository::all( $filters ),
				'clients' => ClientRepository::options(),
				'tags'    => SiteRepository::allTags(),
				'filters' => $filters,
			)
		);
	}

	public function create( Request $request ): void {
		Auth::requireWrite();

		$this->view(
			'sites/create',
			array(
				'clients'       => ClientRepository::options(),
				'pluginVersion' => ChildPackager::version(),
			)
		);
	}

	public function store( Request $request ): void {
		Auth::requireWrite();

		$data = array(
			'name'               => $request->string( 'name' ),
			'url'                => $request->string( 'url' ),
			'connect_code'       => $request->string( 'connect_code' ),
			'client_id'          => $request->int( 'client_id' ),
			'tags'               => $request->string( 'tags' ),
			'notes'              => $request->string( 'notes' ),
			'auto_update_policy' => $request->string( 'auto_update_policy', 'inherit' ),
			'verify_ssl'         => $request->bool( 'verify_ssl' ),
			'http_user'          => $request->string( 'http_user' ),
			'http_pass'          => (string) $request->post( 'http_pass', '' ),
		);

		// Seiten ohne Child-Plugin (Shopify, Wix, Baukasten, fremdgehostet) werden
		// nur ueberwacht - dafuer reicht die URL, es gibt keinen Verbindungscode.
		$monitorOnly = 'monitor' === $request->string( 'site_type', 'wordpress' );

		[ $siteId, $error ] = $monitorOnly
			? SiteService::createMonitorOnly( $data )
			: SiteService::createAndConnect( $data );

		if ( 0 === $siteId ) {
			Session::flashInput( $request->all() );
			$this->respond( $request, false, (string) $error, '/sites/new' );
		}

		$this->respond(
			$request,
			true,
			$monitorOnly ? 'Seite zur Überwachung aufgenommen.' : 'Seite verbunden und synchronisiert.',
			'/sites/' . $siteId
		);
	}

	public function show( Request $request ): void {
		Auth::requireLogin();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			Response::notFound( 'Diese Seite ist nicht im Panel registriert.' );
			return;
		}

		$from = gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 );
		$to   = nl_utc();

		$this->view(
			'sites/show',
			array(
				'site'        => $site,
				'client'      => ! empty( $site['client_id'] ) ? ClientRepository::find( (int) $site['client_id'] ) : null,
				'clients'     => ClientRepository::options(),
				'payload'     => SiteRepository::payload( $siteId ),
				'updates'     => UpdateRepository::query( array( 'site_id' => $siteId, 'include_ignored' => true ) ),
				'uptime'      => UptimeRepository::availability( $siteId, $from, $to ),
				'incidents'   => array_slice( UptimeRepository::incidents( $siteId, $from, $to ), 0, 10 ),
				'series'      => UptimeRepository::dailySeries( $siteId, 30 ),
				'activity'    => ActivityRepository::query( array( 'site_id' => $siteId, 'limit' => 25 ) ),
				'monitorUrl'  => SiteService::monitorUrl( $site ),
				'tasks'       => MaintenanceService::tasks(),
				'features'     => ChildFeature::overview( $site ),
				'branding'      => BrandingService::design(),
				'brandingState' => BrandingService::stored( $siteId ),
				'childShipped' => ChildPluginService::shipped(),
				'childOutdated' => ChildPluginService::isOutdated( $site ),
				'mmodeDesign' => MaintenanceModeService::design(),
				'mmodeTimes'  => MaintenanceModeService::DURATIONS,
			)
		);
	}

	public function update( Request $request ): void {
		Auth::requireWrite();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		$error = SiteService::updateSettings(
			$siteId,
			array(
				'name'               => $request->string( 'name' ),
				'client_id'          => $request->int( 'client_id' ),
				'tags'               => $request->string( 'tags' ),
				'notes'              => $request->string( 'notes' ),
				'auto_update_policy' => $request->string( 'auto_update_policy', 'inherit' ),
				'is_paused'          => $request->bool( 'is_paused' ),
				'verify_ssl'         => $request->bool( 'verify_ssl' ),
				'http_user'          => $request->string( 'http_user' ),
				'http_pass'          => (string) $request->post( 'http_pass', '' ),
			)
		);

		$this->respond(
			$request,
			null === $error,
			$error ?? 'Einstellungen gespeichert.',
			'/sites/' . $siteId
		);
	}

	public function sync( Request $request ): void {
		Auth::requireWrite();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		$result = SyncService::site( $siteId, true );

		$this->respond(
			$request,
			$result['ok'],
			$result['ok'] ? 'Seite synchronisiert.' : 'Sync fehlgeschlagen: ' . $result['error'],
			'/sites/' . $siteId
		);
	}

	public function reconnect( Request $request ): void {
		Auth::requireWrite();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		$error = SiteService::reconnect( $siteId, $request->string( 'connect_code' ) );

		$this->respond(
			$request,
			null === $error,
			$error ?? 'Verbindung erneuert.',
			'/sites/' . $siteId
		);
	}

	public function destroy( Request $request ): void {
		Auth::requireWrite();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		$ok = SiteService::remove( $siteId );

		$this->respond( $request, $ok, $ok ? 'Seite entfernt.' : 'Seite nicht gefunden.', '/sites' );
	}

	public function maintenance( Request $request ): void {
		Auth::requireWrite();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		$result = MaintenanceService::run( $siteId, $request->arrayOfStrings( 'tasks' ) );

		$message = $result['ok']
			? sprintf( '%d Wartungsaufgabe(n) ausgeführt.', count( $result['results'] ) )
			: $result['error'];

		$this->respond( $request, $result['ok'], $message, '/sites/' . $siteId );
	}

	public function security( Request $request ): void {
		Auth::requireWrite();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		$checks = $request->arrayOfStrings( 'checks' );

		$result = $checks
			? MaintenanceService::harden( $siteId, $checks )
			: MaintenanceService::scanSecurity( $siteId );

		$message = $result['ok']
			? ( $checks ? 'Härtungsmaßnahmen angewendet.' : 'Sicherheitsprüfung aktualisiert.' )
			: $result['error'];

		$this->respond( $request, $result['ok'], $message, '/sites/' . $siteId );
	}

	public function extensions( Request $request ): void {
		Auth::requireWrite();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		$kind    = 'theme' === $request->string( 'kind' ) ? 'theme' : 'plugin';
		$action  = $request->string( 'extension_action' );
		$targets = $request->arrayOfStrings( 'targets' );

		if ( ! $targets && '' !== $request->string( 'target' ) ) {
			$targets = array( $request->string( 'target' ) );
		}

		$result = 'theme' === $kind
			? MaintenanceService::themeAction( $siteId, $action, $targets )
			: MaintenanceService::pluginAction( $siteId, $action, $targets );

		$message = $result['ok']
			? sprintf( '%d Aktion(en) ausgeführt.', count( $result['results'] ) )
			: $result['error'];

		$this->respond( $request, $result['ok'], $message, '/sites/' . $siteId );
	}

	/**
	 * Agentur-Branding auf der Kundenseite schalten.
	 */
	public function branding( Request $request ): void {
		Auth::requireWrite();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		$error = BrandingService::apply(
			$siteId,
			array(
				'bar'               => $request->bool( 'bar' ),
				'login_bar'         => $request->bool( 'login_bar' ),
				'login_logo'        => $request->string( 'login_logo' ),
				'login_logo_height' => $request->int( 'login_logo_height' ) ?: 72,
				'login_logo_link'   => $request->string( 'login_logo_link' ),
			)
		);

		$this->respond( $request, null === $error, $error ?? 'Branding übernommen.', '/sites/' . $siteId );
	}

	/**
	 * Child-Plugin auf der Kundenseite aktualisieren.
	 */
	public function childUpdate( Request $request ): void {
		Auth::requireWrite();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		$result = ChildPluginService::update( $siteId );

		$this->respond( $request, $result['ok'], $result['message'], '/sites/' . $siteId );
	}

	/**
	 * Wartungsmodus ein- oder ausschalten.
	 */
	public function maintenanceMode( Request $request ): void {
		Auth::requireWrite();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		if ( $request->bool( 'disable' ) ) {
			$error = MaintenanceModeService::disable( $siteId );
			$this->respond( $request, null === $error, $error ?? 'Wartungsmodus beendet.', '/sites/' . $siteId );
		}

		$error = MaintenanceModeService::enable(
			$siteId,
			$request->int( 'minutes' ),
			array(
				'headline' => $request->string( 'headline', 'Wartungsmodus' ),
				'message'  => $request->string( 'message' ),
			)
		);

		$this->respond( $request, null === $error, $error ?? 'Wartungsmodus eingeschaltet.', '/sites/' . $siteId );
	}

	public function rotateToken( Request $request ): void {
		Auth::requireWrite();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		SiteRepository::rotateMonitorToken( $siteId );

		$this->respond( $request, true, 'Monitoring-Token neu erzeugt. Bitte im Monitoring-Dienst aktualisieren.', '/sites/' . $siteId );
	}

	/**
	 * Sammelaktionen aus der Seitenliste.
	 */
	public function bulk( Request $request ): void {
		Auth::requireWrite();

		$action  = $request->string( 'bulk_action' );
		$siteIds = array_map( 'intval', $request->arrayOfStrings( 'site_ids' ) );

		if ( ! $siteIds ) {
			$this->respond( $request, false, 'Bitte mindestens eine Seite auswählen.', $this->back( $request, '/sites' ) );
		}

		$results = array();

		foreach ( $siteIds as $siteId ) {
			$site = SiteRepository::find( $siteId );
			if ( null === $site || ! Auth::canSeeSite( $siteId ) ) {
				continue;
			}

			switch ( $action ) {
				case 'sync':
					$outcome   = SyncService::site( $site, true );
					$results[] = array( 'site' => $site['name'], 'success' => $outcome['ok'], 'message' => $outcome['error'] );
					break;

				case 'update':
					$outcome   = UpdateService::apply( $site );
					$results[] = array(
						'site'    => $site['name'],
						'success' => $outcome['ok'] && 0 === $outcome['failed'],
						'message' => $outcome['ok'] ? sprintf( '%d Update(s)', $outcome['succeeded'] ) : $outcome['error'],
					);
					break;

				case 'pause':
				case 'resume':
					SiteRepository::update( $siteId, array( 'is_paused' => 'pause' === $action ? 1 : 0 ) );
					$results[] = array( 'site' => $site['name'], 'success' => true, 'message' => 'OK' );
					break;

				case 'maintenance':
					$outcome   = MaintenanceService::run( $siteId, $request->arrayOfStrings( 'tasks' ) );
					$results[] = array( 'site' => $site['name'], 'success' => $outcome['ok'], 'message' => $outcome['error'] );
					break;

				case 'branding-on':
				case 'branding-off':
					$on        = 'branding-on' === $action;
					$error     = BrandingService::toggle( $siteId, $on, $on );
					$results[] = array( 'site' => $site['name'], 'success' => null === $error, 'message' => $error ?? 'OK' );
					break;

				case 'child-update':
					$outcome   = ChildPluginService::update( $siteId );
					$results[] = array( 'site' => $site['name'], 'success' => $outcome['ok'], 'message' => $outcome['message'] );
					break;

				case 'mmode-on':
				case 'mmode-off':
					$error     = 'mmode-on' === $action
						? MaintenanceModeService::enable( $siteId, $request->int( 'minutes' ) )
						: MaintenanceModeService::disable( $siteId );
					$results[] = array( 'site' => $site['name'], 'success' => null === $error, 'message' => $error ?? 'OK' );
					break;

				case 'delete':
					SiteService::remove( $siteId );
					$results[] = array( 'site' => $site['name'], 'success' => true, 'message' => 'entfernt' );
					break;

				default:
					$this->respond( $request, false, 'Unbekannte Sammelaktion.', $this->back( $request, '/sites' ) );
			}
		}

		$this->respond( $request, true, $this->summarize( $results ), $this->back( $request, '/sites' ) );
	}
}
