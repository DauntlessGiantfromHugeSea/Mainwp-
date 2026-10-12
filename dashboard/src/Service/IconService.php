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

	/**
	 * Wo das mitgelieferte Zeichen liegt — das N mit der Welle.
	 *
	 * Blank, also ohne Grundfläche und in Schwarz: so taugt es als Maske,
	 * wo nur zählt, wo etwas undurchsichtig ist. Das fertige Symbol mit
	 * dunkler Kachel ist mark.svg.
	 */
	public static function shippedPath(): string {
		return NL_ROOT . '/public/assets/icons/logo.svg';
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
	/**
	 * So gross darf der Glyph fuers Menue werden.
	 *
	 * Er haengt als Daten-URI in jeder Backend-Seite der Kundenseite. Ein
	 * Logo, das ein Viertelmegabyte ausmacht, gehoert dort nicht hinein.
	 */
	public const GLYPH_MAX_BYTES = 12288;

	/**
	 * Wo das Zeichen fuers Kundenmenue liegt.
	 *
	 * Bewusst eine eigene Datei und nicht die Quelle des Panel-Symbols:
	 * das Favicon darf ein farbiges PNG mit Hintergrund sein — als
	 * Silhouette taugt genau das nicht. Zwei Zwecke, zwei Dateien.
	 */
	public static function glyphPath(): ?string {
		$pfad = self::directory() . '/glyph.svg';

		if ( is_file( $pfad ) ) {
			return $pfad;
		}

		// Nichts hochgeladen heisst nicht: kein Zeichen. Das mitgelieferte
		// liegt im Panel, und damit steht es auf jeder betreuten Seite —
		// ohne dass jemand dafür etwas einstellen muss.
		$mitgeliefert = self::shippedPath();

		return is_file( $mitgeliefert ) ? $mitgeliefert : null;
	}

	/**
	 * Liegt ein eigenes Zeichen vor — oder gilt das mitgelieferte?
	 *
	 * Die Einstellungsseite muss das auseinanderhalten: nur ein eigenes
	 * lässt sich zurücknehmen.
	 */
	public static function hasOwnGlyph(): bool {
		return is_file( self::directory() . '/glyph.svg' );
	}

	public static function hasGlyph(): bool {
		return null !== self::glyphPath();
	}

	/**
	 * Das Zeichen fuers Kundenmenue hinterlegen. Nur SVG.
	 *
	 * @param array<string,mixed> $file Ein Eintrag aus $_FILES.
	 * @return string|null Fehlermeldung oder null bei Erfolg.
	 */
	public static function uploadGlyph( array $file ): ?string {
		if ( ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			return 'Es wurde keine Datei übertragen.';
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return 'Die hochgeladene Datei ist nicht auffindbar.';
		}

		if ( 'image/svg+xml' !== self::sniff( $tmp ) ) {
			return 'Für das Menü der Kundenseiten wird eine SVG-Datei gebraucht. '
				. 'Ein Pixelbild ergibt dort keine saubere Silhouette.';
		}

		$form = self::silhouette( (string) file_get_contents( $tmp ) );

		if ( '' === $form ) {
			return 'Aus dieser Datei liess sich keine Form gewinnen. '
				. 'Sie braucht eine viewBox und mindestens eine Fläche (Pfad, Kreis, Rechteck …).';
		}

		if ( strlen( $form ) > self::GLYPH_MAX_BYTES ) {
			return sprintf(
				'Die Form ist mit %d KB zu verschachtelt für ein Menüsymbol. Ein einfacheres Zeichen tut es.',
				(int) ceil( strlen( $form ) / 1024 )
			);
		}

		file_put_contents( self::ensureDirectory() . '/glyph.svg', $form, LOCK_EX );
		self::touch();

		return null;
	}

	public static function clearGlyph(): void {
		$pfad = self::directory() . '/glyph.svg';

		if ( is_file( $pfad ) ) {
			unlink( $pfad );
		}

		self::touch();
	}

	/**
	 * Das Zeichen fuers Menue im Backend der Kundenseiten.
	 *
	 * Verwendet wird es dort als CSS-Maske: dabei zaehlt nur, wo etwas
	 * undurchsichtig ist, nicht welche Farbe es hat. Dadurch nimmt das Symbol
	 * von selbst die Farbe des WordPress-Menues an — grau, weiss wenn der
	 * Punkt aktiv ist — statt als Fremdkoerper danebenzustehen. Und weil ein
	 * Bild als Maske nie ausgefuehrt wird, ist das zugleich der sichere Weg,
	 * ein hochgeladenes SVG zu benutzen.
	 *
	 * @return array{ok:bool,data:string,reason:string}
	 */
	public static function menuGlyph(): array {
		$pfad = self::glyphPath();

		if ( null === $pfad ) {
			return array(
				'ok'     => false,
				'data'   => '',
				'reason' => 'Es ist noch kein Zeichen für das Kundenmenü hinterlegt.',
			);
		}

		// Auch das mitgelieferte Zeichen geht denselben Weg: ein Hochladen
		// hat die Form schon beim Verwahren bereinigt, hier kommt dasselbe
		// noch einmal darüber. Das kostet wenig — verschickt wird nur, wenn
		// sich etwas geändert hat — und erspart zwei Fassungen derselben
		// Sache, von denen eine irgendwann abweicht.
		$form = self::silhouette( (string) file_get_contents( $pfad ) );

		if ( '' === $form ) {
			return array( 'ok' => false, 'data' => '', 'reason' => 'Aus dem hinterlegten Zeichen liess sich keine Form gewinnen.' );
		}

		$daten = 'data:image/svg+xml;base64,' . base64_encode( $form );

		if ( strlen( $daten ) > self::GLYPH_MAX_BYTES ) {
			return array( 'ok' => false, 'data' => '', 'reason' => 'Das hinterlegte Zeichen ist zu gross.' );
		}

		return array( 'ok' => true, 'data' => $daten, 'reason' => '' );
	}

	/**
	 * Ein SVG auf seine blosse Form bringen.
	 *
	 * Alles raus, was nicht Geometrie ist: Skripte, Verweise, Stile, Farben,
	 * Schrift. Uebrig bleiben die Umrisse — und genau die will eine Maske.
	 * Das ist strenger als cleanSvg(), weil hier nichts gebraucht wird ausser
	 * Pfaden; was nicht auf der Liste steht, fliegt raus statt gefiltert zu
	 * werden.
	 */
	public static function silhouette( string $svg ): string {
		$erlaubt  = array( 'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polygon', 'polyline' );
		$attribute = array(
			'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
			'width', 'height', 'points', 'transform', 'viewBox', 'fill-rule', 'clip-rule',
		);

		$vorher = libxml_use_internal_errors( true );
		$doc    = new \DOMDocument();

		// LIBXML_NONET: keine externen Entitaeten nachladen, auch nicht,
		// wenn das Dokument danach fragt.
		$geladen = $doc->loadXML( $svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );

		libxml_clear_errors();
		libxml_use_internal_errors( $vorher );

		if ( ! $geladen || null === $doc->documentElement || 'svg' !== strtolower( $doc->documentElement->nodeName ) ) {
			return '';
		}

		self::prune( $doc->documentElement, $erlaubt, $attribute );

		$wurzel = $doc->documentElement;

		// Ohne viewBox weiss der Browser nicht, wie gross die Form gemeint
		// war — dann lieber nichts als etwas Verzerrtes.
		if ( ! $wurzel->hasAttribute( 'viewBox' ) ) {
			return '';
		}

		$wurzel->setAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );

		// Eine Farbe fuer alles. Fuer die Maske zaehlt ohnehin nur, dass es
		// undurchsichtig ist.
		$wurzel->setAttribute( 'fill', '#000' );

		return (string) $doc->saveXML( $wurzel );
	}

	/**
	 * @param array<int,string> $erlaubt
	 * @param array<int,string> $attribute
	 */
	private static function prune( \DOMElement $element, array $erlaubt, array $attribute ): void {
		foreach ( iterator_to_array( $element->childNodes ) as $kind ) {
			if ( $kind instanceof \DOMElement ) {
				if ( ! in_array( strtolower( $kind->nodeName ), $erlaubt, true ) ) {
					$element->removeChild( $kind );
					continue;
				}

				self::prune( $kind, $erlaubt, $attribute );
				continue;
			}

			// Text, Kommentare, Verarbeitungsanweisungen: nichts davon
			// beschreibt eine Form.
			if ( ! $kind instanceof \DOMElement ) {
				$element->removeChild( $kind );
			}
		}

		foreach ( iterator_to_array( $element->attributes ) as $attr ) {
			if ( ! in_array( $attr->nodeName, $attribute, true ) ) {
				$element->removeAttribute( $attr->nodeName );
			}
		}
	}

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
			'favicon-32'       => '/assets/icons/favicon-32.png',
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
