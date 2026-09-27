<?php
/** Nachgebauter Push-Dienst: merkt sich die letzte Anfrage, antwortet nach Pfad. */
$store = sys_get_temp_dir() . '/nlc-push-last.json';
$path  = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

if ( '/last' === $path ) {
	header( 'Content-Type: application/json' );
	readfile( $store );
	exit;
}

if ( preg_match( '#^/push/(\d+)$#', $path, $m ) ) {
	$body    = (string) file_get_contents( 'php://input' );
	$headers = array();
	foreach ( $_SERVER as $key => $value ) {
		if ( str_starts_with( $key, 'HTTP_' ) ) {
			$headers[ strtolower( str_replace( '_', '-', substr( $key, 5 ) ) ) ] = $value;
		}
	}
	$headers['content-type']   = $_SERVER['CONTENT_TYPE'] ?? '';
	$headers['content-length'] = $_SERVER['CONTENT_LENGTH'] ?? '';

	file_put_contents(
		$store,
		json_encode( array( 'headers' => $headers, 'length' => strlen( $body ), 'body' => base64_encode( $body ) ) )
	);

	http_response_code( (int) $m[1] );
	exit;
}

http_response_code( 404 );
