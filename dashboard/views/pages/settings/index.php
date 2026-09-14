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
	<button data-tab="mmode">Wartungsseite</button>
	<button data-tab="branding">Branding</button>
	<button data-tab="icon">Symbol</button>
	<button data-tab="push">Meldungen aufs Handy</button>
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

<div class="tab-panel" data-tab-panel="mmode" id="mmode">
	<div class="grid side">
		<div class="card">
			<div class="card-head"><h2>Aussehen der Wartungsseite</h2></div>
			<div class="card-body">
				<p class="small muted">
					Diese Gestaltung gilt für alle Seiten, die du in den Wartungsmodus schaltest.
					Überschrift und Text lassen sich beim Einschalten pro Seite noch ändern.
				</p>
				<form method="post" action="<?= e( url( '/settings' ) ) ?>">
					<?= csrf_field() ?>
					<input type="hidden" name="section" value="mmode">
					<div class="form-grid">
						<div class="field">
							<label for="mmode_headline">Überschrift</label>
							<input type="text" id="mmode_headline" name="mmode_headline" maxlength="80"
								value="<?= e( $get( 'mmode_headline', 'Wartungsmodus' ) ) ?>">
						</div>
						<div class="field">
							<label for="mmode_color">Akzentfarbe</label>
							<input type="text" id="mmode_color" name="mmode_color" class="mono" placeholder="#f9907a"
								value="<?= e( $get( 'mmode_color', $get( 'agency_color', '#f9907a' ) ) ) ?>">
							<div class="hint">Überschrift und Schimmer im Hintergrund. Hex, z.&nbsp;B. <code>#f9907a</code>.</div>
						</div>
						<div class="field full">
							<label for="mmode_message">Text unter der Überschrift</label>
							<input type="text" id="mmode_message" name="mmode_message" maxlength="200"
								value="<?= e( $get( 'mmode_message', 'Wir sind gleich wieder da.' ) ) ?>">
						</div>
						<div class="field full">
							<label for="mmode_logo">Logo (URL)</label>
							<input type="url" id="mmode_logo" name="mmode_logo" placeholder="https://…"
								value="<?= e( $get( 'mmode_logo', $get( 'agency_logo_url', '' ) ) ) ?>">
							<div class="hint">
								Leer lassen, um stattdessen den Namen der Kundenseite zu zeigen. Die Adresse muss
								öffentlich erreichbar sein — Besucher laden das Bild direkt von dort.
							</div>
						</div>
						<div class="field">
							<label for="mmode_retry_after">Retry-After (Sekunden)</label>
							<input type="number" id="mmode_retry_after" name="mmode_retry_after" min="60" max="86400" step="60"
								value="<?= e( $get( 'mmode_retry_after', '3600' ) ) ?>">
							<div class="hint">Sagt Suchmaschinen, wann sie es wieder versuchen sollen.</div>
						</div>
					</div>
					<button class="btn primary">Speichern</button>
				</form>
			</div>
		</div>

		<div class="card">
			<div class="card-head"><h3>So sieht es aus</h3></div>
			<div class="card-body small">
				<p>
					Besucher bekommen die Seite mit dem Status <code>503 Service Unavailable</code> und einem
					<code>Retry-After</code>-Kopf. Suchmaschinen werten das als vorübergehend und nehmen die
					Seite nicht aus dem Index — anders als bei einer Weiterleitung oder einem 404.
				</p>
				<p>
					Angemeldete Benutzer mit Schreibrechten sehen die Seite ganz normal und können weiterarbeiten.
					Auch <code>wp-admin</code>, <code>wp-login.php</code> und die REST-Schnittstelle bleiben offen.
				</p>
				<p class="muted">
					Wird das Child-Plugin deaktiviert, während der Wartungsmodus läuft, schaltet es ihn selbst
					ab — eine Seite kann so nicht versehentlich gesperrt bleiben.
				</p>
			</div>
		</div>
	</div>
</div>

