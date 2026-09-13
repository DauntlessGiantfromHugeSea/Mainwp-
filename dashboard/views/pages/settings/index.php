<?php
/**
 * @var array<string,string>           $settings
 * @var array<string,string>           $catalog
 * @var array<int,string>              $notifyOn
 * @var array<int,array<string,mixed>> $jobs
 * @var string                         $cronToken
 * @var string                         $cronUrl
 * @var array<string,mixed>            $config
 */

use NorthLab\Core\View;
use NorthLab\Repository\ClientRepository;

View::set( 'pageTitle', 'Einstellungen' );

$get = static fn( string $key, string $default = '' ): string => $settings[ $key ] ?? $default;
?>

<div class="tabs">
	<button data-tab="general" class="active">Allgemein</button>
	<button data-tab="automation">Automatisierung</button>
	<button data-tab="notifications">Benachrichtigungen</button>
	<button data-tab="monitoring">Monitoring</button>
	<button data-tab="reports">Berichte</button>
	<button data-tab="system">System</button>
</div>

<div class="tab-panel active" data-tab-panel="general">
	<div class="card">
		<div class="card-head"><h2>Agentur</h2></div>
		<div class="card-body">
			<form method="post" action="<?= e( url( '/settings' ) ) ?>">
				<?= csrf_field() ?>
				<input type="hidden" name="section" value="general">
				<div class="form-grid">
					<div class="field">
						<label for="agency_name">Agenturname</label>
						<input type="text" id="agency_name" name="agency_name" value="<?= e( $get( 'agency_name', 'NorthLab' ) ) ?>" required>
						<div class="hint">Erscheint im Panel, in Berichten und in Webhook-Payloads.</div>
					</div>
					<div class="field">
						<label for="agency_email">Absender-Adresse</label>
						<input type="email" id="agency_email" name="agency_email" value="<?= e( $get( 'agency_email' ) ) ?>">
					</div>
					<div class="field">
						<label for="agency_logo_url">Logo-URL</label>
						<input type="url" id="agency_logo_url" name="agency_logo_url" value="<?= e( $get( 'agency_logo_url' ) ) ?>" placeholder="https://…/logo.svg">
						<div class="hint">Wird in der Seitenleiste und in Kundenberichten verwendet.</div>
					</div>
					<div class="field">
						<label for="agency_color">Akzentfarbe</label>
						<input type="text" id="agency_color" name="agency_color" value="<?= e( $get( 'agency_color', '#2f6df6' ) ) ?>" placeholder="#2f6df6">
					</div>
				</div>
				<button class="btn primary">Speichern</button>
			</form>
		</div>
	</div>
</div>

