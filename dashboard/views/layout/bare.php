<?php
/**
 * Schlankes Layout ohne Navigation — für Seiten, die auch ohne Anmeldung und
 * ohne Netz funktionieren müssen.
 *
 * @var string $content
 * @var string $agencyName
 */
?>
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<meta name="theme-color" content="#08080a">
	<title><?= e( $pageTitle ?? 'Offline' ) ?> · <?= e( $agencyName ?? 'NorthLab' ) ?></title>
	<link rel="icon" href="<?= e( url( '/branding/icon.svg' ) ) ?>" type="image/svg+xml">
	<link rel="stylesheet" href="<?= e( nl_asset( '/assets/css/app.css' ) ) ?>">
</head>
<body class="bare">
	<main class="bare-card">
		<?= $content ?>
	</main>
</body>
</html>
