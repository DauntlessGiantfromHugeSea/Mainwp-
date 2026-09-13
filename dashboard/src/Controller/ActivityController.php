<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;
use NorthLab\Service\EventBus;

final class ActivityController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireLogin();

		$page    = max( 1, $request->int( 'page', 1 ) );
		$perPage = 100;

		$filters = array(
			'site_id' => $request->int( 'site_id' ),
			'level'   => $request->string( 'level' ),
			'action'  => $request->string( 'action' ),
			'search'  => $request->string( 'q' ),
			'limit'   => $perPage,
			'offset'  => ( $page - 1 ) * $perPage,
			'site_ids' => Auth::visibleSiteIds(),
		);

		$this->view(
			'activity/index',
			array(
				'entries' => ActivityRepository::query( $filters ),
				'sites'   => SiteRepository::all( array( 'site_ids' => Auth::visibleSiteIds() ) ),
				'catalog' => EventBus::catalog(),
				'filters' => $filters,
				'page'    => $page,
				'perPage' => $perPage,
			)
		);
	}
}
