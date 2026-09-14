<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Http;
use NorthLab\Core\Setting;

/**
 * Symbol des Panels aus dem eigenen Logo bauen: dunkler Grund, Logo darauf.
 *
 * Zusammengesetzt wird im Browser, nicht hier. Zwei Gründe: die Bildbibliothek
 * GD gehört nicht zu den Voraussetzungen dieser Installation und kann auf einem
 * fremden Server fehlen — und sie kann keine SVG-Logos lesen, was viele Logos
 * heute sind. Ein Browser kann beides.
 *
 * Das Panel nimmt die Quelle entgegen, setzt daraus die SVG-Fassung zusammen
 * (die braucht keinen Zeichner) und verwahrt die vom Browser gelieferten PNGs.
 */
final class IconService {

	/** Grössen, die gebraucht werden: Anwendungssymbol, Apple, Browser-Reiter. */
	public const SIZES = array(
		'icon-512'         => 512,
		'icon-192'         => 192,
		'apple-touch-icon' => 180,
		'favicon-32'       => 32,
	);

	private const MAX_SOURCE_BYTES = 2097152;

	/** Was als Quelle durchgeht — je Medientyp die Dateiendung. */
	private const SOURCE_TYPES = array(
		'image/png'     => 'png',
		'image/jpeg'    => 'jpg',
		'image/webp'    => 'webp',
		'image/gif'     => 'gif',
		'image/svg+xml' => 'svg',
	);

	/**
	 * Wo die eigenen Symbole liegen. Legt nichts an — Nachsehen darf nichts
	 * verändern, sonst kippt ein reiner Lesevorgang wie das Manifest, bloss
	 * weil der Ordner nicht anlegbar ist.
	 */
	public static function directory(): string {
		return NL_STORAGE . '/branding';
	}

