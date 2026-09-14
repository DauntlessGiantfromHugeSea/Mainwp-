<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Config;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Core\Setting;
use NorthLab\Core\View;

/**
 * Alles, was das Panel zu einer installierbaren Anwendung macht.
 *
 * Manifest und Service Worker kommen aus PHP statt als feste Dateien: so tragen
 * sie den eingestellten Agenturnamen und die Akzentfarbe, und der Service Worker
 * bekommt bei jedem Update eine neue Kennung, ohne dass jemand daran denken muss.
 */
final class PwaController extends BaseController {

	private const OFFLINE_PATH = '/offline';

	/**
	 * Dateien, die auch ohne Netz vorliegen müssen.
	 *
	 * @return array<int,string>
	 */
	private function shell(): array {
		return array(
			nl_asset( '/assets/css/app.css' ),
			nl_asset( '/assets/js/app.js' ),
			nl_asset( '/assets/icons/icon-192.png' ),
			nl_asset( '/assets/icons/icon-512.png' ),
			url( self::OFFLINE_PATH ),
		);
	}

	public function manifest( Request $request ): void {
		$name  = Setting::get( 'agency_name', 'NorthLab' );
		$brand = Setting::get( 'agency_color', '#f9907a' );

		$manifest = array(
			'id'               => url( '/' ),
			'name'             => $name . ' Control Panel',
			// Was unter dem Symbol auf dem Startbildschirm steht — dort ist wenig Platz.
			'short_name'       => mb_substr( $name, 0, 12 ),
			'description'      => 'Zentrale Verwaltung aller betreuten Websites.',
			'start_url'        => url( '/' ),
			'scope'            => url( '/' ),
			'display'          => 'standalone',
			'orientation'      => 'any',
			'background_color' => '#08080a',
			'theme_color'      => '#08080a',
			'lang'             => 'de',
			'dir'              => 'ltr',
			'categories'       => array( 'productivity', 'utilities' ),
			'icons'            => array(
				array(
					'src'     => url( '/assets/icons/icon-192.png' ),
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => url( '/assets/icons/icon-512.png' ),
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				// Android schneidet Symbole auf eine Form zu; diese haben Luft am Rand.
				array(
					'src'     => url( '/assets/icons/icon-maskable-192.png' ),
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				),
				array(
					'src'     => url( '/assets/icons/icon-maskable-512.png' ),
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				),
			),
			'shortcuts'        => array(
				array(
					'name'  => 'Seiten',
					'url'   => url( '/sites' ),
					'icons' => array( array( 'src' => url( '/assets/icons/icon-192.png' ), 'sizes' => '192x192' ) ),
				),
				array(
					'name'  => 'Updates',
					'url'   => url( '/updates' ),
					'icons' => array( array( 'src' => url( '/assets/icons/icon-192.png' ), 'sizes' => '192x192' ) ),
				),
			),
		);

		// Die Akzentfarbe faerbt die Statusleiste nur, wenn sie brauchbar ist.
		if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $brand ) ) {
			$manifest['theme_color'] = strtolower( $brand );
		}

		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );

		echo (string) json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Der Service Worker muss an der Wurzel liegen, sonst gilt er nur für
	 * einen Unterpfad — deshalb kommt er über eine eigene Route statt aus
	 * /assets.
	 */
	public function serviceWorker( Request $request ): void {
		$source = NL_RESOURCES . '/pwa/sw.js';

		if ( ! is_file( $source ) ) {
			Response::notFound( 'Service Worker fehlt.' );
			return;
		}

		$shell = $this->shell();

		$script = strtr(
			(string) file_get_contents( $source ),
			array(
				'__VERSION__' => $this->version(),
				'__OFFLINE__' => (string) json_encode( url( self::OFFLINE_PATH ), JSON_UNESCAPED_SLASHES ),
				'__SHELL__'   => (string) json_encode( $shell, JSON_UNESCAPED_SLASHES ),
			)
		);

		header( 'Content-Type: text/javascript; charset=utf-8' );
		// Der Browser prueft den Worker ohnehin selbst; ein Cache verzoegert nur Updates.
		header( 'Cache-Control: no-cache' );
		header( 'Service-Worker-Allowed: /' );

		echo $script;
		exit;
	}

	public function offline( Request $request ): void {
		View::render( 'offline', array( 'agencyName' => Setting::get( 'agency_name', 'NorthLab' ) ), 'layout/bare' );
	}

	/**
	 * Kennung, die sich bei jedem Update aendert.
	 *
	 * Die Aenderungszeit der Hüllendateien reicht: aendert sich eine, bekommt
	 * der Worker einen neuen Cache und raeumt den alten ab.
	 */
	private function version(): string {
		$root  = NL_ROOT . '/public';
		$stamp = (string) Config::get( 'app.version', NL_VERSION );

		foreach ( array( '/assets/css/app.css', '/assets/js/app.js', '/../resources/pwa/sw.js' ) as $file ) {
			$path = $root . $file;
			if ( is_file( $path ) ) {
				$stamp .= '-' . (string) filemtime( $path );
			}
		}

		return substr( hash( 'sha256', $stamp ), 0, 16 );
	}
}
