<?php
/**
 * NorthLab Control Panel — Konfiguration.
 *
 * Diese Datei wird vom Installer erzeugt. Wer lieber von Hand konfiguriert,
 * kopiert sie nach config.php und trägt die Werte ein.
 */

return array(

	'app' => array(
		'name'     => 'NorthLab',
		// Öffentliche Basis-URL ohne abschließenden Slash, z. B. https://panel.northlab.de
		'url'      => '',
		'timezone' => 'Europe/Berlin',
		'locale'   => 'de',
		'debug'    => false,
		// 32 zufällige Bytes, base64-kodiert. Verschlüsselt private Schlüssel in der Datenbank.
		// ACHTUNG: Ändern macht alle bestehenden Seitenverbindungen unbrauchbar.
		'key'      => '',
	),

	'db' => array(
		'host'    => 'localhost',
		'port'    => 3306,
		'name'    => '',
		'user'    => '',
		'pass'    => '',
		'prefix'  => 'nl_',
		'charset' => 'utf8mb4',
	),

	'mail' => array(
		// 'mail' nutzt die PHP-Funktion mail(), 'smtp' einen externen Server.
		'transport'  => 'mail',
		'from_name'  => 'NorthLab',
		'from_email' => '',
		'smtp'       => array(
			'host'       => '',
			'port'       => 587,
			'encryption' => 'tls', // tls | ssl | none
			'user'       => '',
			'pass'       => '',
		),
	),

	'http' => array(
		// Timeouts in Sekunden für die Kommunikation mit den Kundenseiten.
		'timeout'        => 60,
		'update_timeout' => 300,
		'verify_ssl'     => true,
	),

	'security' => array(
		// Fehlversuche bis zur Sperre, Sperrdauer in Sekunden.
		'login_attempts' => 5,
		'lockout_time'   => 900,
		'session_name'   => 'northlab_session',
		'session_ttl'    => 43200,
	),
);
