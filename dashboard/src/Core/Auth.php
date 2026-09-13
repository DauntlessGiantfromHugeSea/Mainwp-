<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\UserRepository;

/**
 * Anmeldung, Sitzungsverwaltung und Rollen.
 *
 * Rollen: admin (alles), member (Seiten verwalten, keine Benutzer/Einstellungen),
 *         readonly (nur lesen).
 */
final class Auth {

	/** @var array<string,mixed>|null */
	private static ?array $user = null;

	private static bool $resolved = false;

	/**
	 * @return array<string,mixed>|null
	 */
	public static function user(): ?array {
		if ( self::$resolved ) {
			return self::$user;
		}

		self::$resolved = true;

		$userId    = (int) Session::get( 'user_id', 0 );
		$sessionId = Session::id();

		if ( $userId <= 0 ) {
			return null;
		}

		// Die Sitzung muss serverseitig noch registriert sein — so lassen sich
		// Sitzungen zentral beenden (z. B. nach einem Passwortwechsel).
		$registered = Database::selectOne(
			'SELECT * FROM `' . Database::table( 'sessions' ) . '` WHERE `id` = :id AND `user_id` = :uid AND `expires_at` > :now',
			array( 'id' => hash( 'sha256', $sessionId ), 'uid' => $userId, 'now' => nl_utc() )
		);

		if ( null === $registered ) {
			Session::destroy();
			return null;
		}

		$user = UserRepository::find( $userId );

		if ( null === $user || ! (int) $user['is_active'] ) {
			self::logout();
			return null;
		}

		self::$user = $user;

		return self::$user;
	}

	public static function check(): bool {
		return null !== self::user();
	}

	public static function id(): int {
		$user = self::user();
		return $user ? (int) $user['id'] : 0;
	}

	public static function role(): string {
		$user = self::user();
		return $user ? (string) $user['role'] : 'guest';
	}

	public static function isAdmin(): bool {
		return 'admin' === self::role();
	}

	/**
	 * Darf schreibende Aktionen ausführen?
	 */
	public static function canWrite(): bool {
		return in_array( self::role(), array( 'admin', 'member' ), true );
	}

	/**
	 * Versucht die Anmeldung. Gibt bei Erfolg null zurück, sonst die Fehlermeldung.
	 */
	public static function attempt( string $email, string $password, Request $request ): ?string {
		$email = strtolower( trim( $email ) );
		$user  = UserRepository::findByEmail( $email );

		// Konstante Antwortzeit gegen Benutzer-Enumeration.
		$hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';

		if ( null !== $user && ! empty( $user['locked_until'] ) && strtotime( (string) $user['locked_until'] . ' UTC' ) > time() ) {
			$remaining = strtotime( (string) $user['locked_until'] . ' UTC' ) - time();
			return sprintf( 'Zu viele Fehlversuche. Bitte in %s erneut versuchen.', nl_duration( $remaining ) );
		}

		$valid = password_verify( $password, (string) $hash );

		if ( null === $user || ! $valid ) {
			if ( null !== $user ) {
				self::registerFailure( $user );
			}
			return 'E-Mail-Adresse oder Passwort ist falsch.';
		}

		if ( ! (int) $user['is_active'] ) {
			return 'Dieses Konto ist deaktiviert.';
		}

		if ( password_needs_rehash( (string) $user['password_hash'], PASSWORD_DEFAULT ) ) {
			UserRepository::updatePassword( (int) $user['id'], $password );
		}

		// Passwort stimmt. Ist ein zweiter Faktor eingerichtet, gilt die Anmeldung
		// erst nach dessen Prüfung — bis dahin wird nur ein Vormerker gesetzt.
		if ( ! empty( $user['totp_enabled'] ) ) {
			Database::update( 'users', array( 'failed_attempts' => 0, 'locked_until' => null ), array( 'id' => (int) $user['id'] ) );

			Session::regenerate();
			Session::set( '_2fa_user', (int) $user['id'] );
			Session::set( '_2fa_since', time() );

			return self::CHALLENGE_REQUIRED;
		}

		self::login( $user, $request );

		return null;
	}

