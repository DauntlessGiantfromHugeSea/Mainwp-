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
			<form method="post" action="<?= e( url( '/sites' ) ) ?>">
				<?= csrf_field() ?>

				<div class="form-grid">
					<div class="field full">
						<label for="url">URL der WordPress-Seite</label>
						<input type="text" id="url" name="url" value="<?= e( nl_old( $old, 'url' ) ) ?>"
							placeholder="https://kundenseite.de" required autofocus>
						<div class="hint">Die Startseite der Installation, ohne <code>/wp-admin</code>.</div>
					</div>

					<div class="field full">
						<label for="connect_code">Verbindungscode</label>
						<input type="text" id="connect_code" name="connect_code" class="mono" required
							placeholder="A1B2C3…" autocomplete="off">
						<div class="hint">
							Steht auf der Kundenseite unter <strong>Einstellungen → NorthLab</strong>, nachdem dort
							„Verbindungscode erzeugen“ geklickt wurde. Gültig für 60 Minuten.
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

					<div class="field">
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

				<details style="margin-bottom:16px">
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
					<button class="btn primary" type="submit" data-busy="Verbinde…">Verbinden</button>
					<a class="btn" href="<?= e( url( '/sites' ) ) ?>">Abbrechen</a>
				</div>
			</form>
		</div>
	</div>

	<div class="card">
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
</div>