<div class="tab-panel" data-tab-panel="automation">
	<div class="card">
		<div class="card-head"><h2>Zeitplaner</h2></div>
		<div class="card-body">
			<p class="small">
				Alle Automatismen hängen an einem einzigen Cron-Eintrag. Trage auf dem Server ein:
			</p>
			<pre class="code-block">* * * * * /usr/bin/php <?= e( NL_ROOT ) ?>/bin/cron.php &gt;&gt; <?= e( NL_ROOT ) ?>/storage/logs/cron.log 2&gt;&amp;1</pre>

			<p class="small muted">Ohne Cron-Zugang: diese URL minütlich von einem externen Dienst abrufen lassen.</p>
			<div class="copy-row">
				<input type="text" id="cron-url" readonly class="mono" value="<?= e( $cronUrl ) ?>">
				<button class="btn sm" type="button" data-copy="#cron-url">Kopieren</button>
			</div>
			<form method="post" action="<?= e( url( '/settings' ) ) ?>" class="mt" data-confirm="Neues Cron-Token erzeugen? Die alte URL wird ungültig.">
				<?= csrf_field() ?>
				<input type="hidden" name="section" value="cron_token">
				<button class="btn sm">Token neu erzeugen</button>
			</form>
		</div>

		<div class="card-body tight" style="border-top:1px solid var(--line)">
			<div class="table-wrap">
				<table class="data">
					<thead><tr><th>Aufgabe</th><th>Intervall</th><th>Zuletzt</th><th>Nächster Lauf</th><th>Ergebnis</th><th class="shrink"></th></tr></thead>
					<tbody>
					<?php foreach ( $jobs as $job ) : ?>
						<tr>
							<td><strong><?= e( (string) $job['label'] ) ?></strong><div class="muted small mono"><?= e( (string) $job['name'] ) ?></div></td>
							<td class="small"><?= e( nl_duration( (int) $job['interval'] ) ) ?></td>
							<td class="small muted"><?= e( nl_ago( $job['last_run_at'] ) ) ?></td>
							<td class="small muted"><?= $job['next_run_at'] ? e( nl_date( (string) $job['next_run_at'], 'H:i' ) ) : 'sofort' ?></td>
							<td class="small muted truncate"><?= e( (string) ( $job['last_result'] ?? '—' ) ) ?></td>
							<td class="shrink">
								<form method="post" action="<?= e( url( '/settings' ) ) ?>">
									<?= csrf_field() ?>
									<input type="hidden" name="section" value="run_job">
									<input type="hidden" name="job" value="<?= e( (string) $job['name'] ) ?>">
									<button class="btn sm" data-busy="Läuft…">Jetzt ausführen</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="card">
		<div class="card-head"><h2>Intervalle und automatische Updates</h2></div>
		<div class="card-body">
			<form method="post" action="<?= e( url( '/settings' ) ) ?>">
				<?= csrf_field() ?>
				<input type="hidden" name="section" value="automation">
				<div class="form-grid">
					<div class="field">
						<label for="sync_interval">Sync-Intervall (Sekunden)</label>
						<input type="number" id="sync_interval" name="sync_interval" min="300" step="60" value="<?= e( $get( 'sync_interval', '900' ) ) ?>">
						<div class="hint">Vollständiger Statusabruf je Seite. Minimum 300 Sekunden.</div>
					</div>
					<div class="field">
						<label for="heartbeat_interval">Heartbeat-Intervall (Sekunden)</label>
						<input type="number" id="heartbeat_interval" name="heartbeat_interval" min="60" step="30" value="<?= e( $get( 'heartbeat_interval', '300' ) ) ?>">
						<div class="hint">Leichter Erreichbarkeitstest für die Uptime-Statistik.</div>
					</div>
					<div class="field full inline">
						<input type="checkbox" id="heartbeat_enabled" name="heartbeat_enabled" value="1" <?= '1' === $get( 'heartbeat_enabled', '1' ) ? 'checked' : '' ?>>
						<label for="heartbeat_enabled">Eigene Erreichbarkeitsprüfung aktiv <span class="muted">(abschalten, wenn ein externes Monitoring alles liefert)</span></label>
					</div>
					<div class="field">
						<label for="auto_update_policy">Automatische Updates</label>
						<select id="auto_update_policy" name="auto_update_policy">
							<option value="off" <?= 'off' === $get( 'auto_update_policy', 'off' ) ? 'selected' : '' ?>>Aus — nur manuell</option>
							<option value="minor" <?= 'minor' === $get( 'auto_update_policy' ) ? 'selected' : '' ?>>Nur Minor- und Patch-Versionen</option>
							<option value="all" <?= 'all' === $get( 'auto_update_policy' ) ? 'selected' : '' ?>>Alle Updates, auch Major</option>
						</select>
						<div class="hint">Einzelne Seiten können davon abweichen.</div>
					</div>
					<div class="field">
						<label for="auto_update_window">Wartungsfenster</label>
						<input type="text" id="auto_update_window" name="auto_update_window" value="<?= e( $get( 'auto_update_window' ) ) ?>" placeholder="02:00-05:00">
						<div class="hint">Leer = jederzeit. Fenster über Mitternacht sind erlaubt.</div>
					</div>
					<div class="field full">
						<label for="auto_update_excludes">Von Auto-Updates ausgenommen</label>
						<textarea id="auto_update_excludes" name="auto_update_excludes" placeholder="woocommerce&#10;elementor*&#10;advanced-custom-fields-pro/acf.php"><?= e( $get( 'auto_update_excludes' ) ) ?></textarea>
						<div class="hint">Ein Eintrag pro Zeile. Ordnername, vollständige Plugin-Datei oder Muster mit <code>*</code>.</div>
					</div>
				</div>
				<button class="btn primary">Speichern</button>
			</form>
		</div>
	</div>
</div>

