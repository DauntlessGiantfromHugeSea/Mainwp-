<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Service\IconService;

/**
 * Ausliefern und Entgegennehmen des Panel-Symbols.
 *
 * Die Symbole liegen in storage/, nicht in public/ — sie sollen über PHP
 * gehen, damit der Medientyp festgelegt ist und nichts hochgeladenes als
 * etwas anderes ausgeliefert werden kann.
 */
final class IconController extends BaseController {

	/**
	 * Zusammengesetztes Symbol als SVG.
	 *
	 * Ohne Anmeldung: es steht im Browser-Reiter, auch auf der Anmeldeseite.
	 */
	public function svg( Request $request ): void {
		$this->serveHeaders( 'image/svg+xml' );

		echo IconService::svg();
		exit;
	}

	/**
	 * Eine der festen Grössen als PNG.
	 */
	public function raster( Request $request ): void {
		$name = (string) ( $request->params['name'] ?? '' );
		$path = IconService::rasterPath( $name );

		if ( null === $path ) {
			Response::notFound( 'Symbol nicht gefunden.' );
			return;
		}

		$this->serveHeaders( 'image/png' );
		header( 'Content-Length: ' . (string) filesize( $path ) );

		readfile( $path );
		exit;
	}

	/**
	 * Das Ausgangslogo — der Browser braucht es gleicher Herkunft, sonst darf
	 * er die daraus gezeichnete Fläche nicht auslesen.
	 */
	public function source( Request $request ): void {
		Auth::requireLogin();

		$path = IconService::sourcePath();

		if ( null === $path ) {
			Response::notFound( 'Es ist kein Logo hinterlegt.' );
			return;
		}

		$this->serveHeaders( IconService::sourceMime() );

		readfile( $path );
		exit;
	}

	/**
	 * Die im Browser erzeugten Fassungen entgegennehmen.
	 */
	public function store( Request $request ): void {
		Auth::requireAdmin();

		$saved  = 0;
		$errors = array();

		foreach ( array_keys( IconService::SIZES ) as $name ) {
			$value = (string) $request->post( $name, '' );

			if ( '' === $value ) {
				continue;
			}

			$error = IconService::saveRaster( $name, $value );

			if ( null === $error ) {
				$saved++;
			} else {
				$errors[] = $name . ': ' . $error;
			}
		}

		if ( $saved > 0 ) {
			IconService::touch();
		}

		Response::json(
			array(
				'ok'     => $saved > 0 && ! $errors,
				'saved'  => $saved,
				'errors' => $errors,
			),
			$saved > 0 && ! $errors ? 200 : 422
		);
	}

	private function serveHeaders( string $type ): void {
		header( 'Content-Type: ' . $type );
		// Der Inhalt kommt aus einem Upload — der Browser soll nicht raten.
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=86400' );
	}
}
