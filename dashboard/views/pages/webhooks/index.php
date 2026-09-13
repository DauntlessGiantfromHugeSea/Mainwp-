<?php
/**
 * @var array<int,array<string,mixed>> $webhooks
 * @var array<string,string>           $catalog
 * @var array<int,array<string,mixed>> $clients
 * @var array<int,array<string,mixed>> $deliveries
 * @var array<string,int>              $queue
 * @var int                            $selected
 * @var string                         $incomingUrl
 * @var string                         $apiToken
 * @var string                         $panelUrl
 */

use NorthLab\Core\Auth;
use NorthLab\Core\View;
use NorthLab\Repository\WebhookRepository;

View::set( 'pageTitle', 'Webhooks' );
$canWrite = Auth::canWrite();
?>

<!-- ------------------------------------------------------------ Eingehend -->
<div class="card">
	<div class="card-head"><h2>Eingehend — Monitoring anbinden</h2></div>
	<div class="card-body">
		<?php if ( '' !== $incomingUrl ) : ?>
			<p class="small">Eine URL für alle Seiten. Das Panel erkennt an der gemeldeten Adresse, um welche Seite es geht.</p>
			<div class="copy-row" style="max-width:640px">
				<input type="text" id="incoming-url" readonly class="mono" value="<?= e( $incomingUrl ) ?>">
				<button class="btn sm" type="button" data-copy="#incoming-url">Kopieren</button>
			</div>
			<p class="hint mb0">
				<strong>Uptime Kuma:</strong> Einstellungen → Benachrichtigungen → Benachrichtigung einrichten →
				Typ <em>Webhook</em> → diese URL → Inhaltstyp <code>application/json</code> →
				<em>Standardmäßig aktiviert</em> und <em>Auf alle bestehenden Monitore anwenden</em> ankreuzen.
				Damit sind alle Monitore in einem Schritt verbunden.
			</p>
			<p class="hint mb0">
				Kumas <em>Test</em>-Knopf bestätigt nur, dass die Adresse erreichbar ist —
				er schickt keine Seitenangabe mit. Zugeordnet wird erst bei einer echten Statusmeldung.
			</p>
		<?php else : ?>
			<p class="small muted mb0">Ein Administrator muss die Empfangs-URL einmalig erzeugen — sie erscheint hier automatisch beim nächsten Aufruf.</p>
		<?php endif; ?>
	</div>
</div>