<div class="tab-panel" data-tab-panel="notifications">
	<div class="card">
		<div class="card-head"><h2>E-Mail-Benachrichtigungen</h2></div>
		<div class="card-body">
			<form method="post" action="<?= e( url( '/settings' ) ) ?>">
				<?= csrf_field() ?>
				<input type="hidden" name="section" value="notifications">
				<div class="field" style="max-width:420px">
					<label for="notify_email">Empfänger</label>
					<input type="email" id="notify_email" name="notify_email" value="<?= e( $get( 'notify_email' ) ) ?>" placeholder="leer = Absender-Adresse">
				</div>
				<div class="field">
					<label>Bei diesen Ereignissen benachrichtigen</label>
					<div class="grid cols-3">
						<?php foreach ( $catalog as $event => $label ) : ?>
							<label class="small" style="display:flex;gap:6px;align-items:flex-start">
								<input type="checkbox" name="notify_on[]" value="<?= e( $event ) ?>" <?= in_array( $event, $notifyOn, true ) ? 'checked' : '' ?>>
								<span><?= e( $label ) ?><br><span class="muted mono" style="font-size:11px"><?= e( $event ) ?></span></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
				<button class="btn primary">Speichern</button>
				<p class="hint">Für Chat- und Automations-Ziele sind <a href="<?= e( url( '/webhooks' ) ) ?>">Webhooks</a> die bessere Wahl.</p>
			</form>
		</div>
	</div>
</div>

<div class="tab-panel" data-tab-panel="monitoring">
	<div class="card">
		<div class="card-head"><h2>Eingehende Monitoring-Webhooks</h2></div>
		<div class="card-body">
			<p class="small">
				Jede Seite hat eine eigene Empfangs-URL nach dem Muster
				<code><?= e( rtrim( (string) $config['app_url'], '/' ) ) ?>/api/uptime/&lt;token&gt;</code>.
				Sie steht auf der jeweiligen Seitenübersicht und in der Uptime-Ansicht.
			</p>
			<p class="small muted">
				Automatisch erkannte Formate: UptimeRobot, Better Stack, Uptime Kuma, Pingdom, StatusCake,
				HetrixTools sowie generisches JSON <code>{"status":"up"|"down"}</code>.
			</p>

			<h3 class="mt">Sammel-URL für alle Seiten</h3>
			<?php $globalToken = $get( 'uptime_global_token' ); ?>
			<?php if ( '' !== $globalToken ) : ?>
				<p class="small muted">
					Eine einzige Benachrichtigung im Monitoring genügt. Das Panel ordnet jede Meldung
					anhand der überwachten Adresse der passenden Seite zu — verglichen wird der Hostname.
				</p>
				<div class="copy-row" style="max-width:640px">
					<input type="text" id="uptime-global" readonly class="mono"
						value="<?= e( rtrim( (string) $config['app_url'], '/' ) . '/api/uptime/' . $globalToken ) ?>">
					<button class="btn sm" type="button" data-copy="#uptime-global">Kopieren</button>
				</div>
				<div class="btn-row mt">
					<form method="post" action="<?= e( url( '/settings' ) ) ?>"
						data-confirm="Neues Sammel-Token erzeugen? Die alte URL wird sofort ungültig.">
						<?= csrf_field() ?>
						<input type="hidden" name="section" value="uptime_token">
						<button class="btn sm">Token neu erzeugen</button>
					</form>
					<form method="post" action="<?= e( url( '/settings' ) ) ?>"
						data-confirm="Sammel-URL abschalten? Danach gelten nur noch die Adressen je Seite.">
						<?= csrf_field() ?>
						<input type="hidden" name="section" value="uptime_token">
						<input type="hidden" name="disable" value="1">
						<button class="btn sm danger">Abschalten</button>
					</form>
				</div>
			<?php else : ?>
				<p class="small muted">
					Noch nicht eingerichtet. Ohne Sammel-URL musst du je Monitor die seitenspezifische
					Adresse eintragen.
				</p>
				<form method="post" action="<?= e( url( '/settings' ) ) ?>">
					<?= csrf_field() ?>
					<input type="hidden" name="section" value="uptime_token">
					<button class="btn primary">Sammel-URL erzeugen</button>
				</form>
			<?php endif; ?>

			<h3 class="mt">Signaturprüfung</h3>
			<form method="post" action="<?= e( url( '/settings' ) ) ?>">
				<?= csrf_field() ?>
				<input type="hidden" name="section" value="monitoring">
				<div class="field" style="max-width:520px">
					<label for="uptime_secret">Zusätzliches Signatur-Secret</label>
					<input type="text" id="uptime_secret" name="uptime_secret" class="mono" value="<?= e( $get( 'uptime_secret' ) ) ?>">
					<div class="hint">
						Leer lassen, wenn das Token in der URL genügt. Ist ein Secret gesetzt, muss jede eingehende
						Meldung den Header <code>X-NorthLab-Signature: sha256=&lt;HMAC des Bodys&gt;</code> tragen.
					</div>
				</div>
				<button class="btn primary">Speichern</button>
			</form>
		</div>
	</div>
