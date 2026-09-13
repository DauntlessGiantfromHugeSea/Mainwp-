<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\ClientRepository;
use NorthLab\Repository\SiteRepository;
use NorthLab\Repository\UpdateRepository;
use NorthLab\Repository\UptimeRepository;
use NorthLab\Repository\WebhookRepository;
use NorthLab\Service\Scheduler;

final class DashboardController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireLogin();

		$visible = Auth::visibleSiteIds();
		$sites   = SiteRepository::all( array( 'orderby' => 'pending_updates', 'order' => 'DESC', 'site_ids' => $visible ) );

		$attention = array_values(
			array_filter(
				$sites,
				static fn( array $s ): bool =>
					'down' === $s['uptime_status']
					|| 'error' === $s['status']
					|| (int) $s['pending_updates'] > 0
					|| ( (int) $s['security_score'] > 0 && (int) $s['security_score'] < 70 )
			)
		);

		$this->view(
			'dashboard',
			array(
				'sites'         => $sites,
				'attention'     => array_slice( $attention, 0, 12 ),
				'updateCounts'  => UpdateRepository::countsByType( $visible ),
				'topUpdates'    => array_slice( UpdateRepository::grouped( array( 'site_ids' => $visible ) ), 0, 8, true ),
				'clients'       => ClientRepository::all(),
				'activity'      => ActivityRepository::query( array( 'limit' => 12, 'site_ids' => $visible ) ),
				'uptimeFeed'    => UptimeRepository::feed( 8 ),
				'queue'         => WebhookRepository::queueStats(),
				'jobs'          => Scheduler::overview(),
				'lastCron'      => Scheduler::lastHeartbeat(),
			)
		);
	}
}
