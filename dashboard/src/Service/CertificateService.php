<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Crypto;
use NorthLab\Core\Http;
use NorthLab\Core\Setting;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;

/**
 * Restlaufzeit der TLS-Zertifikate — aus Uptime Kuma.
 *
 * Kuma prueft das Zertifikat ohnehin bei jedem Abruf. Es ein zweites Mal zu
 * pruefen hiesse, dieselbe Arbeit doppelt zu machen und zwei Wahrheiten zu
 * haben, die auseinanderlaufen koennen.
 *
 * Zwei Wege, die sich ergaenzen:
 *
 *  1. Die Benachrichtigung, die Kuma von selbst schickt, wenn es knapp wird.
 *     Kostet nichts: der Webhook steht schon. Kuma meldet aber nur an seinen
 *     Schwellen (Vorgabe 7, 14 und 21 Tage) und je Schwelle genau einmal —
 *     das ist eine Vorwarnung, kein laufender Wert.
 *  2. Der Abruf der Prometheus-Kennzahlen. Liefert fuer jeden Monitor die
 *     Restlaufzeit, jederzeit. Dafuer braucht es die Adresse von Kuma und
 *     einen API-Schluessel.
 */
final class CertificateService {

	/** Ab so vielen verbleibenden Tagen wird gewarnt, wenn nichts anderes eingestellt ist. */
	public const WARN_DEFAULT = 21;

	/**
	 * Eine Zertifikatsmeldung von Uptime Kuma lesen.
	 *
	 * Der Wortlaut stammt aus Kumas Quelltext:
	 *   [name][url] <Typ> certificate <CN> will expire in <N> days
	 *
	 * Bewusst nachsichtig gelesen: die Formulierung hat sich zwischen Fassungen
	 * schon geaendert, und eine Meldung zu verwerfen, weil ein Wort anders
	 * lautet, waere schlimmer als sie grosszuegig zu erkennen.
	 *
	 * @return array{days:int,url:string,subject:string}|null
	 */
	public static function fromMessage( string $message ): ?array {
		$text = trim( $message );

		// Kumas eigener Wortlaut ist englisch. Eigene Vorlagen sind dort aber
		// erlaubt, und eine deutsche Meldung zu verwerfen waere eine Luecke,
		// die niemandem auffaellt, bis das Zertifikat abgelaufen ist.
		if ( '' === $text || ! preg_match( '/\b(certificate|zertifikat)\b/i', $text ) ) {
			return null;
		}

		// "... will expire in 14 days", "... expires in 14 day", "... in 14 Tagen"
		if ( ! preg_match( '/\bin\s+(-?\d{1,5})\s*(?:days?|tagen?|d)\b/i', $text, $treffer ) ) {
			return null;
		}

		$days = (int) $treffer[1];

		// Die Adresse steht in der zweiten eckigen Klammer.
		$url = '';
		if ( preg_match_all( '/\[([^\]]*)\]/', $text, $klammern ) ) {
			foreach ( $klammern[1] as $inhalt ) {
				if ( preg_match( '#^https?://#i', trim( $inhalt ) ) ) {
					$url = trim( $inhalt );
					break;
				}
			}
		}

		$subject = '';
		if ( preg_match( '/certificate\s+([^\s]+)\s+(?:will|expires|is)/i', $text, $cn ) ) {
			$subject = trim( $cn[1] );
		}

		return array( 'days' => $days, 'url' => $url, 'subject' => $subject );
	}

	/**
	 * Eine Restlaufzeit vermerken und bei Bedarf warnen.
	 *
	 * @return bool Ob gewarnt wurde.
	 */
	public static function record( int $siteId, int $days, string $source, string $subject = '' ): bool {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return false;
		}

		$vorher = null === $site['ssl_days_left'] ? null : (int) $site['ssl_days_left'];

		SiteRepository::update(
			$siteId,
			array(
				'ssl_days_left'  => $days,
				'ssl_checked_at' => nl_utc(),
				'ssl_source'     => substr( $source, 0, 20 ),
				'ssl_subject'    => substr( $subject, 0, 191 ),
			)
		);

		$schwelle = self::warnDays();

		if ( $days > $schwelle ) {
			return false;
		}

		// Nur beim Unterschreiten melden, nicht bei jedem Abruf. Sonst kommt
		// drei Wochen lang jede Stunde dieselbe Warnung.
		if ( null !== $vorher && $vorher <= $schwelle ) {
			return false;
		}

		$text = $days < 0
			? sprintf( 'Das Zertifikat von "%s" ist seit %d Tag(en) abgelaufen.', (string) $site['name'], abs( $days ) )
			: sprintf( 'Das Zertifikat von "%s" läuft in %d Tag(en) ab.', (string) $site['name'], $days );

		ActivityRepository::log( 'site.ssl', $text, array( 'site_id' => $siteId, 'level' => $days <= 7 ? 'error' : 'warning' ) );

		EventBus::dispatch(
			'ssl.expiring',
			array( 'days' => $days, 'subject' => $subject, 'source' => $source ),
			array( 'site_id' => $siteId, 'message' => $text, 'level' => $days <= 7 ? 'error' : 'warning' )
		);

