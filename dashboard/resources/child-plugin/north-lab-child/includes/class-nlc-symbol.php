<?php
defined( 'ABSPATH' ) || exit;

/**
 * Das Zeichen der Agentur im Menü dieser Website.
 *
 * Bewusst eigenständig und nicht Teil des Brandings: das Branding ist etwas,
 * das man je Kunde ein- und ausschaltet. Das Menüsymbol dagegen gehört zum
 * Plugin selbst — es steht überall, wo das Plugin läuft, und hat mit
 * Support-Leisten und Anmeldelogos nichts zu tun. Früher hing es am
 * Branding und kam deshalb nur dort an, wo jemand Branding angewandt hatte.
 */
class NLC_Symbol {

	const OPTION = 'nlc_menu_symbol';

	/**
	 * Setzen. Leer heisst: zurück auf das mitgelieferte Schild.
	 *
	 * @param string $glyph
	 * @return string Was wirklich gespeichert wurde.
	 */
	public static function set( $glyph ) {
		$sauber = self::sanitize( $glyph );

		if ( '' === $sauber ) {
			delete_option( self::OPTION );

			return '';
		}

		update_option( self::OPTION, $sauber, false );

		return $sauber;
	}

	/**
	 * @return string
	 */
	public static function get() {
		$wert = get_option( self::OPTION, '' );

		return is_string( $wert ) ? $wert : '';
	}

	public static function has() {
		return '' !== self::get();
	}

	/**
	 * Eine Daten-URI und sonst nichts.
	 *
	 * Der Wert kommt vom Dashboard und landet in einem CSS-url(). Benutzt
	 * wird er als Maske — ein Bild als Maske wird nie ausgeführt, egal was
	 * darin steht. Gefährlich wäre nur, hier etwas anderes durchzulassen als
	 * eine Daten-URI: eine fremde Adresse etwa, die bei jedem
	 * Backend-Aufruf nach Hause telefoniert.
	 *
	 * Deshalb: fester Anfang, nur Base64-Zeichen dahinter, Länge gedeckelt,
	 * und es muss sich wirklich dekodieren lassen.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function sanitize( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( strlen( $value ) > 16384 ) {
			return '';
		}

		if ( ! preg_match( '#^data:image/(svg\+xml|png|webp|gif|jpeg);base64,([A-Za-z0-9+/]+={0,2})$#', $value, $m ) ) {
			return '';
		}

		return false === base64_decode( $m[2], true ) ? '' : $value;
	}
}
