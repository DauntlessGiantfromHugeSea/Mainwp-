<?php
/**
 * Layout für Anmeldung und Installer.
 *
 * @var string           $content
 * @var array<int,array> $flash
 */
?>
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="theme-color" content="#08080a">
	<link rel="manifest" href="<?= e( url( '/manifest.webmanifest' ) ) ?>">
	<?php $iconStamp = \NorthLab\Service\IconService::stamp(); ?>
	<link rel="icon" href="<?= e( url( '/branding/icon.svg' ) . '?v=' . $iconStamp ) ?>" type="image/svg+xml">
	<link rel="icon" href="<?= e( url( '/branding/icon/favicon-32.png' ) . '?v=' . $iconStamp ) ?>" sizes="32x32" type="image/png">
	<link rel="icon" href="<?= e( url( '/branding/icon/icon-192.png' ) . '?v=' . $iconStamp ) ?>" sizes="192x192" type="image/png">
	<link rel="apple-touch-icon" href="<?= e( url( '/branding/icon/apple-touch-icon.png' ) . '?v=' . $iconStamp ) ?>">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<meta name="apple-mobile-web-app-title" content="<?= e( $agencyName ?? 'NorthLab' ) ?>">
	<meta name="robots" content="noindex, nofollow">
	<title><?= e( $pageTitle ?? 'Anmelden' ) ?> · <?= e( $agencyName ?? 'NorthLab' ) ?></title>
	<link rel="stylesheet" href="<?= e( nl_asset( '/assets/css/app.css' ) ) ?>">
</head>
<body>
<div class="auth-shell">
	<div class="auth-card <?= e( $wide ?? false ? 'wide' : '' ) ?>">
		<?php foreach ( ( $flash ?? array() ) as $message ) : ?>
			<div class="flash <?= e( $message['type'] ) ?>"><?= e( $message['message'] ) ?></div>
		<?php endforeach; ?>

		<?= $content ?>
	</div>
</div>
<script src="<?= e( nl_asset( '/assets/js/app.js' ) ) ?>" defer></script>
</body>
</html>
