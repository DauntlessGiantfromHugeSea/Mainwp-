<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Config;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Core\Setting;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Service\ChildPackager;
use Throwable;

/**
 * Bereitstellung des NorthLab-Child-Plugins.
 */
final class DownloadController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireLogin();

		$this->view(
			'download/index',
			array(
				'pluginVersion' => ChildPackager::version(),
				'pluginFile'    => ChildPackager::filename(),
				'panelUrl'      => rtrim( (string) Config::get( 'app.url', '' ), '/' ),
				'creator'       => Setting::get( 'agency_name', 'NorthLab' ),
			)
		);
	}

	public function childPlugin( Request $request ): void {
		Auth::requireLogin();

		try {
			$archive = ChildPackager::build( $request->bool( 'rebuild' ) );
		} catch ( Throwable $e ) {
			$this->respond( $request, false, 'Plugin-Paket konnte nicht erzeugt werden: ' . $e->getMessage(), '/download' );
		}

		ActivityRepository::log(
			'plugin.downloaded',
			sprintf( 'Child-Plugin %s heruntergeladen.', ChildPackager::version() )
		);

		Response::download( $archive, ChildPackager::filename(), 'application/zip' );
	}
}
