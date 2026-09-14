<?php
/**
 * Wird angezeigt, wenn ein Seitenaufruf ohne Netz erfolgt.
 *
 * Bewusst ohne alte Daten: fertige Seiten hängen an der Anmeldung und werden
 * nicht zwischengespeichert. Lieber ehrlich nichts zeigen als etwas Veraltetes.
 *
 * @var string $agencyName
 */

use NorthLab\Core\View;

View::set( 'pageTitle', 'Keine Verbindung' );
?>

<img class="bare-mark" src="<?= e( nl_asset( '/assets/icons/icon-192.png' ) ) ?>" alt="" width="64" height="64">

<h1>Keine Verbindung</h1>

<p>
	Das Panel ist gerade nicht erreichbar. Sobald das Gerät wieder online ist,
	geht es normal weiter.
</p>

<p class="muted small">
	Es werden bewusst keine alten Stände angezeigt: Was du hier siehst, hängt an
	deiner Anmeldung, und ein veralteter Überblick wäre schlimmer als gar keiner.
</p>

<button class="btn primary" type="button" onclick="location.reload()">Erneut versuchen</button>
