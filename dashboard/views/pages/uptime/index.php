<?php
/**
 * @var array<int,array<string,mixed>> $rows
 * @var array<int,array<string,mixed>> $feed
 * @var int                            $days
 * @var string                         $globalUrl
 */

use NorthLab\Core\View;

View::set( 'pageTitle', 'Uptime' );

$totalDowntime = 0;
$sum           = 0.0;
foreach ( $rows as $row ) {
	$totalDowntime += (int) $row['availability']['downtime_seconds'];
	$sum           += (float) $row['availability']['percent'];
}
$average = $rows ? $sum / count( $rows ) : 100.0;
?>

<div class="grid cols-4" style="margin-bottom:18px">
	<div class="stat <?= e( nl_percent_class( $average ) ) ?>">
		<div class="label">Ø Verfügbarkeit</div>
		<div class="value"><?= e( nl_number( $average, 2 ) ) ?> %</div>
		<div class="sub">letzte <?= e( (string) $days ) ?> Tage</div>
	</div>
	<div class="stat"><div class="label">Gesamte Ausfallzeit</div><div class="value" style="font-size:20px"><?= e( nl_duration( $totalDowntime ) ) ?></div></div>
	<div class="stat"><div class="label">Überwachte Seiten</div><div class="value"><?= e( (string) count( $rows ) ) ?></div></div>
	<div class="stat">
		<div class="label">Zeitraum</div>
		<div class="value" style="font-size:20px">
			<form method="get" style="margin-top:4px">
				<select name="days" data-auto-submit style="font-size:14px">
					<?php foreach ( array( 7, 14, 30, 60, 90 ) as $option ) : ?>
						<option value="<?= e( (string) $option ) ?>" <?= $days === $option ? 'selected' : '' ?>><?= e( (string) $option ) ?> Tage</option>
					<?php endforeach; ?>
				</select>
			</form>
		</div>
	</div>
</div>

<div class="card">
	<div class="card-head">
		<h2>Monitoring anbinden</h2>
		<div class="spacer"></div>
		<a class="btn sm" href="<?= e( url( '/uptime/export' ) ) ?>">Alle URLs als CSV</a>
	</div>
	<div class="card-body">
		<?php if ( '' !== $globalUrl ) : ?>
			<p class="small">
				<strong>Sammel-URL für alle Seiten.</strong> Eine einzige Benachrichtigung im Monitoring genügt —
				das Panel ordnet die Meldung anhand der überwachten Adresse selbst der richtigen Seite zu.
			</p>
			<div class="copy-row" style="max-width:640px">
				<input type="text" id="global-url" readonly class="mono" value="<?= e( $globalUrl ) ?>">
				<button class="btn sm" type="button" data-copy="#global-url">Kopieren</button>
			</div>
			<p class="hint">
				In <strong>Uptime Kuma</strong>: <em>Einstellungen → Benachrichtigungen → Benachrichtigung einrichten</em>,
				Typ <em>Webhook</em>, diese URL eintragen, Inhaltstyp <code>application/json</code>.
				Dann <em>Standardmäßig aktiviert</em> und <em>Auf alle bestehenden Monitore anwenden</em> ankreuzen —
				damit sind alle Monitore auf einmal angebunden.
			</p>
		<?php else : ?>
			<p class="small">
				Bisher hat jede Seite eine eigene Monitoring-URL — die musst du je Monitor einzeln eintragen.
				Bequemer ist eine <strong>Sammel-URL</strong>: eine Benachrichtigung, die auf alle Monitore angewendet wird.
				Das Panel erkennt die Seite dann an der überwachten Adresse.
			</p>
			<?php if ( \NorthLab\Core\Auth::isAdmin() ) : ?>
				<form method="post" action="<?= e( url( '/settings' ) ) ?>">
					<?= csrf_field() ?>
					<input type="hidden" name="section" value="uptime_token">
					<button class="btn primary">Sammel-URL erzeugen</button>
				</form>
			<?php else : ?>
				<p class="hint">Ein Administrator kann sie unter Einstellungen → Monitoring erzeugen.</p>
			<?php endif; ?>
		<?php endif; ?>

		<p class="small muted mt mb0">
			Zusätzlich prüft das Panel die Erreichbarkeit selbst per Heartbeat. Erkannt werden
			UptimeRobot, Better Stack, Uptime Kuma, Pingdom, StatusCake, HetrixTools und beliebige
			Dienste, die <code>{"status":"up"}</code> bzw. <code>{"status":"down"}</code> senden.
		</p>
	</div>
