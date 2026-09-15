<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Crypto;
use NorthLab\Core\Database;
use NorthLab\Core\Request;
use NorthLab\Core\Setting;
use NorthLab\Repository\SiteRepository;
use NorthLab\Service\BackupService;
use NorthLab\Service\Restic;

final class BackupController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireLogin();

		$visible = Auth::visibleSiteIds();
		$sites   = SiteRepository::all( array( 'site_ids' => $visible ) );

		$rows = array();

		foreach ( $sites as $site ) {
			$siteId = (int) $site['id'];

			$rows[] = array(
				'site'   => $site,
				'last'   => Database::selectOne(
					'SELECT * FROM `' . Database::table( 'backups' ) . '` WHERE `site_id` = :id ORDER BY `id` DESC LIMIT 1',
					array( 'id' => $siteId )
				),
				'mirror' => BackupService::mirrorSize( $siteId ),
			);
		}

		$available = Restic::available();

		$this->view(
			'backups/index',
			array(
				'rows'        => $rows,
				'history'     => Database::select(
					'SELECT b.*, s.name AS site_name FROM `' . Database::table( 'backups' ) . '` b
					 INNER JOIN `' . Database::table( 'sites' ) . '` s ON s.id = b.site_id
					 ORDER BY b.id DESC LIMIT 40'
				),
				'enabled'     => Setting::getBool( 'backup_enabled', false ),
				'configured'  => Restic::configured(),
				'available'   => $available,
				'resticInfo'  => $available ? Restic::version() : null,
				'repository'  => Restic::repository(),
				'targetType'  => Restic::type(),
				'checks'      => Restic::diagnose(),
				'publicKey'   => Restic::publicKey(),
				'sftp'        => Restic::parseSftp( Restic::repository() ),
				'pendingKeys' => Setting::getArray( 'restic_hostkey_pending', array() ),
				'setupSteps'  => Setting::getArray( 'restic_setup_steps', array() ),
				'setupAt'     => Setting::get( 'restic_setup_at', '' ),
				'hostKnown'   => Restic::hostKnown(),
				'panelOn'     => Setting::getBool( 'backup_panel', true ),
				'panelAt'     => Setting::get( 'panel_backup_at', '' ),
				'panelState'  => Setting::get( 'panel_backup_status', '' ),
				'panelNote'   => Setting::get( 'panel_backup_message', '' ),
				'hour'        => Setting::getInt( 'backup_hour', 3 ),
				'diskFree'    => (int) ( @disk_free_space( NL_STORAGE ) ?: 0 ),
				'settings'    => Setting::all(),
			)
		);
	}

	/**
	 * Sicherung einer Seite von Hand anstossen.
	 */
	public function run( Request $request ): void {
		Auth::requireWrite();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		$result = BackupService::run( $siteId );

		$this->respond(
			$request,
			$result['ok'],
			$result['ok']
				? sprintf(
					'Sicherung abgeschlossen: %d Datei(en) übertragen.',
					(int) ( $result['stats']['files_changed'] ?? 0 )
				)
				: 'Sicherung fehlgeschlagen: ' . $result['error'],
			'/backups'
		);
	}

	/**
	 * Das Panel selbst von Hand sichern.
	 */
	public function runPanel( Request $request ): void {
		Auth::requireAdmin();

		$result = BackupService::runPanel();

		$this->respond(
			$request,
			$result['ok'],
			$result['ok']
				? sprintf( 'Panel gesichert: Datenbank %s.', size_format_de( $result['bytes'] ) )
				: 'Sicherung des Panels fehlgeschlagen: ' . $result['error'],
			'/backups'
		);
	}

	/**
	 * Einstellungen, Schlüsselverwaltung und Verbindungstest.
	 */
	public function save( Request $request ): void {
		Auth::requireAdmin();

		switch ( $request->string( 'section' ) ) {
			case 'target':
				$this->saveTarget( $request );
				break;

			case 'schedule':
				Setting::setMany(
					array(
						'backup_enabled'      => $request->bool( 'backup_enabled' ) ? '1' : '0',
						'backup_panel'        => $request->bool( 'backup_panel' ) ? '1' : '0',
						'backup_hour'         => (string) max( 0, min( 23, $request->int( 'backup_hour', 3 ) ) ),
						'backup_root'         => 'root' === $request->string( 'backup_root' ) ? 'root' : 'content',
						'backup_excludes'     => $request->string( 'backup_excludes' ),
						'backup_mirror_dir'   => $request->string( 'backup_mirror_dir' ),
						'backup_keep_daily'   => (string) max( 1, $request->int( 'backup_keep_daily', 7 ) ),
						'backup_keep_weekly'  => (string) max( 0, $request->int( 'backup_keep_weekly', 4 ) ),
						'backup_keep_monthly' => (string) max( 0, $request->int( 'backup_keep_monthly', 6 ) ),
					)
				);
				$this->respond( $request, true, 'Zeitplan gespeichert.', '/backups' );

			case 'autosetup':
				$steps = Restic::autoSetup( (string) $request->post( 'box_password', '' ) );
				$last  = end( $steps );

				Setting::setMany(
					array(
						'restic_setup_steps' => $steps,
						'restic_setup_at'    => nl_utc(),
					)
				);

				$this->respond(
					$request,
					is_array( $last ) && ! empty( $last['ok'] ),
					is_array( $last ) && ! empty( $last['ok'] )
						? 'Eingerichtet. Ab jetzt läuft die Sicherung ohne weiteres Zutun.'
						: 'Stehengeblieben bei: ' . ( is_array( $last ) ? $last['label'] . ' — ' . $last['detail'] : 'unbekannt' ),
					'/backups'
				);

			case 'sshkey':
				$result = Restic::generateKey();

				$this->respond(
					$request,
					$result['ok'],
					$result['ok']
						? 'Schlüssel erzeugt. Der öffentliche Teil steht unten — er muss auf den Speicher.'
						: 'Schlüssel nicht erzeugt: ' . $result['error'],
					'/backups'
				);

			case 'hostkey':
				$scan = Restic::scanHostKey();

				if ( ! $scan['ok'] ) {
					$this->respond( $request, false, 'Wirtsschlüssel nicht abrufbar: ' . $scan['error'], '/backups' );
				}

				Setting::set( 'restic_hostkey_pending', $scan['keys'] );

				$this->respond(
					$request,
					true,
					'Wirtsschlüssel abgerufen. Bitte den Fingerabdruck vergleichen, bevor du ihn übernimmst.',
					'/backups'
				);

			case 'hostkey_trust':
				$this->trustHostKey( $request );
				break;

			case 'init':
				$this->initRepository( $request );
				break;

			default:
				$this->respond( $request, false, 'Unbekannter Bereich.', '/backups' );
		}

		$this->respond( $request, false, 'Unbekannter Bereich.', '/backups' );
	}

	/**
	 * Speicherart und Zugangsdaten übernehmen.
	 */
	private function saveTarget( Request $request ): void {
		$type = $request->string( 'backup_target_type' );

		if ( ! in_array( $type, Restic::TYPES, true ) ) {
			$this->respond( $request, false, 'Unbekannte Speicherart.', '/backups' );
		}

		$repository = Restic::buildRepository(
			$type,
			array(
				'user'       => $request->string( 'sb_user' ),
				'path'       => 's3' === $type ? $request->string( 's3_prefix' ) : $request->string( 'sb_path' ),
				'endpoint'   => $request->string( 's3_endpoint' ),
				'bucket'     => $request->string( 's3_bucket' ),
				'repository' => 'local' === $type
					? $request->string( 'local_path' )
					: $request->string( 'sftp_repository' ),
			)
		);

		if ( '' === $repository ) {
			$this->respond( $request, false, 'Die Angaben reichen nicht für eine Zieladresse.', '/backups' );
		}

		$values = array(
			'backup_target_type' => $type,
			'restic_repository'  => $repository,
			'restic_binary'      => $request->string( 'restic_binary' ),
			'sb_user'            => $request->string( 'sb_user' ),
			'sb_path'            => $request->string( 'sb_path' ),
			's3_endpoint'        => $request->string( 's3_endpoint' ),
			's3_bucket'          => $request->string( 's3_bucket' ),
			's3_prefix'          => $request->string( 's3_prefix' ),
			's3_access_key'      => $request->string( 's3_access_key' ),
			'sftp_repository'    => $request->string( 'sftp_repository' ),
			'local_path'         => $request->string( 'local_path' ),
		);

		// Passwortfelder bleiben leer, wenn sich nichts ändern soll — sonst
		// würde ein Speichern der Zeitform das hinterlegte Geheimnis löschen.
		$password = (string) $request->post( 'restic_password', '' );

		if ( '' !== $password ) {
			$values['restic_password'] = Crypto::encrypt( $password );
		}

		$secret = (string) $request->post( 's3_secret_key', '' );

		if ( '' !== $secret ) {
			$values['s3_secret_key'] = Crypto::encrypt( $secret );
		}

		// Wechselt das Ziel, passt der gemerkte Wirtsschluessel nicht mehr.
		if ( $repository !== Restic::repository() ) {
			$values['restic_hostkey_pending'] = '';
		}

		Setting::setMany( $values );

		$this->respond( $request, true, 'Sicherungsziel gespeichert.', '/backups' );
	}

	/**
	 * Abgerufene Wirtsschlüssel übernehmen.
	 */
	private function trustHostKey( Request $request ): void {
		$pending = Setting::getArray( 'restic_hostkey_pending', array() );

		if ( ! $pending ) {
			$this->respond( $request, false, 'Es liegt kein abgerufener Wirtsschlüssel vor.', '/backups' );
		}

		$lines = array();

		foreach ( $pending as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['line'] ) ) {
				$lines[] = (string) $entry['line'];
			}
		}

		$error = Restic::trustHostKeys( $lines );

		if ( null !== $error ) {
			$this->respond( $request, false, 'Übernahme fehlgeschlagen: ' . $error, '/backups' );
		}

		Setting::set( 'restic_hostkey_pending', '' );

		$this->respond( $request, true, 'Wirtsschlüssel übernommen.', '/backups' );
	}

	/**
	 * Vollständige Prüfung samt Netz, danach Repository anlegen.
	 */
	private function initRepository( Request $request ): void {
		foreach ( Restic::diagnose( true ) as $check ) {
			if ( 'bad' === $check['state'] ) {
				$this->respond(
					$request,
					false,
					sprintf( '%s: %s', $check['label'], $check['detail'] ),
					'/backups'
				);
			}
		}

		$result = Restic::initRepository();

		$this->respond(
			$request,
			$result['ok'],
			$result['ok']
				? 'Sicherungsziel erreichbar: ' . trim( $result['output'] )
				: 'Sicherungsziel nicht erreichbar: ' . self::lastLines( $result['output'] ),
			'/backups'
		);
	}

	private static function lastLines( string $text, int $lines = 3 ): string {
		$parts = array_filter( array_map( 'trim', explode( "\n", $text ) ) );

		return implode( ' | ', array_slice( $parts, -$lines ) );
	}
}
