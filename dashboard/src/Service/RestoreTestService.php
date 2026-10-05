<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Database;
use NorthLab\Core\Setting;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;

/**
 * Prueft, ob sich aus einer Sicherung wirklich etwas zurueckholen laesst.
 *
 * "restic check" sagt nur, dass die Pakete in sich stimmen. Das beantwortet
 * nicht die Frage, auf die es ankommt: kommt eine Datei heil wieder heraus?
 * Darum wird hier eine echte Datei geholt und mit der Groesse verglichen,
 * die im Verzeichnis des Sicherungspunkts steht.
 *
 * Geprueft wird eine Seite je Durchgang. Alle auf einmal hiesse, dass der
 * Server einmal im Monat einen halben Tag beschaeftigt ist.
 */
final class RestoreTestService {

	/** Hoechstens so viele Bytes werden zum Vergleich geholt. */
	private const MAX_BYTES = 8388608;

	/** Nach so vielen Tagen ist eine Seite wieder faellig. */
	public const INTERVAL_DAYS = 30;

	/**
	 * Die naechste faellige Seite pruefen.
	 *
	 * @return string Kurzbericht fuer den Zeitplaner.
	 */
	public static function runDue(): string {
		if ( ! Setting::getBool( 'restore_test_enabled', true ) ) {
			return 'abgeschaltet';
		}

		if ( ! Restic::configured() || ! Restic::available() ) {
			return 'kein Sicherungsziel';
		}

		$tage = max( 1, min( 365, Setting::getInt( 'restore_test_days', self::INTERVAL_DAYS ) ) );

		// Die am laengsten ungepruefte zuerst; noch nie geprueft zaehlt als
		// am laengsten her.
		$zeile = Database::selectOne(
			'SELECT `id`, `name` FROM `' . Database::table( 'sites' ) . '`
			 WHERE `is_paused` = 0
			   AND `site_type` = :typ
			   AND `last_backup_at` IS NOT NULL
			   AND ( `restore_test_at` IS NULL OR `restore_test_at` < :grenze )
			 ORDER BY `restore_test_at` IS NOT NULL, `restore_test_at` ASC
			 LIMIT 1',
			array( 'typ' => 'wordpress', 'grenze' => gmdate( 'Y-m-d H:i:s', time() - $tage * 86400 ) )
		);

		if ( null === $zeile ) {
			return 'nichts fällig';
		}

		$ergebnis = self::check( (int) $zeile['id'] );

		return sprintf(
			'%s: %s',
			(string) $zeile['name'],
			$ergebnis['ok'] ? 'lesbar' : 'FEHLER — ' . $ergebnis['note']
		);
	}

	/**
	 * Eine Seite pruefen: eine echte Datei aus der juengsten Sicherung holen.
	 *
	 * @return array{ok:bool,note:string,file:string,bytes:int}
	 */
	public static function check( int $siteId ): array {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return self::result( $siteId, false, 'Seite nicht gefunden.' );
		}

		$punkte = Restic::snapshots( BackupService::hostFor( $site ) );

		if ( ! $punkte ) {
			return self::result( $siteId, false, 'Es gibt keinen Sicherungspunkt zum Prüfen.' );
		}

		// Den juengsten nehmen — der ist der, auf den man sich im Ernstfall
		// verlassen wuerde.
		usort(
			$punkte,
			static fn( array $a, array $b ): int => strcmp( (string) ( $b['time'] ?? '' ), (string) ( $a['time'] ?? '' ) )
		);

		$snapshot = (string) ( $punkte[0]['short_id'] ?? $punkte[0]['id'] ?? '' );
		$root     = Restic::snapshotRoot( $snapshot );

		if ( '' === $snapshot || null === $root ) {
			return self::result( $siteId, false, 'Der Sicherungspunkt lässt sich nicht öffnen.' );
		}

		$datei = self::pickFile( $snapshot, $root, 0 );

		if ( null === $datei ) {
			return self::result( $siteId, false, 'Im Sicherungspunkt ist keine prüfbare Datei zu finden.' );
		}

		$geholt = 0;
		$dump   = Restic::dump(
			$snapshot,
			(string) $datei['path'],
			null,
			static function ( string $stueck ) use ( &$geholt ): void {
				$geholt += strlen( $stueck );
			}
		);

