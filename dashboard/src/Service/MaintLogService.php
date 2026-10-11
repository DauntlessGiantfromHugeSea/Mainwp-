<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Logger;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\ClientRepository;
use NorthLab\Repository\SiteRepository;
use NorthLab\Core\Setting;
use Throwable;

/**
 * Was die Agentur getan hat — sichtbar im Backend der Kundenseite.
 *
 * Die Daten liegen längst im Panel: jedes eingespielte Update steht als
 * Ereignis im Protokoll, mit Zeitpunkt, Paket und Versionssprung. Nur sah
 * der Kunde davon nichts, ohne dass jemand einen Bericht verschickte.
 *
 * Deshalb derselbe Weg wie beim Sicherungsverlauf: das Panel ist die Quelle,
 * die Kundenseite hält eine Kopie zum Anzeigen. Geschoben wird nach jedem
 * Lauf, nicht gezogen — die Kundenseite soll das Panel nicht fragen müssen.
 */
final class MaintLogService {

	/** So viele Einträge hält die Kundenseite vor. */
	public const MAX = 20;

	/** So weit zurück wird gesammelt. */
	public const DAYS = 365;

	/**
	 * Den Verlauf einer Seite aus dem Protokoll zusammenstellen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function entries( int $siteId ): array {
		$seit = gmdate( 'Y-m-d H:i:s', time() - self::DAYS * 86400 );

		$roh = ActivityRepository::query(
			array(
				'site_id' => $siteId,
				'action'  => 'update.applied',
				'since'   => $seit,
				'limit'   => 200,
			)
		);

		$log = array();

		foreach ( $roh as $zeile ) {
			$kontext = json_decode( (string) ( $zeile['context'] ?? '' ), true );
			$teile   = array();

			foreach ( (array) ( $kontext['items'] ?? array() ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$teile[] = array(
					'type' => (string) ( $item['type'] ?? 'plugin' ),
					'name' => (string) ( $item['name'] ?? $item['slug'] ?? '' ),
					'from' => (string) ( $item['from_version'] ?? '' ),
					'to'   => (string) ( $item['to_version'] ?? '' ),
				);
			}

			$log[] = array(
				'at'     => (string) $zeile['created_at'],
				'kind'   => 'update',
				'ok'     => true,
				'count'  => count( $teile ),
				'failed' => 0,
				'note'   => '',
				'items'  => $teile,
			);

			if ( count( $log ) >= self::MAX ) {
				break;
			}
		}

		return $log;
	}

	/**
	 * Was das Panel weiß und die Kundenseite nicht.
	 *
	 * Die Restlaufzeit des Zertifikats etwa kommt aus der Überwachung, nicht
	 * aus WordPress.
	 *
	 * @param array<string,mixed> $site
	 * @return array<string,mixed>
	 */
	public static function state( array $site ): array {
		$kontakt = Setting::get( 'agency_email', '' );

		if ( ! empty( $site['client_id'] ) ) {
			$kunde = ClientRepository::find( (int) $site['client_id'] );

			// Bewusst die Agenturadresse, nicht die des Kunden: der Kunde
			// liest das hier, er braucht nicht seine eigene Anschrift.
			$kontakt = '' !== (string) $kontakt ? $kontakt : (string) ( $kunde['email'] ?? '' );
		}

		return array(
			'ssl_days'  => null !== $site['ssl_days_left'] ? (int) $site['ssl_days_left'] : null,
			'ssl_until' => (string) ( $site['ssl_checked_at'] ?? '' ),
			'pending'   => (int) $site['pending_updates'],
			'backup_at' => (string) ( $site['last_backup_at'] ?? '' ),
			'contact'   => (string) $kontakt,
		);
	}

	/**
	 * Verlauf und Stand zur Kundenseite schieben.
	 *
	 * Ein Fehler hier darf nie etwas anderes aufhalten: das ist eine Anzeige,
	 * keine Wartung. Deshalb wird nur protokolliert, nicht geworfen.
	 *
	 * @param array<string,mixed>|int $site
	 */
	public static function push( $site ): bool {
		$site = is_array( $site ) ? $site : SiteRepository::find( (int) $site );

		if ( null === $site ) {
			return false;
		}

		if ( null !== ChildFeature::unavailable( $site, 'maintlog' ) ) {
			return false;
		}

		try {
			$antwort = ChildClient::post(
				$site,
				'/maintlog',
				array(
					'action'  => 'set',
					'entries' => self::entries( (int) $site['id'] ),
					'stand'   => self::state( $site ),
				),
				30
			);
		} catch ( Throwable $e ) {
			Logger::exception( $e );

			return false;
		}

		if ( ! $antwort['ok'] ) {
			Logger::write( 'warning', sprintf(
				'Wartungsübersicht für "%s" nicht übertragen: %s',
				(string) $site['name'],
				(string) $antwort['error']
			) );
		}

		return (bool) $antwort['ok'];
	}

	/**
	 * Für alle betreuten Seiten nachziehen.
	 *
	 * Läuft im Zeitplaner mit, damit auch eine Seite aktuell bleibt, auf der
	 * gerade nichts eingespielt wurde — die Restlaufzeit des Zertifikats
	 * ändert sich schliesslich jeden Tag.
	 */
	public static function pushAll(): string {
		$ok     = 0;
		$fehler = 0;

		foreach ( SiteRepository::active() as $site ) {
			if ( ! SiteRepository::isManaged( $site ) || ! empty( $site['is_paused'] ) ) {
				continue;
			}

			if ( self::push( $site ) ) {
				$ok++;
			} else {
				$fehler++;
			}
		}

		if ( 0 === $ok && 0 === $fehler ) {
			return 'keine passende Seite';
		}

		return sprintf( '%d übertragen, %d nicht', $ok, $fehler );
	}
}
