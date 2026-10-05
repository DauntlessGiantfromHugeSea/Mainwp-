<?php
declare( strict_types = 1 );
require __DIR__ . '/child-stubs.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-options.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-cache.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-maintenance-mode.php';

echo NLC_Maintenance_Mode::page(
	array(
		'headline' => 'Wartungsmodus',
		'message'  => 'Wir sind gleich wieder da.',
		'color'    => $argv[1] ?? '#f9907a',
		'logo'     => '',
		'until'    => time() + 2700,
	)
);
