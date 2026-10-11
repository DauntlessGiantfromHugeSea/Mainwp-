<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Http;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;

/**
 * WordPress-Benutzer einer betreuten Seite verwalten.
 *
 * Zu unterscheiden von UserRepository — das sind die Konten des Panels selbst.
 *
 * Geheimnisse (erzeugte Passwörter, Anmeldeadressen) werden hier bewusst nirgends
 * gespeichert oder protokolliert: sie gehen einmal an den Browser und sind weg.
 */
final class SiteUserService {

	/** Rollen, die das Panel anbieten darf. */
	public const ROLES = array(
		'administrator' => 'Administrator',
		'editor'        => 'Redakteur',
		'author'        => 'Autor',
		'contributor'   => 'Mitarbeiter',
		'subscriber'    => 'Abonnent',
	);

	/** Vorschläge für die Befristung, in Minuten. */
	public const DURATIONS = array(
		60     => '1 Stunde',
		240    => '4 Stunden',
		1440   => '24 Stunden',
		10080  => '7 Tage',
		43200  => '30 Tage',
	);

	/**
	 * @return array{ok:bool,users:array<int,array<string,mixed>>,error:string}
	 */
	public static function all( int $siteId ): array {
		$site = self::managed( $siteId );

		if ( is_string( $site ) ) {
			return array( 'ok' => false, 'users' => array(), 'error' => $site );
		}

		$response = ChildClient::post( $site, '/users', array( 'action' => 'list', 'args' => array( 'limit' => 200 ) ) );

		if ( ! $response['ok'] ) {
			return array( 'ok' => false, 'users' => array(), 'error' => $response['error'] );
		}

		$users = (array) ( $response['data']['users'] ?? array() );
		usort(
			$users,
			static fn( array $a, array $b ): int => strcasecmp( (string) ( $a['login'] ?? '' ), (string) ( $b['login'] ?? '' ) )
		);

		return array( 'ok' => true, 'users' => $users, 'error' => '' );
	}

	/**
	 * Benutzer anlegen, wahlweise befristet.
	 *
	 * @param array<string,mixed> $data login, email, role, first_name, last_name, expires_minutes
	 * @return array{ok:bool,error:string,login:string,password:string,expires_at:?int}
	 */
	public static function create( int $siteId, array $data ): array {
		$site = self::managed( $siteId );

		if ( is_string( $site ) ) {
			return self::failure( $site );
		}

		$login = self::sanitizeLogin( (string) ( $data['login'] ?? '' ) );
		$email = trim( (string) ( $data['email'] ?? '' ) );
		$role  = (string) ( $data['role'] ?? 'subscriber' );

		if ( '' === $login ) {
			return self::failure( 'Bitte einen Anmeldenamen angeben (Buchstaben, Ziffern, . _ -).' );
		}
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return self::failure( 'Bitte eine gültige E-Mail-Adresse angeben.' );
		}
		if ( ! isset( self::ROLES[ $role ] ) ) {
			return self::failure( 'Unbekannte Rolle.' );
		}

		$minutes = max( 0, (int) ( $data['expires_minutes'] ?? 0 ) );
		$expires = $minutes > 0 ? time() + $minutes * 60 : 0;

		$response = ChildClient::post(
			$site,
			'/users',
			array(
				'action' => 'create',
				'data'   => array(
					'login'      => $login,
					'email'      => $email,
					'role'       => $role,
					'first_name' => (string) ( $data['first_name'] ?? '' ),
					'last_name'  => (string) ( $data['last_name'] ?? '' ),
					'expires_at' => $expires,
				),
			)
		);

		if ( ! $response['ok'] ) {
			return self::failure( $response['error'] );
		}

		$result = (array) ( $response['data']['result'] ?? array() );

		if ( empty( $result['success'] ) ) {
			return self::failure( (string) ( $result['message'] ?? 'Anlegen fehlgeschlagen.' ) );
		}

		self::log(
			$siteId,
			$expires > 0
				? sprintf( 'Befristeter WordPress-Benutzer "%s" (%s) angelegt, gültig bis %s.', $login, self::ROLES[ $role ], gmdate( 'd.m.Y H:i', $expires ) . ' UTC' )
				: sprintf( 'WordPress-Benutzer "%s" (%s) angelegt.', $login, self::ROLES[ $role ] )
		);