</div>

<div class="card">
	<div class="card-head"><h2>Verfügbarkeit je Seite</h2></div>
	<div class="card-body tight">
		<?php if ( ! $rows ) : ?>
			<div class="empty">Noch keine Seiten verbunden.</div>
		<?php else : ?>
			<div class="table-wrap">
				<table class="data">
					<thead>
					<tr>
						<th>Seite</th><th>Status</th><th class="num">Verfügbarkeit</th><th>Verlauf (30 Tage)</th>
						<th class="num">Ausfall</th><th class="num">Störungen</th><th class="num">Ø Antwort</th><th>Monitoring-URL</th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $index => $row ) : ?>
						<?php $site = $row['site']; $availability = $row['availability']; ?>
						<tr>
							<td>
								<a href="<?= e( url( '/sites/' . $site['id'] ) ) ?>"><strong><?= e( (string) $site['name'] ) ?></strong></a>
								<div class="muted small"><?= e( nl_host( (string) $site['url'] ) ) ?></div>
							</td>
							<td><?= nl_uptime_badge( $site ) ?></td>
							<td class="num">
								<strong class="<?= 'bad' === nl_percent_class( (float) $availability['percent'] ) ? 'muted' : '' ?>">
									<?= e( nl_number( (float) $availability['percent'], 3 ) ) ?> %
								</strong>
								<div><?= nl_meter( (float) $availability['percent'], nl_percent_class( (float) $availability['percent'] ) ) ?></div>
							</td>
							<td><?= nl_spark( $row['series'] ) ?></td>
							<td class="num small"><?= e( nl_duration( (int) $availability['downtime_seconds'] ) ) ?></td>
							<td class="num"><?= e( (string) $availability['incidents'] ) ?></td>
							<td class="num small"><?= $availability['avg_response_ms'] > 0 ? e( (string) $availability['avg_response_ms'] ) . ' ms' : '—' ?></td>
							<td>
								<div class="copy-row" style="max-width:260px">
									<input type="text" readonly class="mono" id="mon-<?= e( (string) $index ) ?>" value="<?= e( (string) $row['monitor_url'] ) ?>">
									<button class="btn sm" type="button" data-copy="#mon-<?= e( (string) $index ) ?>">Kopieren</button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>

<div class="card">
	<div class="card-head"><h2>Letzte Statusmeldungen</h2></div>
	<div class="card-body tight">
		<?php if ( ! $feed ) : ?>
			<div class="empty">Noch keine Meldungen eingegangen.</div>
		<?php else : ?>
			<div class="table-wrap">
				<table class="data">
					<thead><tr><th>Zeitpunkt</th><th>Seite</th><th>Status</th><th>Quelle</th><th class="num">HTTP</th><th class="num">Antwort</th><th>Meldung</th></tr></thead>
					<tbody>
					<?php foreach ( $feed as $event ) : ?>
						<tr>
							<td class="nowrap small muted"><?= e( nl_date( (string) $event['occurred_at'] ) ) ?></td>
							<td><a href="<?= e( url( '/sites/' . $event['site_id'] ) ) ?>"><?= e( (string) $event['site_name'] ) ?></a></td>
							<td>
								<?= 'up' === $event['status']
									? '<span class="badge ok"><span class="dot"></span>Up</span>'
									: '<span class="badge bad"><span class="dot"></span>Down</span>' ?>
							</td>
							<td class="small muted"><?= e( (string) $event['source'] ) ?></td>
							<td class="num small"><?= (int) $event['http_code'] > 0 ? e( (string) $event['http_code'] ) : '—' ?></td>
							<td class="num small"><?= (int) $event['response_ms'] > 0 ? e( (string) $event['response_ms'] ) . ' ms' : '—' ?></td>
							<td class="small muted truncate"><?= e( (string) $event['message'] ) ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>
