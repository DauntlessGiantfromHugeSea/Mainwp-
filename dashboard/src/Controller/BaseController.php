<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Core\Session;
use NorthLab\Core\Setting;
use NorthLab\Core\View;
use NorthLab\Repository\SiteRepository;
use NorthLab\Service\Scheduler;

/**
 * Gemeinsame Basis aller Controller.
 */
abstract class BaseController {

	/**
	 * Rendert eine Seite inklusive Navigationsdaten.
	 *
	 * @param array<string,mixed> $data
	 */
	protected function view( string $template, array $data = array() ): void {
		$data += array(
			'nav'          => $template,
			'currentUser'  => Auth::user(),
			'agencyName'   => Setting::get( 'agency_name', 'NorthLab' ),
			'agencyColor'  => Setting::get( 'agency_color', '#2f6df6' ),
			'stats'        => SiteRepository::stats( Auth::visibleSiteIds() ),
			'cronHealthy'  => Scheduler::isCronHealthy(),
			'old'          => Session::oldInput(),
		);

		View::render( $template, $data );
	}

	/**
	 * Antwortet je nach Anfrageart mit JSON oder einer Weiterleitung.
	 *
	 * @param array<string,mixed> $payload
	 */
	protected function respond( Request $request, bool $ok, string $message, string $redirect = '/', array $payload = array() ): never {
		if ( $request->wantsJson() ) {
			Response::json( array( 'ok' => $ok, 'message' => $message ) + $payload, $ok ? 200 : 422 );
			exit;
		}

		Session::flash( $ok ? 'success' : 'error', $message );

		Response::redirect( $redirect );
	}

	/**
	 * Ziel für eine Weiterleitung nach dem Absenden eines Formulars.
	 */
	protected function back( Request $request, string $fallback = '/' ): string {
		$referer = $request->header( 'Referer' );

		if ( '' !== $referer ) {
			$path = (string) parse_url( $referer, PHP_URL_PATH );
			$host = strtolower( (string) parse_url( $referer, PHP_URL_HOST ) );

			// HTTP_HOST traegt den Port mit, parse_url gibt ihn getrennt zurueck.
			// Ohne das Abschneiden passt auf einem Panel, das nicht auf dem
			// Standardport laeuft, nie etwas zusammen — und "zurueck" landet
			// immer auf der Ausweichadresse.
			$eigener = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
			$eigener = (string) preg_replace( '/:\d+$/', '', $eigener );

			// Zurueckgegeben wird ohnehin nur der Pfad, nie eine ganze Adresse.
			// Der Vergleich ist die zweite Sicherung dagegen, dass eine fremde
			// Seite die Weiterleitung bestimmt.
			if ( '' !== $path && ( '' === $host || $host === $eigener ) ) {
				$query = (string) parse_url( $referer, PHP_URL_QUERY );
				return $path . ( '' !== $query ? '?' . $query : '' );
			}
		}

		return $fallback;
	}

	/**
	 * Ergebnisse einer Sammelaktion zu einer lesbaren Meldung verdichten.
	 *
	 * @param array<int,array{site:string,success:bool,message:string}> $results
	 */
	protected function summarize( array $results ): string {
		if ( ! $results ) {
			return 'Keine passenden Seiten gefunden.';
		}

		$ok     = 0;
		$errors = array();

		foreach ( $results as $result ) {
			if ( ! empty( $result['success'] ) ) {
				$ok++;
			} else {
				$errors[] = sprintf( '%s: %s', $result['site'], $result['message'] );
			}
		}

		if ( ! $errors ) {
			return sprintf( '%d Seite(n) erfolgreich verarbeitet.', $ok );
		}

		return sprintf(
			'%d von %d Seite(n) erfolgreich. Fehler: %s',
			$ok,
			count( $results ),
			implode( ' | ', array_slice( $errors, 0, 5 ) )
		);
	}
}
