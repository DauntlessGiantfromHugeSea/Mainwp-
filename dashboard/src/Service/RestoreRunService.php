<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Auth;
use NorthLab\Core\Database;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;

/**
 * Eine Sicherung zurückspielen — mit Freigabe durch die Agentur.
 *
 * Das ist der einzige Vorgang im Panel, der die Kundenseite überschreibt.
 * Entsprechend eng ist er gebaut:
 *
 * - Die Agentur wählt Seite, Sicherungspunkt und Umfang. Erst daraus
 *   entsteht ein Vorhaben, und nur dafür gilt der Code.
 * - Der Kunde kann das Zurückspielen auslösen, aber nichts daran ändern.
 *   Er tippt sechs Ziffern ein; welcher Stand das ist, hat die Agentur
 *   vorher festgelegt.
 * - Der Code gilt einmal, eine Stunde lang, und wird nach wenigen
 *   Fehlversuchen ungültig. Sechs Ziffern sind eine Million Möglichkeiten —
 *   ohne Bremse wäre das kein Schutz, sondern eine Zahl.
 * - Vor dem Zurückspielen wird gesichert. Immer. Wer einen Stand von
 *   gestern einspielt, verliert sonst alles von heute, und zwar endgültig.
 */
final class RestoreRunService {

	/** So lange gilt ein ausgegebener Code. */
	public const CODE_TTL = 3600;

	/** So oft darf daneben getippt werden, dann ist der Code verbrannt. */
	public const CODE_TRIES = 5;

	/** Was zurückgespielt werden kann. */
	public const SCOPES = array(
		'db'    => 'nur die Datenbank',
		'files' => 'nur die Dateien',
		'both'  => 'Datenbank und Dateien',
	);

	/** Ein Lauf, der sich so lange nicht gemeldet hat, gilt als tot. */
	public const PULS_TOT = 1800;

	/* ------------------------------------------------------------ Anlegen */

	/**
	 * Ein Vorhaben anlegen und einen Freigabecode ausgeben.
	 *
	 * Der Klartext-Code wird genau hier einmal zurückgegeben und sonst
	 * nirgends gespeichert — in der Tabelle steht nur sein Hash. Wer ihn
	 * verliert, fordert einen neuen an; das ist billiger als ein Code, der
	 * im Protokoll nachlesbar wäre.
	 *
	 * @return array{ok:bool,error:string,code:string,id:int}
	 */
	public static function request( int $siteId, string $snapshot, string $scope ): array {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return self::nein( 'Seite nicht gefunden.' );
		}
		if ( ! SiteRepository::isManaged( $site ) ) {
			return self::nein( 'Diese Seite wird nur überwacht — dorthin lässt sich nichts zurückspielen.' );
		}
		if ( ! isset( self::SCOPES[ $scope ] ) ) {
			return self::nein( 'Unbekannter Umfang.' );
		}
		if ( ! Scheduler::isCronHealthy() ) {
			return self::nein( 'Der Zeitplaner läuft nicht — ein Zurückspielen würde liegen bleiben.' );
		}
		if ( ! RestoreService::belongsToSite( $site, $snapshot ) ) {
			return self::nein( 'Dieser Sicherungspunkt gehört nicht zu dieser Seite.' );
		}

		// Ein offenes Vorhaben je Seite. Zwei gleichzeitig waeren zwei
		// gueltige Codes fuer zwei verschiedene Staende — und am Telefon
		// weiss dann niemand mehr, welcher welcher ist.
		self::discardOpen( $siteId, 'Durch ein neues Vorhaben ersetzt.' );

		$code = self::freshCode();

		$id = Database::insert(
			'restore_jobs',
			array(
				'site_id'         => $siteId,
				'snapshot'        => $snapshot,
				'snapshot_at'     => self::snapshotTime( $site, $snapshot ),
				'scope'           => $scope,
				'status'          => 'wartet_code',
				'code_hash'       => hash( 'sha256', $code ),
				'code_expires_at' => nl_utc( self::CODE_TTL ),
				'requested_at'    => nl_utc(),
				'requested_by'    => Auth::id() ?: null,
			)
		);