<div class="tab-panel" data-tab-panel="branding" id="branding">
	<div class="grid side">
		<div class="card">
			<div class="card-head"><h2>Support-Leiste und Login-Branding</h2></div>
			<div class="card-body">
				<p class="small muted">
					Diese Angaben gelten für alle Seiten. Ein- und ausgeschaltet wird pro Seite —
					auf der jeweiligen Seitenübersicht oder als Sammelaktion in der Seitenliste.
				</p>
				<form method="post" action="<?= e( url( '/settings' ) ) ?>">
					<?= csrf_field() ?>
					<input type="hidden" name="section" value="branding">
					<div class="form-grid">
						<div class="field full">
							<label for="branding_logo">Logo der Agentur (URL)</label>
							<input type="url" id="branding_logo" name="branding_logo" placeholder="https://…"
								value="<?= e( $get( 'branding_logo', $get( 'agency_logo_url', '' ) ) ) ?>">
							<div class="hint">
								Steht links in der Support-Leiste und in der Leiste über dem Login.
								Muss öffentlich erreichbar sein — die Kundenseite lädt es direkt von dort.
							</div>
						</div>
						<div class="field">
							<label for="branding_site">Website</label>
							<input type="url" id="branding_site" name="branding_site" placeholder="https://north-lab.de"
								value="<?= e( $get( 'branding_site' ) ) ?>">
						</div>
						<div class="field">
							<label for="branding_email">E-Mail</label>
							<input type="email" id="branding_email" name="branding_email" placeholder="hallo@north-lab.de"
								value="<?= e( $get( 'branding_email', $get( 'agency_email', '' ) ) ) ?>">
						</div>
						<div class="field">
							<label for="branding_phone">Telefon</label>
							<input type="text" id="branding_phone" name="branding_phone" placeholder="+49 1590 5535295"
								value="<?= e( $get( 'branding_phone' ) ) ?>">
							<div class="hint">Wird angezeigt und zugleich als Wählnummer verlinkt.</div>
						</div>
						<div class="field">
							<label for="branding_capability">Wer sieht die Support-Leiste?</label>
							<select id="branding_capability" name="branding_capability">
								<?php foreach ( \NorthLab\Service\BrandingService::AUDIENCES as $value => $label ) : ?>
									<option value="<?= e( $value ) ?>" <?= $value === $get( 'branding_capability', 'read' ) ? 'selected' : '' ?>>
										<?= e( $label ) ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="field full">
							<label for="branding_text">Text in der Support-Leiste</label>
							<input type="text" id="branding_text" name="branding_text" maxlength="120"
								value="<?= e( $get( 'branding_text', 'Kontakt bei Fragen oder Problemen:' ) ) ?>">
						</div>
						<div class="field">
							<label for="branding_login_text">Text in der Login-Leiste</label>
							<input type="text" id="branding_login_text" name="branding_login_text" maxlength="60"
								value="<?= e( $get( 'branding_login_text', 'Betreut von' ) ) ?>">
						</div>
						<div class="field">
							<label for="branding_accent">Akzentfarbe</label>
							<input type="text" id="branding_accent" name="branding_accent" class="mono" placeholder="#e8917a"
								value="<?= e( $get( 'branding_accent', '#e8917a' ) ) ?>">
							<div class="hint">Strich links, Rahmen und Symbole.</div>
						</div>
						<div class="field">
							<label for="branding_tint">Hintergrund</label>
							<input type="text" id="branding_tint" name="branding_tint" class="mono" placeholder="#fdf3f0"
								value="<?= e( $get( 'branding_tint', '#fdf3f0' ) ) ?>">
						</div>
					</div>
					<button class="btn primary">Speichern</button>
				</form>
			</div>
		</div>

		<div class="card">
			<div class="card-head"><h3>Was wo erscheint</h3></div>
			<div class="card-body small">
				<p>
					<strong>Support-Leiste:</strong> oben im WordPress-Backend, vor allen Hinweisen.
					Logo, Ansprache und die drei Kontaktwege.
				</p>
				<p>
					<strong>Login-Leiste:</strong> schmaler Streifen ganz oben auf der Anmeldeseite,
					mit Logo, „<?= e( $get( 'branding_login_text', 'Betreut von' ) ) ?>" und deiner Adresse.
				</p>
				<p>
					<strong>Kundenlogo auf der Anmeldeseite:</strong> ersetzt das WordPress-Logo über dem
					Anmeldeformular. Das steht <em>pro Seite</em>, weil jeder Kunde ein eigenes hat —
					einzutragen auf der jeweiligen Seitenübersicht.
				</p>
				<p class="muted">
					Ein Kunde kann das Branding auf seiner Seite unter <em>Einstellungen → NorthLab</em>
					komplett unterbinden. Das Panel meldet dann, dass es dort abgeschaltet ist.
				</p>
			</div>
		</div>
	</div>
