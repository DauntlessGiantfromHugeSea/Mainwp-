<?php
/**
 * Spielt wp-login.php nach — je Pfad einer der Faelle, die das Panel von
 * aussen auseinanderhalten koennen muss, wenn die Ein-Klick-Anmeldung nicht
 * greift.
 *
 *   php -S 127.0.0.1:8732 login-router.php
 */
declare( strict_types = 1 );

$pfad = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

switch ( $pfad ) {
	// So sieht es aus, wenn es funktioniert: Cookie gesetzt, ab ins Backend.
	case '/erfolg':
		header( 'Set-Cookie: wordpress_logged_in_abc123=chef%7C123; path=/; httponly' );
		header( 'Location: https://kunde.de/wp-admin/', true, 302 );
		break;

	// Ein Plugin versteckt die Anmeldeseite.
	case '/versteckt':
		http_response_code( 404 );
		echo 'Nicht gefunden';
		break;

	case '/gesperrt':
		http_response_code( 403 );
		echo 'Verboten';
		break;

	// Ein Cache liefert die Startseite aus, bevor PHP den Token sieht.
	case '/cache':
		header( 'Location: https://kunde.de/', true, 302 );
		break;

	// Der Token wurde nicht eingeloest — es kommt das Formular.
	case '/formular':
		echo '<!doctype html><html><body><form name="loginform" id="loginform">'
			. '<input name="log"><input name="pwd"></form></body></html>';
		break;

	// Weiterleitung auf einen fremden Wirt.
	case '/fremd':
		header( 'Location: https://angreifer.example/anmelden', true, 302 );
		break;

	default:
		http_response_code( 200 );
		echo 'ok';
}
