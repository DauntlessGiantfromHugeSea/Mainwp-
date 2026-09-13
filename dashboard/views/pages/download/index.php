<?php
/**
 * @var string $pluginVersion
 * @var string $pluginFile
 * @var string $panelUrl
 * @var string $creator
 */

use NorthLab\Core\Auth;
use NorthLab\Core\View;

View::set( 'pageTitle', 'Plugin-Download' );
View::set(
	'headerActions',
	'<a class="btn primary" href="' . e( url( '/download/child-plugin' ) ) . '">ZIP herunterladen</a>'
);
?>

<div class="grid side">
	<div>
		<div class="card">
			<div class="card-head"><h2>NorthLab Child</h2></div>
			<div class="card-body">
				<p>
					Das Verbindungs-Plugin für die Kundenseiten. Es wird wie jedes andere WordPress-Plugin
					installiert und stellt die signierte Schnittstelle bereit, über die dieses Panel
					Updates einspielt, den Status abfragt, Wartung ausführt und Sicherheitsprüfungen anstößt.
				</p>

				<dl class="meta">
					<dt>Plugin</dt><dd><strong>NorthLab Child</strong></dd>
					<dt>Ersteller</dt><dd><strong><?= e( $creator ) ?></strong></dd>
					<dt>Version</dt><dd class="mono"><?= e( $pluginVersion ) ?></dd>
					<dt>Datei</dt><dd class="mono"><?= e( $pluginFile ) ?></dd>
					<dt>Voraussetzungen</dt><dd>WordPress 6.0+, PHP 7.4+, OpenSSL</dd>
					<dt>Lizenz</dt><dd>GPL-3.0-or-later</dd>
				</dl>

				<div class="btn-row mt">
					<a class="btn primary" href="<?= e( url( '/download/child-plugin' ) ) ?>">
						<?= e( $pluginFile ) ?> herunterladen
					</a>
					<?php if ( Auth::canWrite() ) : ?>
						<a class="btn" href="<?= e( url( '/download/child-plugin?rebuild=1' ) ) ?>">Paket neu bauen</a>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="card">
			<div class="card-head"><h2>Installation auf der Kundenseite</h2></div>
			<div class="card-body">
				<p><strong>1. Plugin hochladen.</strong> Im WordPress-Backend der Kundenseite unter
					<em>Plugins → Installieren → Plugin hochladen</em> die ZIP-Datei auswählen und aktivieren.</p>

				<p><strong>2. Verbindungscode erzeugen.</strong> Dann unter
					<em>Einstellungen → NorthLab</em> auf <em>Verbindungscode erzeugen</em> klicken.
					Der Code wird nur einmal angezeigt und ist 60 Minuten gültig.</p>

				<p><strong>3. Seite hier eintragen.</strong> Unter
					<a href="<?= e( url( '/sites/new' ) ) ?>">Seiten → Seite hinzufügen</a> URL und Code eingeben.
					Das Panel erzeugt dabei ein eigenes RSA-Schlüsselpaar und synchronisiert die Seite sofort.</p>

				<p class="muted small mb0">
					Alternativ per WP-CLI auf dem Kundenserver:
				</p>
				<pre class="code-block">wp plugin install <?= e( $pluginFile ) ?> --activate</pre>
			</div>
		</div>

		<div class="card">
			<div class="card-head"><h2>Berechtigungen auf der Kundenseite</h2></div>
			<div class="card-body">
				<p class="small">
					Nach der Installation lässt sich unter <em>Einstellungen → NorthLab</em> je Seite festlegen,
					was dieses Panel tun darf: Updates einspielen, Plugins und Themes installieren, Benutzer
					verwalten, Wartungsaufgaben ausführen, Inhalte anlegen. Zusätzlich kann dort eine
					IP-Allowlist gesetzt und erzwungen werden, dass nur HTTPS-Anfragen akzeptiert werden.
				</p>
				<p class="small mb0 muted">
					Standardmäßig sind alle Funktionen aktiv und die IP-Allowlist leer.
					Für besonders sensible Installationen empfiehlt sich die IP dieses Servers als einziger Eintrag.
				</p>
			</div>
		</div>
	</div>

	<div>
		<div class="card">
			<div class="card-head"><h3>Wie die Verbindung abgesichert ist</h3></div>
			<div class="card-body small">
				<p>
					Beim Verbinden erzeugt das Panel ein <strong>RSA-2048-Schlüsselpaar pro Seite</strong>.
					Der öffentliche Teil geht an das Child-Plugin, der private bleibt hier — verschlüsselt
					mit dem <code>app.key</code> aus der config.php.
				</p>
				<p>
					Jede Anfrage an eine Kundenseite trägt eine Signatur über Verbindungs-ID, Zeitstempel,
					Nonce, Methode, Route und Body-Hash. Das Child prüft die Signatur, verwirft Anfragen
					älter als fünf Minuten und lehnt bereits gesehene Nonces ab.
				</p>
				<p class="mb0">
					Es gibt keinen gemeinsamen Schlüssel für alle Seiten und kein Passwort, das übertragen wird.
					Wer den Verkehr mitliest, kann daraus keine gültige Anfrage bauen.
				</p>
			</div>
		</div>

		<div class="card">
			<div class="card-head"><h3>Panel-Adresse</h3></div>
			<div class="card-body">
				<p class="small muted">Diese Adresse trägt das Child-Plugin nach dem Verbinden als sein Dashboard.</p>
				<div class="copy-row">
					<input type="text" id="panel-url" readonly class="mono" value="<?= e( $panelUrl ) ?>">
					<button class="btn sm" type="button" data-copy="#panel-url">Kopieren</button>
				</div>
			</div>
		</div>
	</div>
</div>