		$kurz = ltrim( substr( (string) $datei['path'], strlen( $root ) ), '/' );

		if ( ! $dump['ok'] ) {
			return self::result( $siteId, false, 'Zurückholen scheiterte: ' . $dump['error'], $kurz, $geholt );
		}

		// Der eigentliche Punkt: kam genau so viel heraus, wie im Verzeichnis
		// des Sicherungspunkts steht? Eine Datei, die halb ankommt, ist
		// schlimmer als eine, die fehlt — sie faellt naemlich nicht auf.
		$beanstandung = self::verdict( $geholt, (int) $datei['size'] );

		if ( null !== $beanstandung ) {
			return self::result( $siteId, false, $beanstandung, $kurz, $geholt );
		}

		return self::result(
			$siteId,
			true,
			sprintf( '%s (%s) heil zurückgeholt.', $kurz, size_format_de( $geholt ) ),
			$kurz,
			$geholt
		);
	}

	/**
	 * Stimmt die zurueckgeholte Menge mit der erwarteten ueberein?
	 *
	 * Eigene Methode, damit sich die Regel pruefen laesst: eine halb
	 * angekommene Datei laesst sich mit echtem restic kaum herstellen,
	 * und ungeprueft waere genau dieser Vergleich die Stelle, die
	 * stillschweigend verschwinden koennte.
	 *
	 * @return string|null Beanstandung — oder null, wenn alles passt.
	 */
	public static function verdict( int $geholt, int $erwartet ): ?string {
		if ( $geholt === $erwartet ) {
			return null;
		}

		if ( $geholt < $erwartet ) {
			return sprintf(
				'Die Datei kam unvollständig an: %d von %d Byte.',
				$geholt,
				$erwartet
			);
		}

		return sprintf(
			'Es kam mehr zurück als erwartet: %d statt %d Byte.',
			$geholt,
			$erwartet
		);
	}

	/**
	 * Eine kleine, nicht leere Datei im Sicherungspunkt suchen.
	 *
	 * Steigt hoechstens ein paar Ebenen ab — eine vollstaendige Suche waere
	 * bei einer Mediathek teurer als die Pruefung selbst.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function pickFile( string $snapshot, string $directory, int $tiefe ) {
		if ( $tiefe > 3 ) {
			return null;
		}

		$liste = Restic::ls( $snapshot, $directory );

		if ( ! $liste['ok'] ) {
			return null;
		}

		$ordner = array();

		foreach ( $liste['entries'] as $eintrag ) {
			if ( 'file' === $eintrag['type'] && $eintrag['size'] > 0 && $eintrag['size'] <= self::MAX_BYTES ) {
				return $eintrag;
			}

			if ( 'dir' === $eintrag['type'] ) {
				$ordner[] = (string) $eintrag['path'];
			}
		}

		foreach ( $ordner as $unter ) {
			$treffer = self::pickFile( $snapshot, $unter, $tiefe + 1 );

			if ( null !== $treffer ) {
				return $treffer;
			}
		}

		return null;
	}

	/**
	 * Ergebnis festhalten und melden.
	 *
	 * @return array{ok:bool,note:string,file:string,bytes:int}
	 */
	private static function result( int $siteId, bool $ok, string $note, string $file = '', int $bytes = 0 ): array {
		SiteRepository::update(
			$siteId,
			array(
				'restore_test_at'   => nl_utc(),
				'restore_test_ok'   => $ok ? 1 : 0,
				'restore_test_note' => substr( $note, 0, 255 ),
			)
		);

		ActivityRepository::log(
			'site.restoretest',
			( $ok ? 'Wiederherstellung geprüft: ' : 'Wiederherstellung NICHT möglich: ' ) . $note,
			array( 'site_id' => $siteId, 'level' => $ok ? 'info' : 'error' )
		);

		if ( ! $ok ) {
			EventBus::dispatch(
				'restore.failed',
				array( 'note' => $note, 'file' => $file ),
				array(
					'site_id' => $siteId,
					'level'   => 'error',
					'message' => 'Aus der Sicherung lässt sich nichts zurückholen: ' . $note,
				)
			);
		}

		return array( 'ok' => $ok, 'note' => $note, 'file' => $file, 'bytes' => $bytes );
	}
}
