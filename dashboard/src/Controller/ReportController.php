<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Repository\ClientRepository;
use NorthLab\Repository\ReportRepository;
use NorthLab\Repository\SiteRepository;
use NorthLab\Service\PdfService;
use NorthLab\Service\ReportService;

final class ReportController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireLogin();

		$this->view(
			'reports/index',
			array(
				'reports'  => ReportRepository::recent( 60, $request->int( 'client_id' ) ),
				'clients'  => ClientRepository::all( Auth::visibleSiteIds() ),
				'sites'    => SiteRepository::all( array_filter( array( 'site_ids' => Auth::visibleSiteIds() ), static fn( $v ): bool => null !== $v ) ),
				'pdfReady' => PdfService::available(),
				'pdfHint'  => PdfService::hint(),
			)
		);
	}

	public function generate( Request $request ): void {
		Auth::requireWrite();

		$clientId = $request->int( 'client_id' );
		$siteId   = $request->int( 'site_id' );
		$days     = max( 1, min( 365, $request->int( 'days', 30 ) ) );
		$deliver  = $request->bool( 'deliver' );

		[ $id, $error ] = ReportService::generate(
			$clientId > 0 ? $clientId : null,
			gmdate( 'Y-m-d H:i:s', time() - $days * 86400 ),
			nl_utc(),
			$deliver,
			Auth::visibleSiteIds(),
			$siteId > 0 ? $siteId : null
		);

		if ( 0 === $id ) {
			$this->respond( $request, false, (string) $error, '/reports' );
		}

		$this->respond(
			$request,
			true,
			$deliver ? 'Bericht erstellt und versendet.' : 'Bericht erstellt.',
			'/reports/' . $id
		);
	}

	public function show( Request $request ): void {
		Auth::requireLogin();

		$report = ReportRepository::find( (int) $request->params['id'] );

		if ( null === $report ) {
			Response::notFound( 'Bericht nicht gefunden.' );
			return;
		}

		// Der Bericht bringt sein eigenes Layout mit und wird direkt
		// ausgeliefert. Die Leiste kommt erst hier dazu und nicht in das
		// gespeicherte HTML: dasselbe Dokument geht als E-Mail an den Kunden,
		// und ein Knopf, der dort nichts tut, gehoert da nicht hinein.
		Response::html( self::withToolbar( (string) $report['html'], (int) $report['id'] ) );
	}

	/**
	 * Eine kleine Leiste zum Drucken und Herunterladen oben in den Bericht.
	 *
	 * Steht kein PDF-Erzeuger bereit, bleibt der Druckknopf: damit macht
	 * jeder Browser ein PDF, ohne dass auf dem Server etwas fehlt.
	 */
	private static function withToolbar( string $html, int $reportId ): string {
		$pdf = PdfService::available()
			? '<a href="' . e( url( '/reports/' . $reportId . '/pdf' ) ) . '" '
				. 'style="background:#101828;color:#fff;border:0;border-radius:7px;padding:7px 13px;'
				. 'font:600 13px/1 -apple-system,BlinkMacSystemFont,sans-serif;cursor:pointer;text-decoration:none;'
				. 'display:inline-block">PDF herunterladen</a>'
			: '<span style="color:#8a94a6;font:12px/1.4 -apple-system,sans-serif;max-width:340px;display:inline-block">'
				. 'Kein PDF-Erzeuger auf dem Server — der Druckknopf links erzeugt eins im Browser.</span>';

		$leiste = '<div class="nl-nodruck" style="max-width:860px;margin:0 auto 14px;display:flex;gap:9px;'
			. 'align-items:center;flex-wrap:wrap">'
			. '<button type="button" onclick="window.print()" '
			. 'style="background:#fff;color:#101828;border:1px solid #d0d5dd;border-radius:7px;padding:7px 13px;'
			. 'font:600 13px/1 -apple-system,BlinkMacSystemFont,sans-serif;cursor:pointer">Drucken</button>'
			. $pdf
			. '<a href="' . e( url( '/reports' ) ) . '" style="margin-left:auto;color:#8a94a6;'
			. 'font:13px/1 -apple-system,sans-serif;text-decoration:none">Zurück zur Übersicht</a>'
			. '</div>';

		$pos = stripos( $html, '<body' );

		if ( false === $pos ) {
			return $leiste . $html;
		}

		$ende = strpos( $html, '>', $pos );

		if ( false === $ende ) {
			return $leiste . $html;
		}

		return substr( $html, 0, $ende + 1 ) . $leiste . substr( $html, $ende + 1 );
	}

	public function download( Request $request ): void {
		Auth::requireLogin();

		$report = ReportRepository::find( (int) $request->params['id'] );

		if ( null === $report ) {
			Response::notFound( 'Bericht nicht gefunden.' );
			return;
		}

		$slug = preg_replace( '/[^a-z0-9]+/i', '-', (string) $report['title'] ) ?? 'bericht';

		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . strtolower( trim( $slug, '-' ) ) . '.html"' );
		header( 'X-Content-Type-Options: nosniff' );

		echo (string) $report['html'];
		exit;
	}

	/**
	 * Den Bericht als PDF ausliefern.
	 */
	public function pdf( Request $request ): void {
		Auth::requireLogin();

		$report = ReportRepository::find( (int) $request->params['id'] );

		if ( null === $report ) {
			Response::notFound( 'Bericht nicht gefunden.' );
			return;
		}

		if ( ! PdfService::available() ) {
			$this->respond(
				$request,
				false,
				'PDF ist auf diesem Server nicht möglich. ' . PdfService::hint(),
				'/reports/' . (int) $report['id']
			);
		}

		$datei = PdfService::workDir() . '/ausgabe-' . bin2hex( random_bytes( 8 ) ) . '.pdf';
		$pdf   = PdfService::fromHtml( (string) $report['html'], $datei );

		if ( ! $pdf['ok'] ) {
			$this->respond( $request, false, $pdf['error'], '/reports/' . (int) $report['id'] );
		}

		$slug = strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) $report['title'] ), '-' ) );

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . ( '' !== $slug ? $slug : 'bericht' ) . '.pdf"' );
		header( 'Content-Length: ' . $pdf['bytes'] );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $datei );

		// Der Bericht enthaelt Kundendaten — die Zwischendatei bleibt nicht liegen.
		@unlink( $datei );
		exit;
	}

	public function destroy( Request $request ): void {
		Auth::requireWrite();

		ReportRepository::delete( (int) $request->params['id'] );

		$this->respond( $request, true, 'Bericht gelöscht.', '/reports' );
	}
}
