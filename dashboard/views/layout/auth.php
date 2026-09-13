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
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?= e( $pageTitle ?? 'Anmelden' ) ?> · <?= e( $agencyName ?? 'NorthLab' ) ?></title>
	<link rel="stylesheet" href="<?= e( url( '/assets/css/app.css' ) ) ?>">
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
<script src="<?= e( url( '/assets/js/app.js' ) ) ?>" defer></script>
</body>
</html>
