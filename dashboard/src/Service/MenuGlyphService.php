<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Logger;
use NorthLab\Repository\SiteRepository;
use Throwable;

/**
 * Das Zeichen der Agentur im Menü jeder betreuten Website.
 *
 * Hing anfangs am Branding — und kam deshalb nur dort an, wo jemand
 * Branding angewandt hatte. Falsch: das Branding ist etwas, das man je
 * Kunde ein- und ausschaltet, das Menüsymbol gehört zum Plugin selbst. Wer
 * es installiert hat, soll es sehen.
 *
 * Geschickt wird beim Sync, also ohnehin regelmässig — aber nur, wenn sich
 * wirklich etwas geändert hat. Dafür merkt sich das Panel je Seite, welchen
 * Stand sie schon kennt. Ein unverändertes Symbol kostet damit keine
 * einzige Anfrage.
 */
final class MenuGlyphService {

	/** Ab dieser Child-Fassung gibt es den Weg. */
	public const MIN_CHILD = '1.9.0';

	/**
	 * Der Stand, den alle Seiten haben sollen.
	 *
	 * Ein Hash über den Glyph selbst, nicht der Zeitstempel: so löst ein
	 * Zurücksetzen und erneutes Hochladen derselben Datei keine
	 * überflüssige Runde durch alle Seiten aus.
	 */
	public static function stamp(): string {
		$glyph = IconService::menuGlyph();

		return $glyph['ok'] ? substr( hash( 'sha256', (string) $glyph['data'] ), 0, 32 ) : 'ohne';
	}

	/**
	 * Braucht diese Seite eine neue Fassung?
	 *
	 * @param array<string,mixed> $site
	 */
	public static function needsPush( array $site ): bool {
		if ( ! SiteRepository::isManaged( $site ) ) {
			return false;
		}

		if ( version_compare( (string) ( $site['child_version'] ?? '' ), self::MIN_CHILD, '<' ) ) {
			return false;
		}

		return (string) ( $site['glyph_stamp'] ?? '' ) !== self::stamp();
	}

	/**
	 * Das Symbol an eine Seite schicken.
	 *
	 * Ein Fehler hier hält nie etwas anderes auf — es ist ein Bild für
	 * einen Menüpunkt, keine Wartung.
	 *
	 * @param array<string,mixed> $site
	 */
	public static function push( array $site ): bool {
		$stempel = self::stamp();
		$glyph   = IconService::menuGlyph();

		try {
			$antwort = ChildClient::post(
				$site,
				'/symbol',
				array(
					'glyph' => $glyph['ok'] ? (string) $glyph['data'] : '',
					'stamp' => $stempel,
				),
				30
			);
		} catch ( Throwable $e ) {
			Logger::exception( $e );

			return false;
		}

		if ( ! $antwort['ok'] ) {
			return false;
		}

		// Erst merken, wenn es wirklich angekommen ist. Sonst bliebe eine
		// Seite ohne Symbol stehen, weil das Panel glaubt, sie haette es.
		SiteRepository::update( (int) $site['id'], array( 'glyph_stamp' => $stempel ) );

		return true;
	}

	/**
	 * Nach dem Sync nachziehen, wenn nötig.
	 *
	 * @param array<string,mixed> $site
	 */
	public static function afterSync( array $site ): void {
		if ( self::needsPush( $site ) ) {
			self::push( $site );
		}
	}

	/**
	 * Alle Seiten auf den neuen Stand bringen.
	 *
	 * Läuft nach dem Hochladen eines neuen Zeichens, damit es nicht bis zum
	 * nächsten Sync dauert.
	 *
	 * @return array{sent:int,failed:int,skipped:int}
	 */
	public static function pushAll(): array {
		$gesendet = 0;
		$fehler   = 0;
		$offen    = 0;

		foreach ( SiteRepository::active() as $site ) {
			if ( ! SiteRepository::isManaged( $site ) || ! empty( $site['is_paused'] ) ) {
				continue;
			}

			if ( version_compare( (string) ( $site['child_version'] ?? '' ), self::MIN_CHILD, '<' ) ) {
				// Noch zu alt — das holt der Sync nach, sobald das Plugin
				// sich selbst aktualisiert hat.
				$offen++;
				continue;
			}

			if ( ! self::needsPush( $site ) ) {
				continue;
			}

			if ( self::push( $site ) ) {
				$gesendet++;
			} else {
				$fehler++;
			}
		}

		return array( 'sent' => $gesendet, 'failed' => $fehler, 'skipped' => $offen );
	}
}