<!-- ------------------------------------------------------------ Ausgehend -->
<div class="card">
	<div class="card-head">
		<h2>Ausgehend — Ereignisse verschicken</h2>
		<div class="spacer"></div>
		<span class="muted small">
			<?= e( (string) $queue['pending'] ) ?> offen · <?= e( (string) $queue['success'] ) ?> zugestellt
			<?php if ( $queue['failed'] > 0 ) : ?>
				· <span class="badge bad"><?= e( (string) $queue['failed'] ) ?> aufgegeben</span>
			<?php endif; ?>
		</span>
	</div>

	<?php if ( $canWrite ) : ?>
		<div class="card-body" style="border-bottom:1px solid var(--line)">
			<form method="post" action="<?= e( url( '/webhooks' ) ) ?>" class="btn-row" style="align-items:flex-end">
				<?= csrf_field() ?>
				<div class="field" style="flex:1;min-width:260px;margin:0">
					<label for="new_url">Ziel-URL</label>
					<input type="url" id="new_url" name="target_url" required placeholder="https://hooks.slack.com/…">
				</div>
				<button class="btn primary">Hinzufügen</button>
			</form>
			<p class="hint">Neue Endpunkte bekommen <strong>alle Ereignisse</strong> und ein Secret. Beides lässt sich danach einschränken.</p>

			<hr style="border:0;border-top:1px solid var(--line);margin:16px 0">

			<form method="post" action="<?= e( url( '/webhooks/snapshot' ) ) ?>" class="btn-row" style="align-items:flex-end">
				<?= csrf_field() ?>
				<div class="field" style="margin:0">
					<label for="snap_days">Zeitraum</label>
					<select id="snap_days" name="days" style="width:auto">
						<option value="30" selected>letzte 30 Tage</option>
						<option value="90">letztes Quartal</option>
						<option value="365">letztes Jahr</option>
					</select>
				</div>
				<button class="btn" data-busy="Wird eingereiht…">Gesamtstand jetzt senden</button>
			</form>
			<p class="hint mb0">
				Schickt <strong>alles auf einmal</strong> — Kunden, Seiten, Verfügbarkeit, eingespielte und
				offene Updates, Sicherheitsbefunde. Gedacht für die Erstbefüllung eines angeschlossenen
				Portals. Danach halten die laufenden Ereignisse den Stand aktuell, und einmal täglich
				geht automatisch ein frischer Gesamtstand raus.
			</p>
		</div>
	<?php endif; ?>

	<div class="card-body tight">
		<?php if ( ! $webhooks ) : ?>
			<div class="empty"><strong>Noch kein Endpunkt</strong>Trage oben eine URL ein — Slack, n8n, Make, Zapier oder ein eigenes System.</div>
		<?php else : ?>
			<div class="table-wrap">
				<table class="data">
					<thead><tr><th>Ziel</th><th>Ereignisse</th><th>Status</th><th>Zuletzt</th><th class="shrink"></th></tr></thead>
					<tbody>
					<?php foreach ( $webhooks as $webhook ) : ?>
						<?php
						$subscribed = WebhookRepository::events( $webhook );
						$alle       = count( $subscribed ) === count( $catalog );
						?>
						<tr>
							<td>
								<strong><?= e( (string) $webhook['name'] ) ?></strong>
								<div class="muted small truncate mono"><?= e( (string) $webhook['target_url'] ) ?></div>
								<?php if ( ! empty( $webhook['client_name'] ) ) : ?>
									<span class="tag">nur <?= e( (string) $webhook['client_name'] ) ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $alle ) : ?>
									<span class="badge ok">alle</span>
								<?php else : ?>
									<span class="badge info"><?= e( (string) count( $subscribed ) ) ?> von <?= e( (string) count( $catalog ) ) ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( empty( $webhook['is_active'] ) ) : ?>
									<span class="badge">pausiert</span>
								<?php elseif ( 'success' === $webhook['last_status'] ) : ?>
									<span class="badge ok"><span class="dot"></span>läuft</span>
								<?php elseif ( 'failed' === $webhook['last_status'] ) : ?>
									<span class="badge bad"><span class="dot"></span>Fehler</span>
								<?php else : ?>
									<span class="badge warn">wartet</span>
								<?php endif; ?>
							</td>
							<td class="muted small nowrap"><?= e( nl_ago( $webhook['last_delivery_at'] ) ) ?></td>
							<td class="shrink">
								<div class="btn-row">
									<?php if ( $canWrite ) : ?>
										<form method="post" action="<?= e( url( '/webhooks/' . $webhook['id'] . '/test' ) ) ?>">
											<?= csrf_field() ?>
											<button class="btn sm" data-busy="…">Test</button>
										</form>
									<?php endif; ?>
									<button class="btn sm" type="button" data-toggle="#hook-<?= e( (string) $webhook['id'] ) ?>">Ändern</button>
								</div>
							</td>
						</tr>
						<tr id="hook-<?= e( (string) $webhook['id'] ) ?>" hidden>
							<td colspan="5" style="background:rgba(255,255,255,.02)">
								<form method="post" action="<?= e( url( '/webhooks/' . $webhook['id'] ) ) ?>">
									<?= csrf_field() ?>
									<input type="hidden" name="has_active_field" value="1">
									<div class="form-grid">
										<div class="field">
											<label>Name</label>
											<input type="text" name="name" value="<?= e( (string) $webhook['name'] ) ?>">
										</div>
										<div class="field">
											<label>Nur für Kunde</label>
											<select name="client_id">
												<option value="">alle Kunden</option>
												<?php foreach ( $clients as $client ) : ?>
													<option value="<?= e( (string) $client['id'] ) ?>" <?= (int) $webhook['client_id'] === (int) $client['id'] ? 'selected' : '' ?>>
														<?= e( (string) $client['name'] ) ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>
										<div class="field full">
											<label>Ziel-URL</label>
											<input type="url" name="target_url" value="<?= e( (string) $webhook['target_url'] ) ?>" required>
										</div>
										<div class="field full">
											<label>Secret <span class="muted">— zum Prüfen der Signatur beim Empfänger</span></label>
											<input type="text" name="secret" class="mono" value="<?= e( (string) $webhook['secret'] ) ?>">
										</div>
									</div>

									<details<?= $alle ? '' : ' open' ?>>
										<summary class="small" style="cursor:pointer;padding:6px 0">
											Ereignisse einschränken <span class="muted">(leer lassen = alle)</span>
										</summary>
										<div class="grid cols-3" style="margin-top:10px">
											<?php foreach ( $catalog as $event => $label ) : ?>
												<label class="small" style="display:flex;gap:7px;align-items:flex-start">
													<input type="checkbox" name="events[]" value="<?= e( $event ) ?>" <?= in_array( $event, $subscribed, true ) ? 'checked' : '' ?>>
													<span><?= e( $label ) ?></span>
												</label>
											<?php endforeach; ?>
										</div>
									</details>

									<div class="btn-row mt">
										<label class="small" style="display:flex;gap:7px;align-items:center">
											<input type="checkbox" name="is_active" value="1" <?= ! empty( $webhook['is_active'] ) ? 'checked' : '' ?>> aktiv
										</label>
										<button class="btn primary sm" <?= $canWrite ? '' : 'disabled' ?>>Speichern</button>
										<span style="flex:1"></span>
										<a class="btn sm" href="<?= e( url( '/webhooks?webhook_id=' . $webhook['id'] ) ) ?>">Zustellungen</a>
										<?php if ( $canWrite ) : ?>
											<button class="btn sm danger" type="submit"
												formaction="<?= e( url( '/webhooks/' . $webhook['id'] . '/delete' ) ) ?>" formnovalidate
												onclick="return confirm('Endpunkt wirklich löschen?')">Löschen</button>
										<?php endif; ?>
									</div>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>

