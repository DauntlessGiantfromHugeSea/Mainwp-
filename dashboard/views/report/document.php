<?php
/**
 * Kundenbericht — eigenständiges HTML-Dokument.
 *
 * Wird sowohl im Browser angezeigt als auch als E-Mail-Text verschickt,
 * deshalb ausschließlich Inline-Styles und keine externen Ressourcen.
 *
 * @var array<string,mixed> $report
 */

$agency  = (array) $report['agency'];
$client  = $report['client'];
$period  = (array) $report['period'];
$summary = (array) $report['summary'];
$sites   = (array) $report['sites'];

$color = (string) ( $agency['color'] ?? '#2f6df6' );

$box    = 'background:#fff;border:1px solid #e4e7ec;border-radius:10px;padding:18px;margin-bottom:16px';
$th     = 'text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#8a94a6;padding:8px 10px;border-bottom:1px solid #e4e7ec';
$td     = 'padding:9px 10px;border-bottom:1px solid #f0f2f5;font-size:13px;vertical-align:top';
$muted  = 'color:#8a94a6';
$badge  = 'display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600';

$uptimeColor = static function ( float $percent ): string {
	if ( $percent >= 99.5 ) {
		return '#12805c';
	}
	return $percent >= 98.0 ? '#b25e09' : '#c92a2a';
};
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Wartungsbericht — <?= e( $client['name'] ?? 'Alle Seiten' ) ?></title>
</head>
<body style="margin:0;padding:24px;background:#f5f6f8;font:14px/1.55 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#101828">
<div style="max-width:860px;margin:0 auto">

	<div style="<?= e( $box ) ?>;border-top:4px solid <?= e( $color ) ?>">
		<table width="100%" cellpadding="0" cellspacing="0"><tr>
			<td>
				<?php if ( ! empty( $agency['logo'] ) ) : ?>
					<img src="<?= e( (string) $agency['logo'] ) ?>" alt="<?= e( (string) $agency['name'] ) ?>" style="max-height:36px;margin-bottom:10px">
				<?php else : ?>
					<div style="font-size:18px;font-weight:700;letter-spacing:-.02em"><?= e( (string) $agency['name'] ) ?></div>
				<?php endif; ?>
				<h1 style="margin:8px 0 4px;font-size:24px;letter-spacing:-.02em">Wartungsbericht</h1>
				<div style="<?= e( $muted ) ?>">
					<?= e( $client['name'] ?? 'Alle betreuten Seiten' ) ?> ·
					<?= e( nl_date( (string) $period['start'], 'd.m.Y' ) ) ?> bis <?= e( nl_date( (string) $period['end'], 'd.m.Y' ) ) ?>
					(<?= e( (string) $period['days'] ) ?> Tage)
				</div>
			</td>
			<td align="right" style="<?= e( $muted ) ?>;font-size:12px;white-space:nowrap">
				Erstellt am<br><strong style="color:#101828"><?= e( nl_date( gmdate( 'Y-m-d H:i:s' ), 'd.m.Y' ) ) ?></strong>
			</td>
		</tr></table>
	</div>

	<div style="<?= e( $box ) ?>">
		<h2 style="margin:0 0 14px;font-size:16px">Auf einen Blick</h2>
		<table width="100%" cellpadding="0" cellspacing="0" style="text-align:center">
			<tr>
				<td style="padding:8px">
					<div style="font-size:26px;font-weight:700"><?= e( (string) $summary['sites'] ) ?></div>
					<div style="<?= e( $muted ) ?>;font-size:12px">Betreute Seiten</div>
				</td>
				<td style="padding:8px">
					<div style="font-size:26px;font-weight:700;color:#12805c"><?= e( (string) $summary['updates_applied'] ) ?></div>
					<div style="<?= e( $muted ) ?>;font-size:12px">Updates eingespielt</div>
				</td>
				<td style="padding:8px">
					<div style="font-size:26px;font-weight:700;color:<?= e( $uptimeColor( (float) $summary['avg_uptime'] ) ) ?>">
						<?= e( nl_number( (float) $summary['avg_uptime'], 2 ) ) ?> %
					</div>
					<div style="<?= e( $muted ) ?>;font-size:12px">Ø Verfügbarkeit</div>
				</td>
				<td style="padding:8px">
					<div style="font-size:26px;font-weight:700"><?= e( (string) $summary['incidents'] ) ?></div>
					<div style="<?= e( $muted ) ?>;font-size:12px">Störungen</div>
				</td>
				<td style="padding:8px">
					<div style="font-size:26px;font-weight:700"><?= e( (string) $summary['avg_security'] ) ?></div>
					<div style="<?= e( $muted ) ?>;font-size:12px">Ø Sicherheitswert</div>
				</td>
			</tr>
		</table>
		<?php if ( (int) $summary['downtime_seconds'] > 0 ) : ?>
			<p style="margin:14px 0 0;<?= e( $muted ) ?>;font-size:13px">
				Gesamte Ausfallzeit im Zeitraum: <strong style="color:#101828"><?= e( nl_duration( (int) $summary['downtime_seconds'] ) ) ?></strong>.
			</p>
		<?php endif; ?>
		<?php if ( (int) $summary['pending_updates'] > 0 ) : ?>
			<p style="margin:6px 0 0;<?= e( $muted ) ?>;font-size:13px">
				Aktuell noch offen: <strong style="color:#101828"><?= e( (string) $summary['pending_updates'] ) ?></strong> Update(s).
			</p>
		<?php endif; ?>
	</div>

	<div style="<?= e( $box ) ?>">
		<h2 style="margin:0 0 14px;font-size:16px">Seiten im Überblick</h2>
		<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">
			<tr>
				<th style="<?= e( $th ) ?>">Seite</th>
				<th style="<?= e( $th ) ?>">WordPress</th>
				<th style="<?= e( $th ) ?>">PHP</th>
				<th style="<?= e( $th ) ?>;text-align:right">Verfügbarkeit</th>
				<th style="<?= e( $th ) ?>;text-align:right">Updates</th>
				<th style="<?= e( $th ) ?>;text-align:right">Sicherheit</th>
			</tr>
			<?php foreach ( $sites as $site ) : ?>
				<?php $percent = (float) $site['availability']['percent']; ?>
				<tr>
					<td style="<?= e( $td ) ?>">
						<strong><?= e( (string) $site['name'] ) ?></strong><br>
						<span style="<?= e( $muted ) ?>;font-size:12px"><?= e( nl_host( (string) $site['url'] ) ) ?></span>
					</td>
					<td style="<?= e( $td ) ?>"><?= e( $site['wp_version'] ?: '—' ) ?></td>
					<td style="<?= e( $td ) ?>"><?= e( $site['php_version'] ?: '—' ) ?></td>
					<td style="<?= e( $td ) ?>;text-align:right;color:<?= e( $uptimeColor( $percent ) ) ?>;font-weight:600">
						<?= e( nl_number( $percent, 2 ) ) ?> %
					</td>
					<td style="<?= e( $td ) ?>;text-align:right">
						<?= empty( $site['managed'] ) ? '—' : e( (string) count( $site['updates_applied'] ) ) . ' eingespielt' ?>
					</td>
					<td style="<?= e( $td ) ?>;text-align:right">
						<?= empty( $site['managed'] ) ? '—' : e( (string) $site['security_score'] ) . '/100' ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
	</div>

	<?php foreach ( $sites as $site ) : ?>
		<div style="<?= e( $box ) ?>">
			<h2 style="margin:0 0 2px;font-size:16px"><?= e( (string) $site['name'] ) ?></h2>
			<div style="<?= e( $muted ) ?>;font-size:12px;margin-bottom:14px"><?= e( (string) $site['url'] ) ?></div>

			<?php if ( empty( $site['managed'] ) ) : ?>
				<p style="<?= e( $muted ) ?>;font-size:13px;margin:0 0 16px">
					Diese Seite läuft nicht auf WordPress und wird von uns überwacht, nicht gewartet.
					Der Bericht zeigt daher nur die Erreichbarkeit.
				</p>
			<?php else : ?>
			<h3 style="margin:0 0 8px;font-size:13px">Eingespielte Updates</h3>
			<?php if ( ! $site['updates_applied'] ) : ?>
				<p style="<?= e( $muted ) ?>;font-size:13px;margin:0 0 16px">In diesem Zeitraum waren keine Updates nötig.</p>
			<?php else : ?>
				<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:16px">
					<tr>
						<th style="<?= e( $th ) ?>">Erweiterung</th>
						<th style="<?= e( $th ) ?>">Typ</th>
						<th style="<?= e( $th ) ?>">Version</th>
						<th style="<?= e( $th ) ?>">Datum</th>
					</tr>
					<?php foreach ( $site['updates_applied'] as $update ) : ?>
						<tr>
							<td style="<?= e( $td ) ?>"><?= e( (string) $update['name'] ) ?></td>
							<td style="<?= e( $td ) ?>"><?= e( nl_update_type( (string) $update['type'] ) ) ?></td>
							<td style="<?= e( $td ) ?>">
								<?php if ( '' !== $update['from_version'] && '' !== $update['to_version'] ) : ?>
									<?= e( (string) $update['from_version'] ) ?> → <strong><?= e( (string) $update['to_version'] ) ?></strong>
								<?php else : ?>
									<?= e( (string) ( $update['to_version'] ?: '—' ) ) ?>
								<?php endif; ?>
							</td>
							<td style="<?= e( $td ) ?>;<?= e( $muted ) ?>"><?= e( nl_date( (string) $update['at'], 'd.m.Y' ) ) ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>
			<?php endif; ?>

			<h3 style="margin:0 0 8px;font-size:13px">Verfügbarkeit</h3>
			<p style="font-size:13px;margin:0 0 10px">
				<strong style="color:<?= e( $uptimeColor( (float) $site['availability']['percent'] ) ) ?>">
					<?= e( nl_number( (float) $site['availability']['percent'], 3 ) ) ?> %
				</strong>
				<span style="<?= e( $muted ) ?>">
					· <?= e( (string) $site['availability']['incidents'] ) ?> Störung(en)
					· <?= e( nl_duration( (int) $site['availability']['downtime_seconds'] ) ) ?> Ausfallzeit
				</span>
			</p>

			<?php if ( $site['incidents'] ) : ?>
				<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:16px">
					<tr>
						<th style="<?= e( $th ) ?>">Beginn</th>
						<th style="<?= e( $th ) ?>">Dauer</th>
						<th style="<?= e( $th ) ?>">Ursache</th>
					</tr>
					<?php foreach ( array_slice( $site['incidents'], 0, 10 ) as $incident ) : ?>
						<tr>
							<td style="<?= e( $td ) ?>"><?= e( nl_date( (string) $incident['started_at'] ) ) ?></td>
							<td style="<?= e( $td ) ?>"><?= e( nl_duration( (int) $incident['seconds'] ) ) ?></td>
							<td style="<?= e( $td ) ?>;<?= e( $muted ) ?>"><?= e( (string) ( $incident['reason'] ?: 'nicht erreichbar' ) ) ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $site['managed'] ) ) : ?>
			<?php if ( $site['maintenance'] ) : ?>
				<h3 style="margin:0 0 8px;font-size:13px">Durchgeführte Wartung</h3>
				<ul style="margin:0 0 16px;padding-left:18px;font-size:13px">
					<?php foreach ( $site['maintenance'] as $run ) : ?>
						<li><?= e( nl_date( (string) $run['at'], 'd.m.Y' ) ) ?> — <?= e( (string) $run['message'] ) ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h3 style="margin:0 0 8px;font-size:13px">Sicherheit</h3>
			<?php if ( ! $site['security_issues'] ) : ?>
				<p style="font-size:13px;margin:0">
					<span style="<?= e( $badge ) ?>;background:#e7f6f0;color:#12805c">Alle Prüfungen bestanden</span>
					<span style="<?= e( $muted ) ?>"> — Bewertung <?= e( (string) $site['security_score'] ) ?>/100.</span>
				</p>
			<?php else : ?>
				<p style="font-size:13px;margin:0 0 8px;<?= e( $muted ) ?>">
					Bewertung <?= e( (string) $site['security_score'] ) ?>/100. Offene Punkte:
				</p>
				<ul style="margin:0;padding-left:18px;font-size:13px">
					<?php foreach ( $site['security_issues'] as $issue ) : ?>
						<li style="margin-bottom:3px">
							<strong><?= e( (string) $issue['label'] ) ?></strong>
							<span style="<?= e( $muted ) ?>"> — <?= e( (string) $issue['detail'] ) ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( (int) $site['pending_updates'] > 0 ) : ?>
				<p style="margin:14px 0 0;font-size:13px">
					<span style="<?= e( $badge ) ?>;background:#fdf3e6;color:#b25e09">
						<?= e( (string) $site['pending_updates'] ) ?> Update(s) offen
					</span>
					<span style="<?= e( $muted ) ?>"> — werden im nächsten Wartungslauf eingespielt.</span>
				</p>
			<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<div style="text-align:center;<?= e( $muted ) ?>;font-size:12px;padding:8px 0 24px">
		Erstellt von <?= e( (string) $agency['name'] ) ?>
		<?php if ( ! empty( $agency['email'] ) ) : ?>
			· <?= e( (string) $agency['email'] ) ?>
		<?php endif; ?>
	</div>
</div>
</body>
</html>