		// Bewusst ohne den Code: das Protokoll ist fuer Menschen, nicht fuer
		// jemanden, der sich spaeter Zugang verschafft hat.
		ActivityRepository::log(
			'restore.requested',
			sprintf(
				'Freigabe zum Zurückspielen erstellt (%s, Stand %s).',
				self::SCOPES[ $scope ],
				substr( $snapshot, 0, 8 )
			),
			array( 'site_id' => $siteId, 'level' => 'warning' )
		);

		return array( 'ok' => true, 'error' => '', 'code' => $code, 'id' => $id );
	}

	/**
	 * Sechs Ziffern, gleichverteilt.
	 *
	 * random_int und nicht rand: der Code ist ein Geheimnis, auch wenn er
	 * kurz ist.
	 */
	public static function freshCode(): string {
		return str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}

	/* ----------------------------------------------------------- Einlösen */

	/**
	 * Den Code der Kundenseite prüfen und das Vorhaben freigeben.
	 *
	 * Absichtlich dieselbe Antwort für "kein Vorhaben offen" und "Code
	 * falsch": sonst verrät die Unterscheidung, ob gerade eine Freigabe
	 * aussteht.
	 *
	 * @return array{ok:bool,error:string,scope:string}
	 */
	public static function redeem( int $siteId, string $code ): array {
		$code = preg_replace( '/\D/', '', $code ) ?? '';

		$job = self::openFor( $siteId );

		if ( null === $job || 'wartet_code' !== $job['status'] ) {
			return array( 'ok' => false, 'error' => 'Der Code stimmt nicht oder ist abgelaufen.', 'scope' => '' );
		}

		if ( strtotime( (string) $job['code_expires_at'] . ' UTC' ) < time() ) {
			self::discard( (int) $job['id'], 'Der Code ist abgelaufen.' );

			return array( 'ok' => false, 'error' => 'Der Code stimmt nicht oder ist abgelaufen.', 'scope' => '' );
		}

		$versuche = (int) $job['code_tries'] + 1;

		Database::update( 'restore_jobs', array( 'code_tries' => $versuche ), array( 'id' => (int) $job['id'] ) );

		if ( 6 !== strlen( $code ) || ! hash_equals( (string) $job['code_hash'], hash( 'sha256', $code ) ) ) {
			if ( $versuche >= self::CODE_TRIES ) {
				self::discard( (int) $job['id'], 'Zu viele Fehlversuche beim Code.' );

				ActivityRepository::log(
					'restore.blocked',
					'Freigabe zum Zurückspielen nach zu vielen Fehlversuchen verworfen.',
					array( 'site_id' => $siteId, 'level' => 'warning' )
				);
			}

			return array( 'ok' => false, 'error' => 'Der Code stimmt nicht oder ist abgelaufen.', 'scope' => '' );
		}

		// Der Code ist verbraucht, sobald er einmal gestimmt hat.
		Database::update(
			'restore_jobs',
			array(
				'status'      => 'vorgemerkt',
				'code_hash'   => '',
				'released_at' => nl_utc(),
			),
			array( 'id' => (int) $job['id'] )
		);

		ActivityRepository::log(
			'restore.released',
			sprintf( 'Zurückspielen freigegeben (%s).', self::SCOPES[ (string) $job['scope'] ] ?? (string) $job['scope'] ),
			array( 'site_id' => $siteId, 'level' => 'warning' )
		);

		return array( 'ok' => true, 'error' => '', 'scope' => (string) $job['scope'] );
	}

	/**
	 * Aus dem Panel heraus starten — ohne Code.
	 *
	 * Wer das Panel bedienen darf, braucht sich bei sich selbst nicht
	 * auszuweisen.
	 *
	 * @return array{ok:bool,error:string}
	 */
	public static function startNow( int $siteId, string $snapshot, string $scope ): array {
		$vorhaben = self::request( $siteId, $snapshot, $scope );

		if ( ! $vorhaben['ok'] ) {
			return array( 'ok' => false, 'error' => $vorhaben['error'] );
		}

		Database::update(
			'restore_jobs',
			array( 'status' => 'vorgemerkt', 'code_hash' => '', 'released_at' => nl_utc() ),
			array( 'id' => $vorhaben['id'] )
		);

		return array( 'ok' => true, 'error' => '' );
	}

	/* -------------------------------------------------------------- Stand */

	/**
	 * Das offene Vorhaben einer Seite.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function openFor( int $siteId ): ?array {
		return Database::selectOne(
			'SELECT * FROM `' . Database::table( 'restore_jobs' ) . '`
			 WHERE `site_id` = :id AND `status` IN (:a, :b, :c)
			 ORDER BY `id` DESC LIMIT 1',
			array( 'id' => $siteId, 'a' => 'wartet_code', 'b' => 'vorgemerkt', 'c' => 'laeuft' )
		);
	}

	/**
	 * Was die Kundenseite über den Stand wissen darf.
	 *
	 * Bewusst wenig: dass eine Freigabe aussteht, steht hier nicht. Sonst
	 * wüsste jeder im Backend des Kunden, dass es sich gerade lohnt, Codes
	 * durchzuprobieren.
	 *
	 * @return array<string,mixed>
	 */
	public static function stateForSite( int $siteId ): array {
		$job = self::openFor( $siteId );

		if ( null === $job || 'wartet_code' === $job['status'] ) {
			return array( 'running' => false );
		}

		return array(
			'running' => true,
			'phase'   => (string) $job['phase'],
			'done'    => (int) $job['phase_done'],
			'total'   => (int) $job['phase_total'],
			'scope'   => (string) $job['scope'],
		);
	}

	/* ----------------------------------------------------------- Verwerfen */

	public static function discard( int $id, string $grund ): void {
		Database::update(
			'restore_jobs',
			array( 'status' => 'verworfen', 'finished_at' => nl_utc(), 'code_hash' => '', 'note' => $grund ),
			array( 'id' => $id )
		);
	}

	public static function discardOpen( int $siteId, string $grund ): int {
		$offen = Database::select(
			'SELECT `id` FROM `' . Database::table( 'restore_jobs' ) . '`
			 WHERE `site_id` = :id AND `status` IN (:a, :b)',
			array( 'id' => $siteId, 'a' => 'wartet_code', 'b' => 'vorgemerkt' )
		);

		foreach ( $offen as $zeile ) {
			self::discard( (int) $zeile['id'], $grund );
		}

		return count( $offen );
	}

	/**
	 * Abgelaufene Freigaben wegräumen.
	 *
	 * Ein Code, der eine Stunde lang nicht benutzt wurde, soll nicht morgen
	 * noch gelten, bloss weil niemand hingesehen hat.
	 */
	public static function expireCodes(): int {
		$alt = Database::select(
			'SELECT `id` FROM `' . Database::table( 'restore_jobs' ) . "`
			 WHERE `status` = 'wartet_code' AND `code_expires_at` < :jetzt",
			array( 'jetzt' => nl_utc() )
		);

		foreach ( $alt as $zeile ) {
			self::discard( (int) $zeile['id'], 'Der Code ist abgelaufen, ohne benutzt zu werden.' );
		}

		return count( $alt );
	}

	/* -------------------------------------------------------------- Intern */

	/**
	 * Wann wurde dieser Sicherungspunkt angelegt?
	 *
	 * @param array<string,mixed> $site
	 */
	private static function snapshotTime( array $site, string $snapshot ): ?string {
		foreach ( Restic::snapshots( BackupService::hostFor( $site ), BackupService::tagFor( (int) $site['id'] ) ) as $eintrag ) {
			$id   = (string) ( $eintrag['id'] ?? '' );
			$kurz = (string) ( $eintrag['short_id'] ?? '' );

			if ( 0 === strcasecmp( $snapshot, $id ) || 0 === strcasecmp( $snapshot, $kurz ) ) {
				$zeit = strtotime( (string) ( $eintrag['time'] ?? '' ) );

				return $zeit ? gmdate( 'Y-m-d H:i:s', $zeit ) : null;
			}
		}

		return null;
	}

	/**
	 * @return array{ok:bool,error:string,code:string,id:int}
	 */
	private static function nein( string $grund ): array {
		return array( 'ok' => false, 'error' => $grund, 'code' => '', 'id' => 0 );
	}
}
