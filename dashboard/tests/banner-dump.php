<?php
/**
 * Erzeugt eine Testseite mit dem Sicherungs-Banner — eingebettet in ein
 * bewusst feindseliges Theme. Genau so landet es auf Kundenseiten: zwischen
 * fremdem CSS, das nichts von uns weiss.
 */
declare( strict_types = 1 );

require __DIR__ . '/child-stubs.php';

require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-cache.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-maintenance-mode.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-backuplog.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-banner.php';

$mitLogo = isset( $argv[1] ) && 'logo' === $argv[1];

$banner = NLC_Banner::markup(
	array(
		'text'   => 'Es läuft gerade eine Sicherung dieser Website. Sie kann kurzzeitig etwas langsamer reagieren.',
		'accent' => '#ff3d8b',
		'logo'   => $mitLogo ? 'https://north-flow.de/api/assets/188bbf40' : '',
	),
	array( 'started' => 1700000000 )
);

echo '<!doctype html>
<html lang="de"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kundenseite</title>
<style>
	/* Ein Theme, das sich um nichts schert. */
	*, *::before, *::after { box-sizing: content-box; }
	body { margin: 0; font-family: Georgia, serif; background: #fffdf5; color: #222; }
	p { font-size: 34px; line-height: 3; color: #cc0000; margin: 40px; text-align: center; text-transform: uppercase; }
	img { width: 100%; height: auto; display: inline; border: 6px solid lime; }
	button { all: unset; font-size: 48px; background: yellow; }
	div { border: 2px dashed blue; }
	.inhalt { padding: 24px; }
	.hoch { position: fixed; right: 0; bottom: 0; width: 200px; height: 90px; background: #0b5; z-index: 500; }
</style>
</head><body>
<div class="inhalt"><p>Inhalt der Kundenseite</p><p>Noch mehr Inhalt</p></div>
<div class="hoch">Etwas anderes unten rechts</div>
' . $banner . '
</body></html>';
