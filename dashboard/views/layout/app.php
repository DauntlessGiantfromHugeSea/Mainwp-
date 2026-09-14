<?php
/**
 * Hauptlayout mit Seitennavigation.
 *
 * @var string              $content
 * @var string              $nav
 * @var array<string,mixed> $currentUser
 * @var array<string,int>   $stats
 * @var array<int,array>    $flash
 * @var bool                $cronHealthy
 */

use NorthLab\Core\Auth;
use NorthLab\Core\Setting;

$logo   = Setting::get( 'agency_logo_url', '' );
$brand  = $agencyColor ?? '#2f6df6';
$navKey = static fn( string $prefix ): string => str_starts_with( $nav, $prefix ) ? 'active' : '';
?>
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?= e( $pageTitle ?? 'Übersicht' ) ?> · <?= e( $agencyName ?? 'NorthLab' ) ?></title>
	<link rel="stylesheet" href="<?= e( nl_asset( '/assets/css/app.css' ) ) ?>">
	<style>
		:root {
			--brand: <?= e( $brand ) ?>;
			--brand-ink: <?= e( nl_brand_ink( $brand ) ) ?>;
			--brand-soft: color-mix(in srgb, <?= e( $brand ) ?> 14%, transparent);
			--brand-line: color-mix(in srgb, <?= e( $brand ) ?> 32%, transparent);
		}
	</style>
</head>
<body>
<div class="shell">

	<aside class="sidebar" id="sidebar">
		<div class="sidebar-head">
			<a class="brand" href="<?= e( url( '/' ) ) ?>">
				<?php if ( '' !== $logo ) : ?>
					<img class="brand-logo" src="<?= e( $logo ) ?>" alt="<?= e( $agencyName ) ?>">
				<?php else : ?>
					<span class="brand-mark">N</span>
					<span><?= e( $agencyName ) ?></span>
				<?php endif; ?>
			</a>

			<button type="button" class="nav-toggle" data-nav-toggle
				aria-controls="sidebar-nav" aria-expanded="false" aria-label="Navigation ein- und ausblenden">
				<span></span><span></span><span></span>
			</button>
		</div>

		<nav class="nav" id="sidebar-nav">
			<div class="nav-label">Überblick</div>
			<a href="<?= e( url( '/' ) ) ?>" class="<?= e( 'dashboard' === $nav ? 'active' : '' ) ?>">Dashboard</a>
			<a href="<?= e( url( '/sites' ) ) ?>" class="<?= e( $navKey( 'sites' ) ) ?>">
				Seiten <span class="count"><?= e( (string) $stats['total'] ) ?></span>
			</a>
			<a href="<?= e( url( '/updates' ) ) ?>" class="<?= e( $navKey( 'updates' ) ) ?>">
				Updates
				<?php if ( $stats['updates'] > 0 ) : ?>
					<span class="count alert"><?= e( (string) $stats['updates'] ) ?></span>
				<?php endif; ?>
			</a>
			<a href="<?= e( url( '/uptime' ) ) ?>" class="<?= e( $navKey( 'uptime' ) ) ?>">
				Uptime
				<?php if ( $stats['offline'] > 0 ) : ?>
					<span class="count alert"><?= e( (string) $stats['offline'] ) ?></span>
				<?php endif; ?>
			</a>

			<a href="<?= e( url( '/backups' ) ) ?>" class="<?= e( $navKey( 'backups' ) ) ?>">Sicherungen</a>

			<div class="nav-label">Agentur</div>
			<a href="<?= e( url( '/clients' ) ) ?>" class="<?= e( $navKey( 'clients' ) ) ?>">Kunden</a>
			<a href="<?= e( url( '/reports' ) ) ?>" class="<?= e( $navKey( 'reports' ) ) ?>">Berichte</a>
			<a href="<?= e( url( '/webhooks' ) ) ?>" class="<?= e( $navKey( 'webhooks' ) ) ?>">Webhooks</a>
			<a href="<?= e( url( '/activity' ) ) ?>" class="<?= e( $navKey( 'activity' ) ) ?>">Protokoll</a>

			<div class="nav-label">System</div>
			<a href="<?= e( url( '/download' ) ) ?>" class="<?= e( $navKey( 'download' ) ) ?>">Plugin-Download</a>
			<a href="<?= e( url( '/profile/2fa' ) ) ?>" class="<?= e( $navKey( 'users/two-factor' ) ) ?>">
				Mein Zugang
				<?php if ( empty( $currentUser['totp_enabled'] ) ) : ?>
					<span class="count alert">2FA</span>
				<?php endif; ?>
			</a>
			<?php if ( Auth::isAdmin() ) : ?>
				<a href="<?= e( url( '/settings' ) ) ?>" class="<?= e( $navKey( 'settings' ) ) ?>">Einstellungen</a>
				<a href="<?= e( url( '/users' ) ) ?>" class="<?= e( $navKey( 'users' ) ) ?>">Benutzer</a>
			<?php endif; ?>
		</nav>

		<div class="sidebar-foot">
			<strong><?= e( $currentUser['name'] ?: $currentUser['email'] ) ?></strong>
			<?= e( \NorthLab\Repository\UserRepository::roleLabel( (string) $currentUser['role'] ) ) ?>
			<form method="post" action="<?= e( url( '/logout' ) ) ?>" style="margin-top:8px" data-no-lock>
				<?= csrf_field() ?>
				<button class="btn sm" type="submit">Abmelden</button>
			</form>
		</div>
	</aside>

	<div class="main">
		<header class="topbar">
			<h1><?= e( $pageTitle ?? 'Übersicht' ) ?></h1>
			<div class="spacer"></div>
			<?php if ( ! empty( $headerActions ) ) : ?>
				<div class="btn-row"><?= $headerActions ?></div>
			<?php endif; ?>
		</header>

		<main class="content">
			<?php foreach ( $flash as $message ) : ?>
				<div class="flash <?= e( $message['type'] ) ?>"><?= e( $message['message'] ) ?></div>
			<?php endforeach; ?>

			<?php if ( ! $cronHealthy ) : ?>
				<div class="notice warn">
					<strong>Der Zeitplaner läuft nicht.</strong>
					Ohne Cron finden keine automatischen Syncs, Uptime-Prüfungen, Webhook-Zustellungen oder Berichte statt.
					<?php if ( \NorthLab\Core\Auth::isAdmin() ) : ?>
						Einrichtung unter <a href="<?= e( url( '/settings#automation' ) ) ?>">Einstellungen → Automatisierung</a>.
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?= $content ?>
		</main>
	</div>
</div>

<script src="<?= e( nl_asset( '/assets/js/app.js' ) ) ?>" defer></script>
</body>
</html>