		return true;
	}

	public static function warnDays(): int {
		return max( 1, min( 180, Setting::getInt( 'ssl_warn_days', self::WARN_DEFAULT ) ) );
	}

	/* ------------------------------------------------- Abruf der Kennzahlen */

	/**
	 * Ist der Abruf bei Kuma eingerichtet?
	 */
	public static function pullConfigured(): bool {
		return '' !== trim( Setting::get( 'kuma_url', '' ) )
			&& '' !== trim( self::apiKey() );
	}

	public static function apiKey(): string {
		$gespeichert = Setting::get( 'kuma_api_key', '' );

		return '' === $gespeichert ? '' : (string) Crypto::decrypt( $gespeichert );
	}

	/**
	 * Restlaufzeiten aller Monitore bei Kuma holen.
	 *
	 * @return array{ok:bool,error:string,updated:int,unmatched:array<int,string>}
	 */
	public static function pullFromKuma(): array {
		$basis = rtrim( trim( Setting::get( 'kuma_url', '' ) ), '/' );
		$key   = self::apiKey();

		if ( '' === $basis || '' === $key ) {
			return self::pullResult( false, 'Für Uptime Kuma sind keine Zugangsdaten hinterlegt.' );
		}

		// Kuma erwartet HTTP-Basic mit leerem Benutzer und dem Schluessel als
		// Passwort. Steht ein Schluessel bereit, schaltet Kuma die gewoehnliche
		// Basic-Anmeldung dauerhaft ab — ein anderer Weg bleibt also nicht.
		$response = Http::get( $basis . '/metrics', array( 'Accept' => 'text/plain' ), 20, true, ':' . $key );

		if ( '' !== $response['error'] ) {
			return self::pullResult( false, 'Uptime Kuma nicht erreichbar: ' . $response['error'] );
		}

		if ( 401 === $response['status'] || 403 === $response['status'] ) {
			return self::pullResult( false, 'Uptime Kuma hat den API-Schlüssel abgelehnt.' );
		}

		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			return self::pullResult( false, sprintf( 'Uptime Kuma antwortete mit HTTP %d.', $response['status'] ) );
		}

		$werte = self::parseMetrics( $response['body'] );

		if ( ! $werte ) {
			return self::pullResult(
				false,
				'Die Antwort enthält keine Zertifikatswerte. Überwacht Kuma die Seiten über HTTPS?'
			);
		}

		$geaendert   = 0;
		$ohneZuord   = array();

		foreach ( $werte as $eintrag ) {
			$site = SiteRepository::findByLooseUrl( $eintrag['url'] );

			if ( null === $site ) {
				$ohneZuord[] = $eintrag['url'];
				continue;
			}

			self::record( (int) $site['id'], $eintrag['days'], 'kuma', $eintrag['name'] );
			$geaendert++;
		}

		return array(
			'ok'        => true,
			'error'     => '',
			'updated'   => $geaendert,
			'unmatched' => $ohneZuord,
		);
	}

	/**
	 * Die Zeilen "monitor_cert_days_remaining{...} 67" auslesen.
	 *
	 * @return array<int,array{url:string,name:string,days:int}>
	 */
	public static function parseMetrics( string $body ): array {
		$treffer = array();

		foreach ( preg_split( '/\R/', $body ) ?: array() as $zeile ) {
			$zeile = trim( $zeile );

			if ( '' === $zeile || '#' === $zeile[0] || 0 !== strpos( $zeile, 'monitor_cert_days_remaining' ) ) {
				continue;
			}

			if ( ! preg_match( '/^monitor_cert_days_remaining\{(.*)\}\s+(-?[\d.eE+]+)\s*$/', $zeile, $teile ) ) {
				continue;
			}

			$wert = $teile[2];

			// Prometheus kennt NaN fuer "kein Wert". Das ist keine Null.
			if ( ! is_numeric( $wert ) ) {
				continue;
			}

			$labels = self::parseLabels( $teile[1] );
			$url    = (string) ( $labels['monitor_url'] ?? '' );

			// Monitore ohne Adresse (Ping, Port) haben kein Zertifikat, das zu
			// einer Seite gehoert. Kuma schreibt dort "null" hinein.
			if ( '' === $url || 'null' === strtolower( $url ) ) {
				continue;
			}

			$treffer[] = array(
				'url'  => $url,
				'name' => (string) ( $labels['monitor_name'] ?? '' ),
				'days' => (int) round( (float) $wert ),
			);
		}

		return $treffer;
	}

	/**
	 * @return array<string,string>
	 */
	private static function parseLabels( string $raw ): array {
		$labels = array();

		if ( preg_match_all( '/([a-zA-Z_][a-zA-Z0-9_]*)="((?:[^"\\\\]|\\\\.)*)"/', $raw, $paare, PREG_SET_ORDER ) ) {
			foreach ( $paare as $paar ) {
				$labels[ $paar[1] ] = stripcslashes( $paar[2] );
			}
		}

		return $labels;
	}

	/**
	 * @return array{ok:bool,error:string,updated:int,unmatched:array<int,string>}
	 */
	private static function pullResult( bool $ok, string $error ): array {
		return array( 'ok' => $ok, 'error' => $error, 'updated' => 0, 'unmatched' => array() );
	}
}
