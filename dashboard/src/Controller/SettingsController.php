<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Config;
use NorthLab\Core\Crypto;
use NorthLab\Core\Request;
use NorthLab\Core\Session;
use NorthLab\Core\Setting;
use NorthLab\Service\BrandingService;
use NorthLab\Service\EventBus;
use NorthLab\Service\IconService;
use NorthLab\Service\PushService;
use NorthLab\Service\Scheduler;

final class SettingsController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireAdmin();

		$this->view(
			'settings/index',
			array(
				'settings'  => Setting::all(),
				'catalog'   => EventBus::catalog(),
				'notifyOn'  => Setting::getArray( 'notify_on', array() ),
				'pushOn'    => Setting::getArray( 'push_on', array() ),
				'pushReady' => PushService::ready(),
				'pushCount' => \NorthLab\Repository\PushRepository::countForUser( Auth::id() ),
				'iconSource' => IconService::hasSource(),
				'iconOwn'    => IconService::hasOwnRaster(),
				'iconStamp'  => IconService::stamp(),
				'jobs'      => Scheduler::overview(),
				'cronToken' => Setting::get( 'cron_token', '' ),
				'cronUrl'   => rtrim( (string) Config::get( 'app.url', '' ), '/' ) . '/api/cron/' . Setting::get( 'cron_token', '' ),
				'config'    => array(
					'app_url'    => (string) Config::get( 'app.url', '' ),
					'timezone'   => (string) Config::get( 'app.timezone', '' ),
					'db_name'    => (string) Config::get( 'db.name', '' ),
					'db_prefix'  => (string) Config::get( 'db.prefix', '' ),
					'mail'       => (string) Config::get( 'mail.transport', 'mail' ),
					'verify_ssl' => (bool) Config::get( 'http.verify_ssl', true ),
				),
			)
		);
	}

	public function save( Request $request ): void {
		Auth::requireAdmin();

		$section = $request->string( 'section', 'general' );

		switch ( $section ) {
			case 'general':
				Setting::setMany(
					array(
						'agency_name'     => $request->string( 'agency_name', 'NorthLab' ),
						'agency_email'    => $request->string( 'agency_email' ),
						'agency_logo_url' => $request->string( 'agency_logo_url' ),
						'agency_color'    => $this->color( $request->string( 'agency_color', '#2f6df6' ) ),
					)
				);
				break;

			case 'automation':
				Setting::setMany(
					array(
						'sync_interval'        => (string) max( 300, $request->int( 'sync_interval', 900 ) ),
						'heartbeat_enabled'    => $request->bool( 'heartbeat_enabled' ) ? '1' : '0',
						'heartbeat_interval'   => (string) max( 60, $request->int( 'heartbeat_interval', 300 ) ),
						'auto_update_policy'   => $this->policy( $request->string( 'auto_update_policy', 'off' ) ),
						'auto_update_excludes' => $request->string( 'auto_update_excludes' ),
						'auto_update_window'   => $request->string( 'auto_update_window' ),
					)
				);
				break;

			case 'mmode':
				Setting::setMany(
					array(
						'mmode_headline'    => $request->string( 'mmode_headline', 'Wartungsmodus' ) ?: 'Wartungsmodus',
						'mmode_message'     => $request->string( 'mmode_message' ),
						'mmode_color'       => $this->color(
							$request->string( 'mmode_color' ),
							(string) Setting::get( 'agency_color', '#f9907a' )
						),
						'mmode_logo'        => $request->string( 'mmode_logo' ),
						'mmode_retry_after' => (string) max( 60, min( 86400, $request->int( 'mmode_retry_after', 3600 ) ) ),
					)
				);
				break;

			case 'branding':
				$audiences = array_keys( BrandingService::AUDIENCES );

				Setting::setMany(
					array(
						'branding_logo'       => $request->string( 'branding_logo' ),
						'branding_site'       => $request->string( 'branding_site' ),
						'branding_email'      => $request->string( 'branding_email' ),
						'branding_phone'      => $request->string( 'branding_phone' ),
						'branding_text'       => $request->string( 'branding_text', 'Kontakt bei Fragen oder Problemen:' ),
						'branding_login_text' => $request->string( 'branding_login_text', 'Betreut von' ),
						'branding_capability' => in_array( $request->string( 'branding_capability' ), $audiences, true )
							? $request->string( 'branding_capability' )
							: 'read',
						'branding_accent'     => $this->color( $request->string( 'branding_accent' ), '#e8917a' ),
						'branding_tint'       => $this->color( $request->string( 'branding_tint' ), '#fdf3f0' ),
					)
				);
				break;

			case 'icon':
				Setting::setMany(
					array(
						'icon_bg'      => $this->color( $request->string( 'icon_bg' ), '#000000' ),
						'icon_padding' => (string) max( 0, min( 40, $request->int( 'icon_padding', 18 ) ) ),
					)
				);

				$error = null;

				if ( ! empty( $_FILES['icon_file']['name'] ) ) {
					$error = IconService::uploadSource( (array) $_FILES['icon_file'] );
				} elseif ( '' !== $request->string( 'icon_url' ) ) {
					$error = IconService::fetchSource( $request->string( 'icon_url' ) );
				} else {
					// Nur Farbe oder Rand geaendert: die Fassungen passen nicht mehr.
					IconService::touch();
				}

				if ( null !== $error ) {
					Session::flash( 'error', $error );
				} elseif ( IconService::hasSource() ) {
					Session::flash( 'info', 'Logo übernommen. Bitte unten die Vorschau prüfen und die Symbole erzeugen.' );
				}
				break;

			case 'icon_reset':
				IconService::reset();
				Session::flash( 'success', 'Zurück auf das mitgelieferte Symbol.' );
				break;

			case 'push':
				Setting::setMany(
					array(
						'push_on' => array_values(
							array_intersect( $request->arrayOfStrings( 'push_on' ), array_keys( EventBus::catalog() ) )
						),
					)
				);
				break;

			case 'push_keys':
				// Ein neues Paar macht alle bestehenden Geraete wertlos, deshalb
				// nur erzeugen, wenn noch keins da ist — oder ausdruecklich erneuern.
				if ( $request->bool( 'rotate' ) ) {
					PushService::rotateKeys();
					Session::flash( 'warning', 'Neues Push-Schlüsselpaar erzeugt. Alle Geräte müssen sich neu anmelden.' );
				} else {
					PushService::ensureKeys();
				}
				break;

			case 'notifications':
				Setting::setMany(
					array(
						'notify_email' => $request->string( 'notify_email' ),
						'notify_on'    => array_values(
							array_intersect( $request->arrayOfStrings( 'notify_on' ), array_keys( EventBus::catalog() ) )
						),
					)
				);
				break;

			case 'monitoring':
				Setting::set( 'uptime_secret', $request->string( 'uptime_secret' ) );
				break;

			case 'reports':
				Setting::setMany(
					array(
						'report_default_freq' => $request->string( 'report_default_freq', 'monthly' ),
						'report_send_hour'    => (string) max( 0, min( 23, $request->int( 'report_send_hour', 8 ) ) ),
					)
				);
				break;

			case 'retention':
				Setting::setMany(
					array(
						'activity_retention' => (string) max( 7, $request->int( 'activity_retention', 180 ) ),
						'uptime_retention'   => (string) max( 7, $request->int( 'uptime_retention', 365 ) ),
					)
				);
				break;

			case 'api_token':
				Setting::set( 'api_token', $request->bool( 'disable' ) ? '' : Crypto::secret( 24 ) );
				$this->respond(
					$request,
					true,
					$request->bool( 'disable' ) ? 'API-Token entfernt.' : 'Neues API-Token erzeugt.',
					'/settings#monitoring'
				);

			case 'uptime_token':
				Setting::set(
					'uptime_global_token',
					$request->bool( 'disable' ) ? '' : Crypto::monitorToken()
				);
				$this->respond(
					$request,
					true,
					$request->bool( 'disable' )
						? 'Sammel-Token deaktiviert. Es gelten nur noch die seitenspezifischen URLs.'
						: 'Neues Sammel-Token erzeugt. Bitte die Benachrichtigung im Monitoring aktualisieren.',
					'/settings#monitoring'
				);

			case 'cron_token':
				Setting::set( 'cron_token', Crypto::secret( 16 ) );
				$this->respond( $request, true, 'Neues Cron-Token erzeugt. Bitte den Aufruf im Monitoring/Cron anpassen.', '/settings' );

			case 'run_job':
				$job = $request->string( 'job' );

				if ( ! in_array( $job, Scheduler::jobNames(), true ) ) {
					$this->respond( $request, false, 'Unbekannte Aufgabe.', '/settings' );
				}

				$result = Scheduler::run( $job );
				$this->respond( $request, true, sprintf( 'Aufgabe "%s" ausgeführt: %s', $job, $result ), '/settings' );

			default:
				$this->respond( $request, false, 'Unbekannter Einstellungsbereich.', '/settings' );
		}

		$this->respond( $request, true, 'Einstellungen gespeichert.', '/settings' );
	}

	private function policy( string $value ): string {
		return in_array( $value, array( 'off', 'security', 'minor', 'all' ), true ) ? $value : 'off';
	}

	private function color( string $value, string $fallback = '#2f6df6' ): string {
		$value = strtolower( trim( $value ) );

		// Kurzform mitnehmen, sonst landet ein getipptes #f9a still im Fallback.
		if ( preg_match( '/^#[0-9a-f]{3}$/', $value ) ) {
			$value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
		}

		return preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : $fallback;
	}
}