</div>

<div class="tab-panel" data-tab-panel="icon" id="icon">
	<div class="grid side">
		<div class="card">
			<div class="card-head"><h2>Symbol des Panels</h2></div>
			<div class="card-body">
				<p class="small muted">
					Steht im Browser-Reiter, als Lesezeichen und als Symbol der installierten
					Anwendung auf Handy und Desktop. Dunkler Grund, dein Logo darauf.
				</p>

				<form method="post" action="<?= e( url( '/settings' ) ) ?>" enctype="multipart/form-data">
					<?= csrf_field() ?>
					<input type="hidden" name="section" value="icon">

					<div class="form-grid">
						<div class="field full">
							<label for="icon_url">Logo von einer Adresse holen</label>
							<input type="url" id="icon_url" name="icon_url" placeholder="https://…/logo.svg">
							<div class="hint">
								PNG, JPEG, WebP, GIF oder SVG, bis 2 MB. Das Panel lädt die Datei einmal
								herunter und verwahrt sie — sie muss danach nicht erreichbar bleiben.
							</div>
						</div>

						<div class="field full">
							<label for="icon_file">…oder eine Datei hochladen</label>
							<input type="file" id="icon_file" name="icon_file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
						</div>

						<div class="field">
							<label for="icon_bg">Hintergrund</label>
							<input type="text" id="icon_bg" name="icon_bg" class="mono" placeholder="#000000"
								value="<?= e( $get( 'icon_bg', '#000000' ) ) ?>">
							<div class="hint">Hex. Schwarz ist die Vorgabe.</div>
						</div>

						<div class="field">
							<label for="icon_padding">Rand um das Logo</label>
							<input type="number" id="icon_padding" name="icon_padding" min="0" max="40" step="1"
								value="<?= e( $get( 'icon_padding', '18' ) ) ?>">
							<div class="hint">
								In Prozent. Android schneidet Symbole rund zu — unter 15 % kann davon
								etwas verloren gehen.
							</div>
						</div>
					</div>

					<button class="btn primary" data-busy="Übernehme…">Logo übernehmen</button>
				</form>
			</div>
		</div>

		<div>
			<div class="card" id="icon-box" data-icon-source="<?= e( url( '/branding/logo' ) ) ?>">
				<div class="card-head">
					<h3>Vorschau</h3>
					<div class="spacer"></div>
					<?php if ( $iconOwn ) : ?>
						<span class="badge ok">eigenes Symbol aktiv</span>
					<?php endif; ?>
				</div>
				<div class="card-body" style="text-align:center">
					<?php if ( ! $iconSource ) : ?>
						<img src="<?= e( url( '/branding/icon/icon-192.png' ) . '?v=' . $iconStamp ) ?>"
							alt="" width="128" height="128" style="border-radius:28px">
						<p class="small muted mt">
							Noch kein eigenes Logo hinterlegt — gezeigt wird das mitgelieferte Symbol.
						</p>
					<?php else : ?>
						<canvas id="icon-canvas" width="256" height="256"
							style="width:128px;height:128px;border-radius:28px;background:#111"></canvas>

						<div class="notice mt" data-icon-status>Wird geladen…</div>

						<div class="btn-row" style="justify-content:center">
							<button class="btn primary" type="button" data-icon-save disabled data-busy="Erzeuge…">
								Symbole erzeugen
							</button>
						</div>

						<p class="small muted mt">
							Farbe und Rand wirken sofort in der Vorschau. Erst „Symbole erzeugen“
							schreibt sie fest.
						</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="card">
				<div class="card-head"><h3>Wie das gebaut wird</h3></div>
				<div class="card-body small">
					<p>
						Zusammengesetzt wird im Browser, nicht auf dem Server. Zwei Gründe: die
						Bildbibliothek GD gehört nicht zu den Voraussetzungen dieser Installation und
						fehlt auf manchen Servern — und sie kann keine SVG-Logos lesen, was viele
						Logos heute sind.
					</p>
					<p>
						Für den Browser-Reiter entsteht zusätzlich eine SVG-Fassung. Die baut das
						Panel selbst zusammen; sie braucht keinen Zeichner und bleibt bei jeder
						Grösse scharf.
					</p>
					<?php if ( $iconSource || $iconOwn ) : ?>
						<form method="post" action="<?= e( url( '/settings' ) ) ?>" class="mt"
							data-confirm="Eigenes Logo und alle daraus erzeugten Symbole entfernen?">
							<?= csrf_field() ?>
							<input type="hidden" name="section" value="icon_reset">
							<button class="btn danger">Zurück zum mitgelieferten Symbol</button>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>

