<?php
/**
 * Spielt eine Kundenseite nach — je Pfad ein Fall, den das Panel von aussen
 * auseinanderhalten koennen muss.
 *
 *   php -S 127.0.0.1:8097 site-router.php
 */
declare( strict_types = 1 );

$pfad = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

$wartungsseite = "<!doctype html>\n<html><head><meta charset=\"utf-8\">"
	. "<meta name=\"robots\" content=\"noindex, nofollow\">\n"
	. "<meta name=\"nlc-maintenance\" content=\"1\">\n"
	. "<title>Wartungsmodus</title></head><body><h1>Wartungsmodus</h1></body></html>";

$normal = "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>Kundenseite</title></head>"
	. "<body><h1>Willkommen</h1></body></html>";

switch ( $pfad ) {
	case '/wartung':
		http_response_code( 503 );
		header( 'Retry-After: 3600' );
		echo $wartungsseite;
		break;

	// Wenn die Kopfzeilen schon raus waren, kommt die Wartungsseite mit 200.
	// Der Marker im Text muss sie trotzdem erkennbar machen.
	case '/wartung-200':
		echo $wartungsseite;
		break;

	case '/cf-hit':
		header( 'cf-cache-status: HIT' );
		echo $normal;
		break;

	case '/cf-miss':
		header( 'cf-cache-status: MISS' );
		echo $normal;
		break;

	case '/cf-hit-wartung':
		// Der Cache liefert die Wartungsseite aus, obwohl sie abgeschaltet ist.
		header( 'cf-cache-status: HIT' );
		http_response_code( 503 );
		echo $wartungsseite;
		break;

	case '/age-alt':
		header( 'Age: 120' );
		echo $normal;
		break;

	case '/age-null':
		header( 'Age: 0' );
		echo $normal;
		break;

	case '/xcache':
		header( 'X-Cache: HIT from edge-fra1' );
		echo $normal;
		break;

	// Das Wort steht im Text, der Marker fehlt. Darf nicht als Wartungsseite
	// durchgehen - sonst meldete jede Seite mit dem Wort "maintenance" Erfolg.
	case '/wortfalle':
		echo "<!doctype html><html><head><title>Wartung &amp; Service</title></head>"
			. "<body><h1>Maintenance und Reparatur</h1><p>nlc-maintenance</p></body></html>";
		break;

	default:
		echo $normal;
}
