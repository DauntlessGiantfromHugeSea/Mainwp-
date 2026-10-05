<?php
/**
 * Stellt die WPScan-API nach — so viel davon, wie das Panel braucht.
 *
 *   php -S 127.0.0.1:8096 wpscan-router.php
 *
 * Zaehlt die Abrufe mit, damit sich pruefen laesst, dass der
 * Zwischenspeicher wirklich greift.
 */
declare( strict_types = 1 );

$zaehler = sys_get_temp_dir() . '/nl-wpscan-abrufe';
$pfad    = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
$slug    = basename( $pfad );

// Die Kopfzeile muss stimmen — sonst merkt niemand, wenn sie verlorengeht.
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if ( 0 !== strpos( $auth, 'Token token=' ) || 'Token token=' === $auth ) {
	http_response_code( 401 );
	header( 'Content-Type: application/json' );
	echo json_encode( array( 'error' => 'no token' ) );
	return;
}

if ( '__abrufe' === $slug ) {
	header( 'Content-Type: text/plain' );
	echo is_file( $zaehler ) ? (string) file_get_contents( $zaehler ) : '0';
	return;
}

if ( '__reset' === $slug ) {
	file_put_contents( $zaehler, '0' );
	echo 'ok';
	return;
}

$bisher = is_file( $zaehler ) ? (int) file_get_contents( $zaehler ) : 0;
file_put_contents( $zaehler, (string) ( $bisher + 1 ) );

header( 'Content-Type: application/json' );

switch ( $slug ) {
	case 'loechrig':
		echo json_encode(
			array(
				'loechrig' => array(
					'latest_version'  => '3.0.0',
					'vulnerabilities' => array(
						array(
							'id'       => 'aaaa-1111',
							'title'    => 'Authenticated SQL Injection',
							'fixed_in' => '2.5.0',
							'cvss'     => array( 'score' => '8.8', 'severity' => 'high' ),
						),
						array(
							'id'       => 'bbbb-2222',
							'title'    => 'Ungepatchte Lücke ohne Fassung',
							'fixed_in' => null,
							'cvss'     => array( 'severity' => 'critical' ),
						),
					),
				),
			)
		);
		break;

	case 'sauber':
		echo json_encode( array( 'sauber' => array( 'latest_version' => '1.2.0', 'vulnerabilities' => array() ) ) );
		break;

	// Eine flache Antwort - falls die Form sich einmal aendert.
	case 'flach':
		echo json_encode(
			array( 'vulnerabilities' => array( array( 'id' => 'cccc', 'title' => 'Flach', 'fixed_in' => '9.9.9' ) ) )
		);
		break;

	case 'unbekannt':
		http_response_code( 404 );
		echo json_encode( array( 'error' => 'Not found' ) );
		break;

	case 'zuviel':
		http_response_code( 429 );
		echo json_encode( array( 'error' => 'Too many requests' ) );
		break;

	default:
		echo json_encode( array( $slug => array( 'vulnerabilities' => array() ) ) );
}
