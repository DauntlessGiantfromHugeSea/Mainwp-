<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Config;
use NorthLab\Core\Request;
use NorthLab\Core\Setting;
use NorthLab\Repository\SiteRepository;
use NorthLab\Repository\UptimeRepository;
use NorthLab\Service\SiteService;

final class UptimeController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireLogin();

		$days = max( 1, min( 90, $request->int( 'days', 30 ) ) );
		$from = gmdate( 'Y-m-d H:i:s', time() - $days * 86400 );
		$to   = nl_utc();

		$rows = array();

		foreach ( SiteRepository::all( array( 'site_ids' => Auth::visibleSiteIds() ) ) as $site ) {
			$siteId = (int) $site['id'];

			$rows[] = array(
				'site'         => $site,
				'availability' => UptimeRepository::availability( $siteId, $from, $to ),
				'incidents'    => UptimeRepository::incidents( $siteId, $from, $to ),
				'series'       => UptimeRepository::dailySeries( $siteId, min( $days, 30 ) ),
				'monitor_url'  => SiteService::monitorUrl( $site ),
			);
		}

		usort(
			$rows,
			static fn( array $a, array $b ): int => $a['availability']['percent'] <=> $b['availability']['percent']
		);

		$globalToken = trim( Setting::get( 'uptime_global_token', '' ) );

		$this->view(
			'uptime/index',
			array(
				'rows'      => $rows,
				'feed'      => UptimeRepository::feed( 60, Auth::visibleSiteIds() ),
				'days'      => $days,
				'globalUrl' => '' !== $globalToken ? self::baseUrl() . '/api/uptime/' . $globalToken : '',
			)
		);
	}

	/**
	 * Liefert alle Monitoring-URLs als CSV — Vorlage für den Import ins Monitoring.
	 */
	public function export( Request $request ): void {
		Auth::requireLogin();

		$rows = array();

		foreach ( SiteRepository::all( array( 'site_ids' => Auth::visibleSiteIds() ) ) as $site ) {
			$rows[] = array(
				(string) $site['name'],
				(string) $site['url'],
				(string) ( $site['client_name'] ?? '' ),
				SiteService::monitorUrl( $site ),
				empty( $site['is_paused'] ) ? 'aktiv' : 'pausiert',
			);
		}

		$filename = 'northlab-monitoring-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'wb' );

		// BOM, damit Excel die Umlaute richtig liest.
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'Name', 'URL', 'Kunde', 'Monitoring-Webhook', 'Status' ), ';' );

		foreach ( $rows as $row ) {
			fputcsv( $out, $row, ';' );
		}

		fclose( $out );
		exit;
	}

	private static function baseUrl(): string {
		return rtrim( (string) Config::get( 'app.url', '' ), '/' );
	}
}