	/**
	 * Verzeichnis für einen Schreibvorgang bereitstellen.
	 */
	private static function ensureDirectory(): string {
		$dir = self::directory();

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0750, true );
		}

		return $dir;
	}

	/* ------------------------------------------------------------- Quelle */

	public static function sourcePath(): ?string {
		foreach ( self::SOURCE_TYPES as $extension ) {
			$path = self::directory() . '/source.' . $extension;

			if ( is_file( $path ) ) {
				return $path;
			}
		}

		return null;
	}

	public static function hasSource(): bool {
		return null !== self::sourcePath();
	}

	public static function sourceMime(): string {
		$path = self::sourcePath();

		if ( null === $path ) {
			return '';
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		return (string) array_search( $extension, self::SOURCE_TYPES, true );
	}

	/**
	 * Logo von einer Adresse holen.
	 *
	 * @return string|null Fehlermeldung oder null bei Erfolg.
	 */
	public static function fetchSource( string $url ): ?string {
		$url = trim( $url );

		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return 'Bitte eine vollständige http- oder https-Adresse angeben.';
		}

		$response = Http::get( $url, array( 'Accept' => 'image/*' ), 25 );

		if ( '' !== $response['error'] ) {
			return 'Das Logo konnte nicht geladen werden: ' . $response['error'];
		}
		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			return sprintf( 'Die Adresse antwortete mit HTTP %d.', $response['status'] );
		}

		$type = strtolower( trim( explode( ';', (string) ( $response['headers']['content-type'] ?? '' ) )[0] ) );

		return self::store( (string) $response['body'], $type );
	}

	/**
	 * Logo aus einem Datei-Upload übernehmen.
	 *
	 * @param array<string,mixed> $file Eintrag aus $_FILES.
	 * @return string|null Fehlermeldung oder null bei Erfolg.
	 */
	public static function uploadSource( array $file ): ?string {
		if ( ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			return 'Es wurde keine Datei übertragen.';
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return 'Die hochgeladene Datei ist nicht auffindbar.';
		}

		// Dem gemeldeten Typ nicht trauen — er kommt vom Browser.
		return self::store( (string) file_get_contents( $tmp ), self::sniff( $tmp ) );
	}

	/**
	 * @return string|null Fehlermeldung oder null bei Erfolg.
	 */
	private static function store( string $bytes, string $type ): ?string {
		if ( '' === $bytes ) {
			return 'Die Datei ist leer.';
		}
		if ( strlen( $bytes ) > self::MAX_SOURCE_BYTES ) {
			return 'Das Logo ist grösser als 2 MB.';
		}
		if ( ! isset( self::SOURCE_TYPES[ $type ] ) ) {
			return 'Dieses Format wird nicht unterstützt. Erlaubt sind PNG, JPEG, WebP, GIF und SVG.';
		}

		// Ein SVG kann Skripte enthalten. Es wird zwar nur in <img> und als
		// Hintergrund benutzt, wo nichts davon laeuft — aber ausgeliefert wird
		// es trotzdem, also raus damit.
		if ( 'image/svg+xml' === $type ) {
			$bytes = self::cleanSvg( $bytes );

			if ( '' === $bytes ) {
				return 'Diese SVG-Datei enthält Bestandteile, die nicht ausgeliefert werden.';
			}
		}

		self::clearSource();

		// Die erzeugten Fassungen zeigen noch das alte Logo. Lieber das
		// mitgelieferte Zeichen als ein falsches — bis der Browser die neuen
		// Fassungen geliefert hat.
		self::clearRasters();

		file_put_contents( self::ensureDirectory() . '/source.' . self::SOURCE_TYPES[ $type ], $bytes, LOCK_EX );
		self::touch();

		return null;
	}

	/**
	 * Skripte, Ereignisse und externe Verweise aus einem SVG entfernen.
	 */
	public static function cleanSvg( string $svg ): string {
		if ( ! str_contains( strtolower( $svg ), '<svg' ) ) {
			return '';
		}

		$svg = (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $svg );
		$svg = (string) preg_replace( '#<foreignObject\b[^>]*>.*?</foreignObject>#is', '', $svg );
		$svg = (string) preg_replace( '#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $svg );
		$svg = (string) preg_replace( '#(href|xlink:href)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2#i', '', $svg );

		return trim( $svg );
	}

	public static function clearSource(): void {
		foreach ( self::SOURCE_TYPES as $extension ) {
			$path = self::directory() . '/source.' . $extension;

			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	/**
	 * Alles wieder auf das mitgelieferte Symbol zurücksetzen.
	 */
	public static function reset(): void {
		self::clearSource();
		self::clearRasters();
		self::touch();
	}

	/**
	 * Die erzeugten PNG-Fassungen entfernen.
	 */
	private static function clearRasters(): void {
		foreach ( array_keys( self::SIZES ) as $name ) {
			$path = self::directory() . '/' . $name . '.png';

			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	/* ------------------------------------------------------- Zusammenbau */

	public static function background(): string {
		$value = strtolower( trim( (string) Setting::get( 'icon_bg', '#000000' ) ) );

		return preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : '#000000';
	}

	/**
	 * Luft zwischen Logo und Rand, in Prozent der Kantenlänge.
	 */
	public static function padding(): int {
		return max( 0, min( 40, (int) Setting::get( 'icon_padding', '18' ) ) );
	}

	/**
	 * SVG-Fassung — die braucht keinen Zeichner und gilt im Browser-Reiter.
	 */
	public static function svg(): string {
		$background = self::background();
		$path       = self::sourcePath();

		if ( null === $path ) {
			return (string) file_get_contents( NL_ROOT . '/public/assets/icons/mark.svg' );
		}

		$padding = self::padding();
		$inner   = 512 - ( 2 * 512 * $padding / 100 );
		$offset  = ( 512 - $inner ) / 2;

		// Das Logo wandert als Daten-URI hinein: so ist die Datei in sich
		// geschlossen und laedt nichts nach, was ein Browser blockieren koennte.
		$embedded = 'data:' . self::sourceMime() . ';base64,' . base64_encode( (string) file_get_contents( $path ) );

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">'
			. '<rect width="512" height="512" rx="112" fill="' . htmlspecialchars( $background, ENT_QUOTES ) . '"/>'
			. '<image x="' . round( $offset, 2 ) . '" y="' . round( $offset, 2 ) . '"'
			. ' width="' . round( $inner, 2 ) . '" height="' . round( $inner, 2 ) . '"'
			. ' preserveAspectRatio="xMidYMid meet"'
			. ' href="' . htmlspecialchars( $embedded, ENT_QUOTES ) . '"/>'
			. '</svg>';
	}

	/**
	 * Eine vom Browser gelieferte Fassung verwahren.
	 *
	 * @return string|null Fehlermeldung oder null bei Erfolg.
	 */
	public static function saveRaster( string $name, string $dataUrl ): ?string {
		if ( ! isset( self::SIZES[ $name ] ) ) {
			return 'Unbekannte Grösse.';
		}

		if ( ! preg_match( '#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim( $dataUrl ), $match ) ) {
			return 'Erwartet wird ein PNG als Daten-URI.';
		}

		$bytes = base64_decode( $match[1], true );

		if ( false === $bytes || strlen( $bytes ) > self::MAX_SOURCE_BYTES ) {
			return 'Die erzeugte Datei ist unbrauchbar oder zu gross.';
		}

		// Nur echte PNG-Dateien: die ersten acht Bytes sind festgelegt.
		if ( "\x89PNG\r\n\x1a\n" !== substr( $bytes, 0, 8 ) ) {
			return 'Die erzeugte Datei ist kein PNG.';
		}

		file_put_contents( self::ensureDirectory() . '/' . $name . '.png', $bytes, LOCK_EX );

		return null;
	}

	/* --------------------------------------------------------- Ausliefern */

	/**
	 * Pfad der gewünschten Grösse — eigenes Symbol, sonst das mitgelieferte.
	 */
	public static function rasterPath( string $name ): ?string {
		if ( ! isset( self::SIZES[ $name ] ) ) {
			return null;
		}

		$own = self::directory() . '/' . $name . '.png';

		if ( is_file( $own ) ) {
			return $own;
		}

		$shipped = array(
			'icon-512'         => '/assets/icons/icon-512.png',
			'icon-192'         => '/assets/icons/icon-192.png',
			'apple-touch-icon' => '/assets/icons/apple-touch-icon.png',
			'favicon-32'       => '/assets/icons/icon-192.png',
		);

		$path = NL_ROOT . '/public' . $shipped[ $name ];

		return is_file( $path ) ? $path : null;
	}

	public static function hasOwnRaster(): bool {
		foreach ( array_keys( self::SIZES ) as $name ) {
			if ( is_file( self::directory() . '/' . $name . '.png' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Stempel für die Adressen — sonst zeigt der Browser tagelang das alte Symbol.
	 */
	public static function stamp(): string {
		return (string) Setting::get( 'icon_updated_at', '0' );
	}

	public static function touch(): void {
		Setting::set( 'icon_updated_at', (string) time() );
	}

	/**
	 * Medientyp anhand des Inhalts, nicht anhand der Endung.
	 */
	private static function sniff( string $path ): string {
		$head = (string) file_get_contents( $path, false, null, 0, 1024 );

		if ( "\x89PNG\r\n\x1a\n" === substr( $head, 0, 8 ) ) {
			return 'image/png';
		}
		if ( "\xff\xd8\xff" === substr( $head, 0, 3 ) ) {
			return 'image/jpeg';
		}
		if ( 'GIF8' === substr( $head, 0, 4 ) ) {
			return 'image/gif';
		}
		if ( 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			return 'image/webp';
		}
		if ( str_contains( strtolower( $head ), '<svg' ) ) {
			return 'image/svg+xml';
		}

		return '';
	}
}