<!-- -------------------------------------------------------- Was ankommt -->
<div class="card">
	<div class="card-head">
		<h2>Was beim Empfänger ankommt</h2>
		<div class="spacer"></div>
		<button class="btn sm" type="button" data-toggle="#payload-beispiel">Beispiel zeigen</button>
	</div>
	<div class="card-body" id="payload-beispiel" hidden>
		<p class="small">Nach jedem Update-Lauf geht dieses Paket raus — mit jeder einzelnen Erweiterung und den Versionsnummern davor und danach:</p>
		<pre class="code-block">POST <em>deine Ziel-URL</em>
X-NorthLab-Event:     update.applied
X-NorthLab-Timestamp: 1789456200
X-NorthLab-Signature: sha256=&lt;HMAC über "timestamp.body" mit deinem Secret&gt;

{
  "event": "update.applied",
  "occurred_at": "2026-09-13T18:30:00+00:00",
  "agency": "NorthLab",
  "message": "3 Update(s) auf \"Kunde Müller\" eingespielt.",
  "site": { "id": 7, "name": "Kunde Müller", "url": "https://kunde.de", "client_id": 2 },
  "data": {
    "items": [
      { "type": "plugin", "name": "WooCommerce",  "from_version": "9.3.1", "to_version": "9.4.2", "success": true },
      { "type": "plugin", "name": "Yoast SEO",    "from_version": "23.1",  "to_version": "23.4",  "success": true },
      { "type": "core",   "name": "WordPress",    "from_version": "6.6.2", "to_version": "6.7.1", "success": true }
    ]
  }
}</pre>
		<p class="small muted">Die übrigen Ereignisse folgen demselben Aufbau — nur <code>data</code> unterscheidet sich.</p>
		<div class="table-wrap">
			<table class="data">
				<thead><tr><th>Ereignis</th><th>Wird ausgelöst</th></tr></thead>
				<tbody>
				<?php
				$wann = array(
					'update.applied'    => 'nach jedem erfolgreichen Update — mit Liste aller Erweiterungen und Versionen',
					'update.failed'     => 'wenn ein Update scheitert, mit Fehlermeldung je Eintrag',
					'updates.available' => 'sobald bei einem Sync neue Updates auftauchen',
					'site.offline'      => 'wenn eine Seite nicht mehr antwortet',
					'site.online'       => 'wenn sie zurück ist, mit Dauer des Ausfalls',
					'sync.failed'       => 'wenn eine Seite beim Sync nicht erreichbar war',
					'security.changed'  => 'wenn die Sicherheitsbewertung deutlich fällt',
					'maintenance.done'  => 'nach Wartungsaufgaben, mit Anzahl bereinigter Datensätze',
					'report.generated'  => 'wenn ein Kundenbericht erstellt wurde, mit allen Kennzahlen',
					'site.connected'    => 'wenn eine Seite verbunden wurde',
					'site.disconnected' => 'wenn eine Seite entfernt wurde',
					'snapshot.full'     => 'kompletter Stand aller Kunden und Seiten — manuell per Knopf und einmal täglich automatisch',
				);
				foreach ( $catalog as $event => $label ) :
					?>
					<tr>
						<td class="mono small nowrap"><?= e( $event ) ?></td>
						<td class="small muted"><?= e( $wann[ $event ] ?? $label ) ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<!-- ----------------------------------------------------- Kundenportal -->
