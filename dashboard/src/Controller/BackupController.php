<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
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

		$configured = Restic::configured();
		$available  = Restic::available();

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
				'configured'  => $configured,
				'available'   => $available,
				'resticInfo'  => $available ? Restic::version() : null,
				'repository'  => Setting::get( 'restic_repository', '' ),
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
	 * Einstellungen und Verbindungstest zum Sicherungsziel.
	 */
	public function save( Request $request ): void {
		Auth::requireAdmin();

		switch ( $request->string( 'section' ) ) {
			case 'target':
				Setting::setMany(
					array(
						'restic_repository' => $request->string( 'restic_repository' ),
						'restic_password'   => (string) $request->post( 'restic_password', '' ) !== ''
							? (string) $request->post( 'restic_password' )
							: Setting::get( 'restic_password', '' ),
						'restic_ssh_key'    => $request->string( 'restic_ssh_key' ),
						'restic_binary'     => $request->string( 'restic_binary' ),
						'restic_home'       => $request->string( 'restic_home', '/root' ),
					)
				);
				$this->respond( $request, true, 'Sicherungsziel gespeichert.', '/backups' );

			case 'schedule':
				Setting::setMany(
					array(
						'backup_enabled'      => $request->bool( 'backup_enabled' ) ? '1' : '0',
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

			case 'init':
				if ( ! Restic::available() ) {
					$this->respond( $request, false, 'restic ist auf diesem Server nicht installiert.', '/backups' );
				}

				$result = Restic::initRepository();

				$this->respond(
					$request,
					$result['ok'],
					$result['ok']
						? 'Sicherungsziel erreichbar: ' . trim( $result['output'] )
						: 'Sicherungsziel nicht erreichbar: ' . trim( $result['output'] ),
					'/backups'
				);
		}

		$this->respond( $request, false, 'Unbekannter Bereich.', '/backups' );
	}
}
