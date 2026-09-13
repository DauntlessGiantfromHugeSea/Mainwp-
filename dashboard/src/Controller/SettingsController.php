<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Config;
use NorthLab\Core\Crypto;
use NorthLab\Core\Request;
use NorthLab\Core\Setting;
use NorthLab\Service\EventBus;
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

	private function color( string $value ): string {
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? $value : '#2f6df6';
	}
}
