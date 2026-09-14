<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

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
}