		return array(
			'ok'         => true,
			'error'      => '',
			'login'      => (string) ( $result['login'] ?? $login ),
			'password'   => (string) ( $result['password'] ?? '' ),
			'expires_at' => $expires > 0 ? $expires : null,
		);
	}

	/**
	 * Neues Passwort setzen und alle offenen Sitzungen beenden.
	 *
	 * @return array{ok:bool,error:string,login:string,password:string,expires_at:?int}
	 */
	public static function setPassword( int $siteId, int $userId ): array {
		$site = self::managed( $siteId );

		if ( is_string( $site ) ) {
			return self::failure( $site );
		}

		$response = ChildClient::post(
			$site,
			'/users',
			array( 'action' => 'set-password', 'data' => array( 'id' => $userId ) )
		);

		if ( ! $response['ok'] ) {
			return self::failure( $response['error'] );
		}

		$result = (array) ( $response['data']['result'] ?? array() );

		if ( empty( $result['success'] ) ) {
			return self::failure( (string) ( $result['message'] ?? 'Zurücksetzen fehlgeschlagen.' ) );
		}

		self::log( $siteId, sprintf( 'Passwort von "%s" zurückgesetzt, alle Sitzungen beendet.', (string) ( $result['login'] ?? $userId ) ) );

		return array(
			'ok'         => true,
			'error'      => '',
			'login'      => (string) ( $result['login'] ?? '' ),
			'password'   => (string) ( $result['password'] ?? '' ),
			'expires_at' => null,
		);
	}

	/**
	 * WordPress selbst eine Zurücksetz-Mail schicken lassen.
	 */
	public static function sendReset( int $siteId, int $userId ): ?string {
		return self::simple(
			$siteId,
			array( 'action' => 'reset-password', 'data' => array( 'id' => $userId ) ),
			'Zurücksetz-Mail verschickt.'
		);
	}

	public static function delete( int $siteId, int $userId, ?int $reassign = null ): ?string {
		return self::simple(
			$siteId,
			array( 'action' => 'delete', 'data' => array( 'id' => $userId, 'reassign' => $reassign ) ),
			'Benutzer gelöscht.'
		);
	}

	/**
	 * Befristung ändern; 0 Minuten hebt sie auf.
	 */
	public static function setExpiry( int $siteId, int $userId, int $minutes ): ?string {
		$expires = $minutes > 0 ? time() + $minutes * 60 : 0;

		return self::simple(
			$siteId,
			array( 'action' => 'extend', 'data' => array( 'id' => $userId, 'expires_at' => $expires ) ),
			$minutes > 0 ? 'Befristung geändert.' : 'Befristung aufgehoben.'
		);
	}

	/**
	 * Einmal-Adresse für die Ein-Klick-Anmeldung.
	 *
	 * Der Rückgabewert ist ein Geheimnis mit sehr kurzer Haltbarkeit — er gehört
	 * in eine Weiterleitung und in kein Protokoll.
	 *
	 * @return array{ok:bool,url:string,error:string}
	 */
	public static function loginLink( int $siteId, int $userId = 0 ): array {
		$site = self::managed( $siteId, 'autologin' );

		if ( is_string( $site ) ) {
			return array( 'ok' => false, 'url' => '', 'error' => $site );
		}

		$response = ChildClient::post(
			$site,
			'/login-link',
			array( 'data' => $userId > 0 ? array( 'user_id' => $userId ) : array() )
		);

		if ( ! $response['ok'] ) {
			return array( 'ok' => false, 'url' => '', 'error' => $response['error'] );
		}

		$data = (array) $response['data'];

		if ( empty( $data['ok'] ) || empty( $data['url'] ) ) {
			return array(
				'ok'    => false,
				'url'   => '',
				'error' => (string) ( $data['error'] ?? 'Die Seite hat keine Anmeldeadresse geliefert.' ),
			);
		}

		// Das Panel leitet den Browser gleich dorthin weiter — und zwar genau
		// in dem Moment, in dem jemand eine WordPress-Anmeldemaske erwartet.
		// Eine fremde Adresse waere eine perfekte Phishing-Gelegenheit.
		if ( ! Http::sameHost( (string) $data['url'], (string) $site['url'] ) ) {
			return array(
				'ok'    => false,
				'url'   => '',
				'error' => 'Die Seite hat eine Anmeldeadresse auf einem fremden Host geliefert. Das Panel folgt ihr nicht.',
			);
		}

		$login = (string) ( $data['user']['login'] ?? '?' );

		// Bewusst ohne die Adresse: das Protokoll ist für Menschen, nicht für Angreifer.
		self::log( $siteId, sprintf( 'Ein-Klick-Anmeldung als "%s" geöffnet.', $login ) );

		EventBus::dispatch(
			'site.autologin',
			array( 'user' => $login ),
			array(
				'site_id' => $siteId,
				'message' => sprintf( 'Ein-Klick-Anmeldung auf "%s" als "%s".', (string) $site['name'], $login ),
			)
		);

		return array( 'ok' => true, 'url' => (string) $data['url'], 'error' => '' );
	}

	/* ------------------------------------------------------------- Intern */

	/**
	 * @param array<string,mixed> $body
	 */
	private static function simple( int $siteId, array $body, string $success ): ?string {
		$site = self::managed( $siteId );

		if ( is_string( $site ) ) {
			return $site;
		}

		$response = ChildClient::post( $site, '/users', $body );

		if ( ! $response['ok'] ) {
			return $response['error'];
		}

		$result = (array) ( $response['data']['result'] ?? array() );

		if ( empty( $result['success'] ) ) {
			return (string) ( $result['message'] ?? 'Aktion fehlgeschlagen.' );
		}

		self::log( $siteId, $success . ' (' . (string) ( $body['action'] ?? '?' ) . ')' );

		return null;
	}

	/**
	 * @return array<string,mixed>|string Seite oder Fehlermeldung.
	 */
	private static function managed( int $siteId, string $feature = 'users' ) {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return 'Seite nicht gefunden.';
		}

		return ChildFeature::unavailable( $site, $feature ) ?? $site;
	}

	/**
	 * @return array{ok:bool,error:string,login:string,password:string,expires_at:?int}
	 */
	private static function failure( string $error ): array {
		return array( 'ok' => false, 'error' => $error, 'login' => '', 'password' => '', 'expires_at' => null );
	}

	private static function log( int $siteId, string $message ): void {
		ActivityRepository::log( 'site.users', $message, array( 'site_id' => $siteId ) );
	}

	private static function sanitizeLogin( string $login ): string {
		$login = strtolower( trim( $login ) );
		$login = preg_replace( '/[^a-z0-9._-]/', '', $login ) ?? '';

		return substr( $login, 0, 60 );
	}

	/* ------------------------------------------------------------ Diagnose */

	/**
	 * Die ganze Kette der Ein-Klick-Anmeldung einmal durchlaufen und sagen,
	 * wo sie reisst.
	 *
	 * "Klappt nicht" ist keine Fehlermeldung. Die Kette hat sechs Glieder —
	 * Child erreichbar, Funktion eingeschaltet, Adresse geliefert, Adresse
	 * plausibel, Adresse abrufbar, Anmeldung angenommen — und bisher musste
	 * man raten, welches davon gerissen ist. Hier wird jedes einzeln
	 * angefasst und benannt.
	 *
	 * Der dabei erzeugte Token wird verbraucht: die Pruefung meldet sich
	 * wirklich an, statt es zu behaupten. Danach einmal auf den richtigen
	 * Knopf druecken.
	 *
	 * @return array{ok:bool,schritte:array<int,array{name:string,ok:bool,text:string}>}
	 */
	public static function diagnose( int $siteId ): array {
		$schritte = array();

		$merke = static function ( string $name, bool $ok, string $text ) use ( &$schritte ): void {
			$schritte[] = array( 'name' => $name, 'ok' => $ok, 'text' => $text );
		};

		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			$merke( 'Seite', false, 'Seite nicht gefunden.' );

			return array( 'ok' => false, 'schritte' => $schritte );
		}

		$merke(
			'Child-Plugin',
			'' !== (string) $site['child_version'],
			'' !== (string) $site['child_version']
				? 'Version ' . $site['child_version']
				: 'Keine Version bekannt — die Seite hat sich noch nie gemeldet.'
		);

		// --- Funktion eingeschaltet? -------------------------------------
		$gesperrt = ChildFeature::unavailable( $site, 'autologin' );

		$merke( 'Funktion freigegeben', null === $gesperrt, $gesperrt ?? 'Die Ein-Klick-Anmeldung ist auf dieser Seite erlaubt.' );

		if ( null !== $gesperrt ) {
			return array( 'ok' => false, 'schritte' => $schritte );
		}

		// --- Liefert die Seite eine Adresse? ------------------------------
		$antwort = ChildClient::post( $site, '/login-link', array( 'data' => array() ) );

		if ( ! $antwort['ok'] ) {
			$merke( 'Adresse angefordert', false, $antwort['error'] );

			return array( 'ok' => false, 'schritte' => $schritte );
		}

		$daten = (array) $antwort['data'];
		$url   = (string) ( $daten['url'] ?? '' );

		if ( empty( $daten['ok'] ) || '' === $url ) {
			$merke( 'Adresse angefordert', false, (string) ( $daten['error'] ?? 'Die Seite hat keine Adresse geliefert.' ) );

			return array( 'ok' => false, 'schritte' => $schritte );
		}

		$merke( 'Adresse angefordert', true, 'Die Seite hat eine Einmal-Adresse geliefert.' );

		// --- Passt der Wirt? ----------------------------------------------
		// Genau hier hat es einmal geklemmt: im Panel stand "kunde.de", die
		// Installation laeuft auf "www.kunde.de". Das ist dieselbe Seite,
		// waren aber zwei Zeichenketten.
		$gleich = Http::sameHost( $url, (string) $site['url'] );

		$merke(
			'Wirt stimmt überein',
			$gleich,
			$gleich
				? 'Die Adresse liegt auf ' . Http::host( $url ) . '.'
				: sprintf(
					'Die Seite antwortet mit "%s", im Panel steht "%s". Das Panel folgt dorthin nicht. '
						. 'Bitte die Adresse der Seite im Panel angleichen.',
					Http::host( $url ),
					Http::host( (string) $site['url'] )
				)
		);

		if ( ! $gleich ) {
			return array( 'ok' => false, 'schritte' => $schritte );
		}

		// --- Was antwortet die Adresse wirklich? --------------------------
		$probe = Http::probe( $url, 20, ChildClient::verifiesSsl( $site ) );

		if ( ! $probe['ok'] ) {
			$merke( 'Adresse abrufbar', false, '' !== $probe['error'] ? $probe['error'] : 'Keine Antwort.' );

			return array( 'ok' => false, 'schritte' => $schritte );
		}

		$merke( 'Adresse abrufbar', true, sprintf( 'Status %d.', $probe['status'] ) );

		// --- Hat WordPress die Anmeldung angenommen? ----------------------
		// Das ist der eigentliche Beweis. Setzt WordPress das
		// Anmeldecookie, hat der Token gegriffen — alles andere ist
		// Vermutung.
		$angemeldet = false;

		foreach ( $probe['cookies'] as $cookie ) {
			if ( 0 === strpos( $cookie, 'wordpress_logged_in_' ) ) {
				$angemeldet = true;
				break;
			}
		}

		if ( $angemeldet ) {
			$merke( 'Anmeldung angenommen', true, 'WordPress hat das Anmeldecookie gesetzt. Die Ein-Klick-Anmeldung funktioniert.' );

			return array( 'ok' => true, 'schritte' => $schritte );
		}

		// Kein Cookie — also nachsehen, was stattdessen passiert ist.
		$merke( 'Anmeldung angenommen', false, self::erklaerung( $probe, (string) $site['url'], (array) ( $daten['caches'] ?? array() ) ) );

		return array( 'ok' => false, 'schritte' => $schritte );
	}

	/**
	 * Aus dem, was zurueckkam, einen brauchbaren Satz machen.
	 *
	 * Oeffentlich, weil genau das der Teil ist, der stimmen muss: die
	 * Fehlersuche ist nur so viel wert wie der Satz am Ende. Der laesst sich
	 * so einzeln pruefen, ohne eine ganze Kundenseite nachzubauen.
	 *
	 * @param array{status:int,location:string,cookies:array<int,string>,body:string} $probe
	 * @param array<int,mixed>                                                        $caches
	 */
	public static function erklaerung( array $probe, string $siteUrl, array $caches = array() ): string {
		$status = (int) $probe['status'];
		$ziel   = (string) $probe['location'];

		if ( 404 === $status || 403 === $status ) {
			return sprintf(
				'wp-login.php antwortet mit %d. Auf der Seite läuft vermutlich ein Plugin, das die '
					. 'Anmeldeseite versteckt oder sperrt (WPS Hide Login, Wordfence, iThemes). '
					. 'Dort eine Ausnahme eintragen oder die Ein-Klick-Anmeldung abschalten.',
				$status
			);
		}

		if ( '' !== $ziel && ! Http::sameHost( $ziel, $siteUrl ) ) {
			return 'Die Adresse leitet auf einen fremden Wirt weiter: ' . $ziel;
		}

		if ( '' !== $ziel && false === strpos( $ziel, 'wp-admin' ) ) {
			return sprintf(
				'Statt ins Backend wird nach "%s" weitergeleitet. %s',
				$ziel,
				$caches
					? 'Auf der Seite läuft ein Cache (' . implode( ', ', array_map( 'strval', $caches ) ) . '). '
						. 'Er liefert fertiges HTML aus, bevor WordPress den Token sieht — dort wp-login.php ausnehmen.'
					: 'Das sieht nach einer Weiterleitung aus, die vor WordPress greift (Webserver, Reverse Proxy, Sicherheits-Plugin).'
			);
		}

		if ( 200 === $status && false !== strpos( $probe['body'], 'loginform' ) ) {
			return 'Es kommt das Anmeldeformular zurück, der Token wurde also nicht eingelöst. '
				. 'Entweder ist das Child-Plugin zu alt für diese Seite, oder etwas räumt den '
				. 'Transient weg, in dem der Token steht (aggressives Object-Caching).';
		}

		if ( $caches ) {
			return 'Kein Anmeldecookie. Auf der Seite läuft ein Cache ('
				. implode( ', ', array_map( 'strval', $caches ) ) . ') — dort wp-login.php ausnehmen.';
		}

		return sprintf( 'Kein Anmeldecookie, Status %d%s. Das Panel kann von hier aus nicht weiter eingrenzen.',
			$status, '' !== $ziel ? ', Weiterleitung nach ' . $ziel : '' );
	}
}
