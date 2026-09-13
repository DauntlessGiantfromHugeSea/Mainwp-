<?php
/** @var string $appUrl */
/** @var string $agencyName */

use NorthLab\Core\View;

View::set( 'pageTitle', 'Installation abgeschlossen' );
View::set( 'wide', true );
?>
<h1>Fertig</h1>
<p class="sub"><?= e( $agencyName ) ?> Control Panel ist einsatzbereit</p>

<div class="flash success">Die Datenbank wurde angelegt und dein Administratorkonto erstellt.</div>

<h2>Noch zwei Schritte</h2>

<p><strong>1. Zeitplaner einrichten.</strong> Ohne ihn laufen keine Syncs, Uptime-Prüfungen, Webhooks und Berichte.
Trage diesen Eintrag in die Crontab des Servers ein:</p>

<pre class="code-block">* * * * * /usr/bin/php <?= e( NL_ROOT ) ?>/bin/cron.php &gt;&gt; <?= e( NL_ROOT ) ?>/storage/logs/cron.log 2&gt;&amp;1</pre>

<p class="small muted">Ohne Cron-Zugang gibt es unter Einstellungen einen HTTP-Auslöser, den ein externer Dienst minütlich abrufen kann.</p>

<p class="mt"><strong>2. config.php absichern.</strong> Die Datei enthält Datenbankzugang und Verschlüsselungsschlüssel.
Entziehe dem Webserver die Schreibrechte, sobald alles läuft:</p>

<pre class="code-block">chmod 640 <?= e( NL_CONFIG_FILE ) ?></pre>

<div class="notice warn mt">
	<strong>Wichtig:</strong> Der Wert <code>app.key</code> in der config.php verschlüsselt die privaten Schlüssel
	aller Seitenverbindungen. Sichere ihn zusammen mit der Datenbank — geht er verloren, müssen alle Kundenseiten neu verbunden werden.
</div>

<a class="btn primary mt" href="<?= e( $appUrl . '/login' ) ?>" style="width:100%">Zur Anmeldung</a>