	/**
	 * Kennzeichnet, dass nach dem Passwort noch der zweite Faktor fehlt.
	 */
	public const CHALLENGE_REQUIRED = '__2fa__';

	/** Nach dieser Zeit ohne zweiten Faktor beginnt die Anmeldung von vorn. */
	private const CHALLENGE_TTL = 300;

	/**
	 * Wartet gerade eine Anmeldung auf den zweiten Faktor?
	 *
	 * @return array<string,mixed>|null
	 */
	public static function pendingUser(): ?array {
		$userId = (int) Session::get( '_2fa_user', 0 );
		$since  = (int) Session::get( '_2fa_since', 0 );

		if ( $userId <= 0 ) {
			return null;
		}

		if ( time() - $since > self::CHALLENGE_TTL ) {
			self::clearChallenge();
			return null;
		}

		$user = UserRepository::find( $userId );

		return ( null !== $user && ! empty( $user['is_active'] ) ) ? $user : null;
	}

	public static function clearChallenge(): void {
		Session::forget( '_2fa_user' );
		Session::forget( '_2fa_since' );
	}

	/**
	 * Prüft den zweiten Faktor und schliesst die Anmeldung ab.
	 * Gibt bei Erfolg null zurück, sonst die Fehlermeldung.
	 */
	public static function completeChallenge( string $code, Request $request ): ?string {
		$user = self::pendingUser();

		if ( null === $user ) {
			return 'Die Anmeldung ist abgelaufen. Bitte erneut mit E-Mail und Passwort beginnen.';
		}

		$code = trim( $code );

		if ( '' === $code ) {
			return 'Bitte den Code aus deiner Authenticator-App eingeben.';
		}

		$attempts = (int) Session::get( '_2fa_tries', 0 ) + 1;
		Session::set( '_2fa_tries', $attempts );

		if ( $attempts > 6 ) {
			self::clearChallenge();
			Session::forget( '_2fa_tries' );

			ActivityRepository::log(
				'auth.2fa_blocked',
				sprintf( 'Zu viele falsche 2FA-Codes für %s.', $user['email'] ),
				array( 'level' => 'warning', 'user_id' => (int) $user['id'] )
			);

			return 'Zu viele Fehlversuche. Bitte erneut anmelden.';
		}

		$secret = UserRepository::totpSecret( $user );
		$valid  = '' !== $secret && Totp::verify( $secret, $code );
		$viaRecovery = false;

		if ( ! $valid ) {
			$valid       = UserRepository::consumeRecoveryCode( $user, $code );
			$viaRecovery = $valid;
		}

		if ( ! $valid ) {
			return 'Der Code stimmt nicht. Prüfe die Uhrzeit deines Geräts oder nimm einen Ersatzcode.';
		}

		self::clearChallenge();
		Session::forget( '_2fa_tries' );

		self::login( $user, $request );

		if ( $viaRecovery ) {
			$left = UserRepository::recoveryCodesLeft( UserRepository::find( (int) $user['id'] ) ?? $user );

			ActivityRepository::log(
				'auth.recovery_used',
				sprintf( 'Ersatzcode verwendet. Noch %d übrig.', $left ),
				array( 'level' => 'warning', 'user_id' => (int) $user['id'] )
			);

			Session::flash(
				'warning',
				sprintf( 'Mit Ersatzcode angemeldet. Es sind noch %d Ersatzcodes übrig.', $left )
			);
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $user
	 */
	public static function login( array $user, Request $request ): void {
		Session::regenerate();
		Session::set( 'user_id', (int) $user['id'] );

		$ttl = max( 900, (int) Config::get( 'security.session_ttl', 43200 ) );

		Database::insert(
			'sessions',
			array(
				'id'         => hash( 'sha256', Session::id() ),
				'user_id'    => (int) $user['id'],
				'ip'         => $request->ip(),
				'user_agent' => $request->userAgent(),
				'created_at' => nl_utc(),
				'expires_at' => nl_utc( $ttl ),
			)
		);

		Database::update(
			'users',
			array(
				'last_login_at'   => nl_utc(),
				'last_login_ip'   => $request->ip(),
				'failed_attempts' => 0,
				'locked_until'    => null,
			),
			array( 'id' => (int) $user['id'] )
		);

		self::$user     = $user;
		self::$resolved = true;

		ActivityRepository::log( 'auth.login', sprintf( '%s hat sich angemeldet.', $user['email'] ), array( 'user_id' => (int) $user['id'] ) );
	}

	public static function logout(): void {
		$user = self::$user;

		Database::delete( 'sessions', array( 'id' => hash( 'sha256', Session::id() ) ) );

		if ( $user ) {
			ActivityRepository::log( 'auth.logout', sprintf( '%s hat sich abgemeldet.', $user['email'] ), array( 'user_id' => (int) $user['id'] ) );
		}

		Session::destroy();

		self::$user     = null;
		self::$resolved = true;
	}

	/**
	 * Alle Sitzungen eines Benutzers beenden.
	 */
	public static function logoutEverywhere( int $userId ): void {
		Database::delete( 'sessions', array( 'user_id' => $userId ) );
	}

	/**
	 * Abgelaufene Sitzungen entfernen.
	 */
	public static function pruneSessions(): int {
		return Database::run(
			'DELETE FROM `' . Database::table( 'sessions' ) . '` WHERE `expires_at` < :now',
			array( 'now' => nl_utc() )
		)->rowCount();
	}

	/**
	 * @param array<string,mixed> $user
	 */
	private static function registerFailure( array $user ): void {
		$attempts = (int) $user['failed_attempts'] + 1;
		$max      = max( 3, (int) Config::get( 'security.login_attempts', 5 ) );
		$lockout  = max( 60, (int) Config::get( 'security.lockout_time', 900 ) );

		Database::update(
			'users',
			array(
				'failed_attempts' => $attempts,
				'locked_until'    => $attempts >= $max ? nl_utc( $lockout ) : null,
			),
			array( 'id' => (int) $user['id'] )
		);

		if ( $attempts >= $max ) {
			ActivityRepository::log(
				'auth.locked',
				sprintf( 'Konto %s nach %d Fehlversuchen gesperrt.', $user['email'], $attempts ),
				array( 'level' => 'warning' )
			);
		}
	}

	/* -------------------------------------------------- Sichtbare Seiten */

	/**
	 * Auf welche Seiten darf das angemeldete Konto sehen?
	 *
	 * null bedeutet: alle. Ein Array schränkt auf genau diese IDs ein —
	 * ein leeres Array heisst also "keine einzige".
	 *
	 * @return array<int,int>|null
	 */
	public static function visibleSiteIds(): ?array {
		$user = self::user();

		if ( null === $user || 'admin' === $user['role'] ) {
			return null;
		}

		if ( 'assigned' !== ( $user['site_access'] ?? 'all' ) ) {
			return null;
		}

		return UserRepository::siteIds( (int) $user['id'] );
	}

	public static function seesAllSites(): bool {
		return null === self::visibleSiteIds();
	}

	public static function canSeeSite( int $siteId ): bool {
		$visible = self::visibleSiteIds();

		return null === $visible || in_array( $siteId, $visible, true );
	}

	/**
	 * Bricht ab, wenn die Seite für dieses Konto nicht freigegeben ist.
	 */
	public static function requireSite( int $siteId ): void {
		self::requireLogin();

		if ( ! self::canSeeSite( $siteId ) ) {
			Response::forbidden( 'Diese Seite ist deinem Konto nicht zugeordnet.' );
			exit;
		}
	}

	/**
	 * Bricht ab, wenn keine Anmeldung vorliegt.
	 */
	public static function requireLogin(): void {
		if ( ! self::check() ) {
			Session::set( '_intended', $_SERVER['REQUEST_URI'] ?? '/' );
			Response::redirect( '/login' );
		}
	}

	public static function requireWrite(): void {
		self::requireLogin();
		if ( ! self::canWrite() ) {
			Response::forbidden( 'Dein Konto hat nur Leserechte.' );
			exit;
		}
	}

	public static function requireAdmin(): void {
		self::requireLogin();
		if ( ! self::isAdmin() ) {
			Response::forbidden( 'Dieser Bereich ist Administratoren vorbehalten.' );
			exit;
		}
	}
}