</div>

<div class="tab-panel" data-tab-panel="reports">
	<div class="card">
		<div class="card-head"><h2>Berichte</h2></div>
		<div class="card-body">
			<form method="post" action="<?= e( url( '/settings' ) ) ?>">
				<?= csrf_field() ?>
				<input type="hidden" name="section" value="reports">
				<div class="form-grid">
					<div class="field">
						<label for="report_default_freq">Standard-Rhythmus für neue Kunden</label>
						<select id="report_default_freq" name="report_default_freq">
							<?php foreach ( ClientRepository::FREQUENCIES as $value => $label ) : ?>
								<option value="<?= e( $value ) ?>" <?= $get( 'report_default_freq', 'monthly' ) === $value ? 'selected' : '' ?>><?= e( $label ) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="field">
						<label for="report_send_hour">Versandzeit (Stunde)</label>
						<input type="number" id="report_send_hour" name="report_send_hour" min="0" max="23" value="<?= e( $get( 'report_send_hour', '8' ) ) ?>">
						<div class="hint">Ortszeit des Servers (<?= e( (string) $config['timezone'] ) ?>).</div>
					</div>
				</div>
				<button class="btn primary">Speichern</button>
			</form>
		</div>
	</div>

	<div class="card">
		<div class="card-head"><h2>Datenaufbewahrung</h2></div>
		<div class="card-body">
			<form method="post" action="<?= e( url( '/settings' ) ) ?>">
				<?= csrf_field() ?>
				<input type="hidden" name="section" value="retention">
				<div class="form-grid">
					<div class="field">
						<label for="activity_retention">Protokoll (Tage)</label>
						<input type="number" id="activity_retention" name="activity_retention" min="7" value="<?= e( $get( 'activity_retention', '180' ) ) ?>">
					</div>
					<div class="field">
						<label for="uptime_retention">Uptime-Daten (Tage)</label>
						<input type="number" id="uptime_retention" name="uptime_retention" min="7" value="<?= e( $get( 'uptime_retention', '365' ) ) ?>">
						<div class="hint">Ältere Daten fehlen dann in Jahresberichten.</div>
					</div>
				</div>
				<button class="btn primary">Speichern</button>
			</form>
		</div>
	</div>
</div>

<div class="tab-panel" data-tab-panel="system">
	<div class="card">
		<div class="card-head"><h2>Installation</h2></div>
		<div class="card-body">
			<dl class="meta">
				<dt>Panel-Version</dt><dd class="mono"><?= e( NL_VERSION ) ?></dd>
				<dt>PHP</dt><dd class="mono"><?= e( PHP_VERSION ) ?></dd>
				<dt>Panel-URL</dt><dd class="mono"><?= e( (string) $config['app_url'] ) ?></dd>
				<dt>Zeitzone</dt><dd><?= e( (string) $config['timezone'] ) ?></dd>
				<dt>Datenbank</dt><dd class="mono"><?= e( (string) $config['db_name'] ) ?> (Präfix <?= e( (string) $config['db_prefix'] ) ?>)</dd>
				<dt>Mailversand</dt><dd><?= e( 'smtp' === $config['mail'] ? 'SMTP' : 'PHP mail()' ) ?></dd>
				<dt>TLS-Prüfung</dt><dd><?= $config['verify_ssl'] ? '<span class="badge ok">aktiv</span>' : '<span class="badge warn">abgeschaltet</span>' ?></dd>
				<dt>Verzeichnis</dt><dd class="mono small"><?= e( NL_ROOT ) ?></dd>
			</dl>

			<p class="small muted mt">
				Diese Werte stehen in der <code>config.php</code> und werden dort geändert.
				Nach dem Ändern von <code>app.key</code> müssen alle Seiten neu verbunden werden.
			</p>
		</div>
	</div>
</div>
