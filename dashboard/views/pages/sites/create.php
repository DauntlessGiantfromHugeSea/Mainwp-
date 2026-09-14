<?php
/**
 * @var array<int,array<string,mixed>> $clients
 * @var string                         $pluginVersion
 * @var array<string,mixed>            $old
 */

use NorthLab\Core\View;

View::set( 'pageTitle', 'Seite hinzufügen' );
?>

<div class="grid side">
	<div class="card">
		<div class="card-head"><h2>Verbindungsdaten</h2></div>
		<div class="card-body">
			<form method="post" action="<?= e( url( '/sites' ) ) ?>" data-site-form>
				<?= csrf_field() ?>

				<div class="type-choice">
					<label class="type-option">
						<input type="radio" name="site_type" value="wordpress" data-site-type
							<?= 'monitor' === nl_old( $old, 'site_type' ) ? '' : 'checked' ?>>
						<span>
							<strong>WordPress</strong>
							<span class="muted small">Child-Plugin, Updates, Backups, Wartung, Sicherheit</span>
						</span>
					</label>
					<label class="type-option">
						<input type="radio" name="site_type" value="monitor" data-site-type
							<?= 'monitor' === nl_old( $old, 'site_type' ) ? 'checked' : '' ?>>
						<span>
							<strong>Nur Überwachung</strong>
							<span class="muted small">Shopify, Baukasten &amp; Co. — Uptime, Kunde, Berichte</span>
						</span>
					</label>
				</div>

				<div class="form-grid">
					<div class="field full">
						<label for="url">URL der Seite</label>
						<input type="text" id="url" name="url" value="<?= e( nl_old( $old, 'url' ) ) ?>"
							placeholder="https://kundenseite.de" required autofocus>
						<div class="hint" data-when="wordpress">Die Startseite der Installation, ohne <code>/wp-admin</code>.</div>
						<div class="hint" data-when="monitor" hidden>Die öffentlich erreichbare Startseite, z.&nbsp;B. <code>https://shop.kundenseite.de</code>.</div>
					</div>

					<div class="field full" data-when="wordpress">
						<label for="connect_code">Verbindungscode</label>
						<!-- data-required statt required: app.js setzt die Pflicht je nach Typ.
						     Bliebe sie fest im Markup, blockierte ein verstecktes Pflichtfeld
						     das Absenden, sobald das Skript fehlt oder veraltet im Cache liegt. -->
						<input type="text" id="connect_code" name="connect_code" class="mono" data-required="1"
							placeholder="A1B2C3…" autocomplete="off">
						<div class="hint">
							Steht auf der Kundenseite unter <strong>Einstellungen → NorthLab</strong>, nachdem dort
							„Verbindungscode erzeugen“ geklickt wurde. Gültig für 60 Minuten.
						</div>
					</div>

					<div class="field full" data-when="monitor" hidden>
						<div class="notice">
							Ohne Child-Plugin prüft das Panel im Minutentakt nur, ob die Seite antwortet.
							<strong>Nicht möglich:</strong> Updates einspielen, Plugin- und Theme-Übersicht,
							Sicherheitsprüfung, Wartung und Backups. Uptime, Kundenzuordnung, Berichte und
							Webhooks laufen normal.
						</div>
					</div>

					<div class="field">
						<label for="name">Anzeigename</label>
						<input type="text" id="name" name="name" value="<?= e( nl_old( $old, 'name' ) ) ?>" placeholder="wird aus der URL übernommen">
					</div>

					<div class="field">
						<label for="client_id">Kunde</label>
						<select id="client_id" name="client_id">
							<option value="">— keiner —</option>
							<?php foreach ( $clients as $client ) : ?>
								<option value="<?= e( (string) $client['id'] ) ?>"
									<?= (string) $client['id'] === nl_old( $old, 'client_id' ) ? 'selected' : '' ?>>
									<?= e( $client['name'] ) ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="field">
						<label for="tags">Tags</label>
						<input type="text" id="tags" name="tags" value="<?= e( nl_old( $old, 'tags' ) ) ?>" placeholder="shop, wartungsvertrag">
						<div class="hint">Komma-getrennt.</div>
					</div>

					<div class="field" data-when="wordpress">
						<label for="auto_update_policy">Automatische Updates</label>
						<select id="auto_update_policy" name="auto_update_policy">
							<option value="inherit">Globale Einstellung übernehmen</option>
							<option value="off">Aus</option>
							<option value="minor">Nur Minor- und Patch-Versionen</option>
							<option value="all">Alle Updates</option>
						</select>
					</div>

					<div class="field full">
						<label for="notes">Notizen</label>
						<textarea id="notes" name="notes" placeholder="Hosting, Ansprechpartner, Besonderheiten…"><?= e( nl_old( $old, 'notes' ) ) ?></textarea>
					</div>
				</div>

				<details style="margin-bottom:16px" data-when="wordpress">
					<summary class="small muted" style="cursor:pointer;padding:6px 0">Erweiterte Optionen</summary>
					<div class="form-grid" style="margin-top:12px">
						<div class="field full inline">
							<input type="checkbox" id="verify_ssl" name="verify_ssl" value="1" checked>
							<label for="verify_ssl">TLS-Zertifikat prüfen <span class="muted">(nur für Testumgebungen abschalten)</span></label>
						</div>
						<div class="field">
							<label for="http_user">HTTP-Basic-Benutzer</label>
							<input type="text" id="http_user" name="http_user" autocomplete="off">
							<div class="hint">Nur nötig, wenn die Seite zusätzlich per .htpasswd geschützt ist.</div>
						</div>
						<div class="field">
							<label for="http_pass">HTTP-Basic-Passwort</label>
							<input type="password" id="http_pass" name="http_pass" autocomplete="new-password">
						</div>
					</div>
				</details>

				<div class="btn-row">
					<button class="btn primary" type="submit" data-busy="Verbinde…" data-label-monitor="Seite aufnehmen"
						data-label-wordpress="Verbinden">Verbinden</button>
					<a class="btn" href="<?= e( url( '/sites' ) ) ?>">Abbrechen</a>
				</div>
			</form>
		</div>
	</div>

	<div class="card" data-when="wordpress">
		<div class="card-head"><h3>So funktioniert es</h3></div>
		<div class="card-body small">
			<p><strong>1.</strong> Lade das Child-Plugin herunter (Version <?= e( $pluginVersion ) ?>).</p>
			<p><strong>2.</strong> Installiere und aktiviere es auf der Kundenseite wie jedes andere WordPress-Plugin.</p>
			<p><strong>3.</strong> Öffne dort <em>Einstellungen → NorthLab</em> und klicke auf <em>Verbindungscode erzeugen</em>.</p>
			<p><strong>4.</strong> Trage URL und Code hier ein.</p>

			<p class="muted">
				Das Panel erzeugt beim Verbinden ein eigenes RSA-Schlüsselpaar pro Seite. Der private Schlüssel
				bleibt verschlüsselt hier, die Kundenseite kennt nur den öffentlichen Teil und akzeptiert
				ausschließlich signierte Anfragen.
			</p>

			<a class="btn primary" href="<?= e( url( '/download/child-plugin' ) ) ?>">Child-Plugin herunterladen</a>
		</div>
	</div>

	<div class="card" data-when="monitor" hidden>
		<div class="card-head"><h3>Seiten ohne WordPress</h3></div>
		<div class="card-body small">
			<p>
				Shopify, Wix, Squarespace, Webflow oder eine fremdgehostete Installation ohne Plugin-Zugriff:
				solche Seiten nimmt das Panel als reine Überwachung auf. Es braucht nur die URL.
			</p>
			<p><strong>Läuft:</strong> Erreichbarkeitsprüfung, Störungsprotokoll, Zuordnung zu einem Kunden,
				Tags, Notizen, Berichte und alle Webhooks.</p>
			<p><strong>Läuft nicht:</strong> Updates, Plugin- und Theme-Listen, Sicherheitswert, Wartung
				und Backups — dafür müsste Code auf der Seite laufen, und den gibt es dort nicht.</p>
			<p class="muted">
				Der Typ lässt sich später nicht umstellen. Soll aus einer überwachten Seite eine
				vollwertige WordPress-Seite werden, entferne sie und verbinde sie neu mit Child-Plugin.
			</p>
		</div>
	</div>
</div>