<div class="card">
	<div class="card-head">
		<h2>Für dein Kundenportal</h2>
		<div class="spacer"></div>
		<button class="btn sm" type="button" data-toggle="#portal-api">Details</button>
	</div>
	<div class="card-body">
		<p class="small mb0">
			Webhooks liefern einzelne Ereignisse, sobald sie passieren. Für Berichte brauchst du
			zusätzlich den <strong>Gesamtstand auf Abruf</strong> — Seiten, Kunden, Verfügbarkeit,
			eingespielte und offene Updates in einem Aufruf.
			<?php if ( '' !== $apiToken ) : ?>
				Das Token dafür steht bereit.
			<?php else : ?>
				Ein Administrator erzeugt das Token unter <a href="<?= e( url( '/settings#monitoring' ) ) ?>">Einstellungen → Monitoring</a>.
			<?php endif; ?>
		</p>

		<div id="portal-api" hidden class="mt">
			<?php if ( '' !== $apiToken ) : ?>
				<div class="field">
					<label>Zugriffstoken</label>
					<div class="copy-row" style="max-width:520px">
						<input type="text" id="api-token" readonly class="mono" value="<?= e( $apiToken ) ?>">
						<button class="btn sm" type="button" data-copy="#api-token">Kopieren</button>
					</div>
					<div class="hint">Wie ein Passwort behandeln — wer es hat, liest alle Paneldaten.</div>
				</div>
			<?php endif; ?>

			<p class="small"><strong>Gesamtstand abholen</strong> — genau die Daten, aus denen auch die Panel-Berichte entstehen:</p>
			<pre class="code-block">curl -H "Authorization: Bearer &lt;TOKEN&gt;"   "<?= e( $panelUrl ) ?>/api/v1/export?days=30"</pre>

			<p class="small">Weitere Endpunkte:</p>
			<div class="table-wrap">
				<table class="data">
					<thead><tr><th>Aufruf</th><th>Liefert</th></tr></thead>
					<tbody>
						<tr><td class="mono small nowrap">/api/v1/export?days=30</td>
							<td class="small muted">Kennzahlen, Seiten, Verfügbarkeit, Störungen, eingespielte Updates des Zeitraums, offene Updates, Sicherheitsbefunde. Mit <code>&amp;client_id=…</code> auf einen Kunden begrenzen.</td></tr>
						<tr><td class="mono small nowrap">/api/v1/sites</td>
							<td class="small muted">alle Seiten mit aktuellem Zustand, Versionen, Tags</td></tr>
						<tr><td class="mono small nowrap">/api/v1/updates</td>
							<td class="small muted">alle offenen Updates, filterbar nach <code>site_id</code>, <code>client_id</code>, <code>type</code></td></tr>
					</tbody>
				</table>
			</div>

			<p class="small muted mb0 mt">
				Das Token geht wahlweise als <code>Authorization: Bearer …</code> oder als <code>?token=…</code> mit.
				Sinnvolle Aufteilung: Webhooks für alles, was sofort auffallen soll, der Abruf einmal je Berichtslauf.
			</p>
		</div>
	</div>
</div>

<!-- --------------------------------------------------------- Zustellungen -->
<div class="card">
	<div class="card-head">
		<h2>Letzte Zustellungen</h2>
		<div class="spacer"></div>
		<?php if ( $selected > 0 ) : ?>
			<a class="btn sm" href="<?= e( url( '/webhooks' ) ) ?>">Filter aufheben</a>
		<?php endif; ?>
	</div>
	<div class="card-body tight">
		<?php if ( ! $deliveries ) : ?>
			<div class="empty">Noch nichts verschickt.</div>
		<?php else : ?>
			<div class="table-wrap">
				<table class="data">
					<thead><tr><th>Zeitpunkt</th><th>Ereignis</th><th>Ziel</th><th>Status</th><th>Antwort</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $deliveries as $delivery ) : ?>
						<tr>
							<td class="nowrap small muted"><?= e( nl_date( (string) $delivery['updated_at'] ) ) ?></td>
							<td class="mono small"><?= e( (string) $delivery['event'] ) ?></td>
							<td class="small"><?= e( (string) ( $delivery['webhook_name'] ?? '—' ) ) ?></td>
							<td>
								<?php $status = (string) $delivery['status']; ?>
								<?php if ( 'success' === $status ) : ?>
									<span class="badge ok">zugestellt</span>
								<?php elseif ( 'failed' === $status ) : ?>
									<span class="badge bad">aufgegeben</span>
								<?php else : ?>
									<span class="badge warn">Versuch <?= e( (string) $delivery['attempts'] ) ?></span>
								<?php endif; ?>
							</td>
							<td class="small muted truncate">
								<?= (int) $delivery['response_code'] > 0 ? 'HTTP ' . e( (string) $delivery['response_code'] ) . ' · ' : '' ?>
								<?= e( (string) $delivery['response_body'] ) ?>
							</td>
							<td class="shrink">
								<?php if ( $canWrite && 'success' !== $status ) : ?>
									<form method="post" action="<?= e( url( '/webhooks/deliveries/' . $delivery['id'] . '/retry' ) ) ?>">
										<?= csrf_field() ?>
										<button class="btn sm">Nochmal</button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
	<div class="card-foot small muted">
		Fehlgeschlagene Zustellungen wiederholt das Panel fünfmal mit wachsendem Abstand (1 Min. bis 3 Std.).
	</div>
</div>
