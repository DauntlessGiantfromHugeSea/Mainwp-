<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
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

		foreach ( SiteRepository::all() as $site ) {
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

		$this->view(
			'uptime/index',
			array(
				'rows' => $rows,
				'feed' => UptimeRepository::feed( 60 ),
				'days' => $days,
			)
		);
	}
}