<div class="tab-panel" data-tab-panel="push" id="push">
	<div class="grid side">
		<div>
			<div class="card" id="push-box">
				<div class="card-head">
					<h2>Dieses Gerät</h2>
					<div class="spacer"></div>
					<?php if ( $pushCount > 0 ) : ?>
						<span class="badge ok"><?= e( (string) $pushCount ) ?> Gerät(e) angemeldet</span>
					<?php endif; ?>
				</div>
				<div class="card-body">
					<?php if ( ! $pushReady ) : ?>
						<div class="notice warn">
							Für Push fehlt noch ein Schlüsselpaar. Es gehört dem Panel und weist es
							gegenüber den Push-Diensten von Chrome, Firefox und Safari aus.
						</div>
						<form method="post" action="<?= e( url( '/settings' ) ) ?>">
							<?= csrf_field() ?>
							<input type="hidden" name="section" value="push_keys">
							<button class="btn primary">Schlüsselpaar erzeugen</button>
						</form>
					<?php else : ?>
						<div class="notice" data-push-status>Wird geprüft…</div>

						<div class="btn-row">
							<button class="btn primary" type="button" data-push-enable hidden>Meldungen einschalten</button>
							<button class="btn" type="button" data-push-disable hidden>Auf diesem Gerät abschalten</button>
						</div>

						<form method="post" action="<?= e( url( '/push/test' ) ) ?>" id="push-test" class="mt" hidden>
							<?= csrf_field() ?>
							<button class="btn" data-busy="Verschicke…">Probemeldung an meine Geräte</button>
						</form>
					<?php endif; ?>

					<p class="small muted mt">
						Die Einstellung gilt pro Gerät und Browser. Auf dem iPhone funktioniert Push erst,
						wenn das Panel über <em>Teilen → Zum Home-Bildschirm</em> installiert wurde — das ist
						eine Vorgabe von Apple, keine Einschränkung des Panels.
					</p>
				</div>
			</div>

			<div class="card">
				<div class="card-head"><h2>Wobei soll das Handy klingeln?</h2></div>
				<div class="card-body">
					<p class="small muted">
						Getrennt von den E-Mail-Hinweisen: nicht jedes Ereignis, das eine Mail wert ist,
						muss auch sofort auf dem Sperrbildschirm stehen.
					</p>
					<form method="post" action="<?= e( url( '/settings' ) ) ?>">
						<?= csrf_field() ?>
						<input type="hidden" name="section" value="push">
						<div class="grid cols-2">
							<?php foreach ( $catalog as $key => $label ) : ?>
								<label class="field inline full" style="display:flex;gap:6px;align-items:flex-start">
									<input type="checkbox" name="push_on[]" value="<?= e( $key ) ?>"
										<?= in_array( $key, $pushOn, true ) ? 'checked' : '' ?>>
									<span><?= e( $label ) ?><br>
										<span class="muted mono" style="font-size:11px"><?= e( $key ) ?></span></span>
								</label>
							<?php endforeach; ?>
						</div>
						<button class="btn primary">Speichern</button>
					</form>
				</div>
			</div>
		</div>

		<div>
			<div class="card">
				<div class="card-head"><h3>Wer bekommt was</h3></div>
				<div class="card-body small">
					<p>
						Eine Meldung geht nur an Konten, die die betroffene Seite auch im Panel sehen
						dürfen. Wer nur einzelne Kundenseiten zugewiesen bekommen hat, erfährt nichts
						über die übrigen.
					</p>
					<p>
						Der Inhalt der Meldung ist für genau ein Gerät verschlüsselt. Der Push-Dienst
						von Google, Mozilla oder Apple leitet nur weiter und kann nicht mitlesen.
					</p>
					<p class="muted">
						Antwortet ein Gerät mehrfach nicht mehr, trägt sich das Panel selbst aus —
						etwa nachdem jemand die Anwendung deinstalliert hat.
					</p>
				</div>
			</div>

			<?php if ( $pushReady ) : ?>
				<div class="card">
					<div class="card-head"><h3>Schlüsselpaar</h3></div>
					<div class="card-body small">
						<p class="muted">
							Ein neues Paar macht alle angemeldeten Geräte wertlos — sie haben den alten
							öffentlichen Schlüssel gespeichert und müssen sich erneut anmelden. Nur nötig,
							wenn der private Schlüssel in falsche Hände geraten sein könnte.
						</p>
						<form method="post" action="<?= e( url( '/settings' ) ) ?>"
							data-confirm="Neues Schlüsselpaar erzeugen? Alle angemeldeten Geräte bekommen danach keine Meldungen mehr, bis sie sich neu anmelden.">
							<?= csrf_field() ?>
							<input type="hidden" name="section" value="push_keys">
							<input type="hidden" name="rotate" value="1">
							<button class="btn danger">Schlüsselpaar erneuern</button>
						</form>
					</div>
				</div>
			<?php endif; ?>
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

			<h3 class="mt">Zugriffstoken fürs Kundenportal</h3>
			<?php $apiToken = $get( 'api_token' ); ?>
			<p class="small muted">
				Erlaubt es einem eigenen System, den Gesamtstand abzurufen — Grundlage für Berichte
				ausserhalb des Panels. Nur lesend.
			</p>
			<?php if ( '' !== $apiToken ) : ?>
				<div class="copy-row" style="max-width:520px">
					<input type="text" id="api-token-setting" readonly class="mono" value="<?= e( $apiToken ) ?>">
					<button class="btn sm" type="button" data-copy="#api-token-setting">Kopieren</button>
				</div>
				<div class="btn-row mt">
					<form method="post" action="<?= e( url( '/settings' ) ) ?>" data-confirm="Neues Token erzeugen? Das alte wird sofort ungültig.">
						<?= csrf_field() ?>
						<input type="hidden" name="section" value="api_token">
						<button class="btn sm">Neu erzeugen</button>
					</form>
					<form method="post" action="<?= e( url( '/settings' ) ) ?>" data-confirm="Zugriff wirklich entziehen?">
						<?= csrf_field() ?>
						<input type="hidden" name="section" value="api_token">
						<input type="hidden" name="disable" value="1">
						<button class="btn sm danger">Entfernen</button>
					</form>
				</div>
			<?php else : ?>
				<form method="post" action="<?= e( url( '/settings' ) ) ?>">
					<?= csrf_field() ?>
					<input type="hidden" name="section" value="api_token">
					<button class="btn primary">Token erzeugen</button>
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

<script src="<?= e( nl_asset( '/assets/js/push.js' ) ) ?>" defer></script>
<script src="<?= e( nl_asset( '/assets/js/icon.js' ) ) ?>" defer></script>
