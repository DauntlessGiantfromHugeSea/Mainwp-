<?php
declare( strict_types = 1 );
require __DIR__ . '/child-stubs.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-options.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-maintenance-mode.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-branding.php';

function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }

$state = array(
	'logo'           => '',
	'site'           => 'https://north-lab.de',
	'email'          => 'hallo@north-lab.de',
	'phone'          => '+49 1590 5535295',
	'text'           => 'Kontakt bei Fragen oder Problemen:',
	'login_bar_text' => 'Betreut von',
	'accent'         => '#e8917a',
	'tint'           => '#fdf3f0',
);

// Der Rahmen ahmt das WordPress-Backend grob nach, damit die Leiste im
// richtigen Kontext steht.
echo '<!doctype html><html lang="de"><head><meta charset="utf-8">',
	'<meta name="viewport" content="width=device-width, initial-scale=1">',
	'<style>body{margin:0;background:#f0f0f1;font:13px -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#3c434a}',
	'#wpcontent{margin-left:0;padding-left:20px}#wpbody-content{padding-bottom:40px}',
	'.wrap h1{font-size:23px;font-weight:400;margin:20px 0}</style></head><body>',
	'<div id="wpcontent">';

echo 'bar' === ( $argv[1] ?? 'bar' )
	? NLC_Branding::bar_markup( $state )
	: NLC_Branding::login_bar_markup( $state );

echo '<div id="wpbody-content"><div class="wrap"><h1>Dashboard</h1>',
	'<p>Willkommen bei WordPress.</p></div></div></div></body></html>';
