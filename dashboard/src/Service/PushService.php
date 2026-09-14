<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Auth;
use NorthLab\Core\Config;
use NorthLab\Core\Crypto;
use NorthLab\Core\Logger;
use NorthLab\Core\Setting;
use NorthLab\Core\WebPush;
use NorthLab\Repository\PushRepository;
use NorthLab\Repository\UserRepository;
use Throwable;

/**
 * Push-Meldungen auf die Geräte der Panel-Benutzer.
 *
 * Wer welche Meldung bekommt, richtet sich nach den Seiten, die dem Konto
 * zugeordnet sind — eine Meldung über eine fremde Kundenseite geht auch aufs
 * Handy nicht raus.
 */
final class PushService {

	/**
	 * Ist Push überhaupt eingerichtet?
	 */
	public static function ready(): bool {
		$keys = self::keys();

		return '' !== $keys['public'] && '' !== $keys['private'];
	}

	/**
	 * VAPID-Schlüsselpaar. Der private Teil liegt verschlüsselt.
	 *
	 * @return array{public:string,private:string,subject:string}
	 */
	public static function keys(): array {
		$stored = (string) Setting::get( 'push_private_key', '' );

		return array(
			'public'  => (string) Setting::get( 'push_public_key', '' ),
			'private' => '' === $stored ? '' : Crypto::decrypt( $stored ),
			'subject' => self::subject(),
		);
	}

	/**
	 * Wer der Absender ist — die Push-Dienste bestehen darauf.
	 */
	private static function subject(): string {
		$email = trim( (string) Setting::get( 'agency_email', '' ) );

		if ( '' !== $email && filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return 'mailto:' . $email;
		}

		$url = rtrim( (string) Config::get( 'app.url', '' ), '/' );

		return '' !== $url ? $url : 'mailto:admin@localhost';
	}

	/**
	 * Erzeugt ein Schlüsselpaar, falls noch keines da ist.
	 *
	 * @return string Der öffentliche Schlüssel.
	 */
	public static function ensureKeys(): string {
		if ( self::ready() ) {
			return (string) Setting::get( 'push_public_key', '' );
		}

		$keys = WebPush::generateKeys();

		Setting::setMany(
			array(
				'push_public_key'  => $keys['public'],
				'push_private_key' => Crypto::encrypt( $keys['private'] ),
			)
		);

		return $keys['public'];
	}

	/**
	 * Ein neues Schlüsselpaar macht alle bestehenden Abonnements wertlos —
	 * die Geräte haben den alten öffentlichen Schlüssel gespeichert.
	 */
	public static function rotateKeys(): string {
		$keys = WebPush::generateKeys();

		Setting::setMany(
			array(
				'push_public_key'  => $keys['public'],
				'push_private_key' => Crypto::encrypt( $keys['private'] ),
			)
		);

		foreach ( PushRepository::all() as $subscription ) {
			PushRepository::delete( (int) $subscription['id'] );
		}

		return $keys['public'];
	}

	/**
	 * Meldung an alle Geräte eines Kontos.
	 *
	 * @param array<string,mixed> $payload title, body, url, tag
	 * @return array{sent:int,failed:int,removed:int}
	 */
	public static function toUser( int $userId, array $payload ): array {
		return self::deliver( PushRepository::forUser( $userId ), $payload );
	}

	/**
	 * Meldung zu einem Ereignis — an alle, die die betroffene Seite sehen dürfen.
	 *
	 * @param array<string,mixed> $envelope
	 * @return array{sent:int,failed:int,removed:int}
	 */
	public static function forEvent( string $event, array $envelope ): array {
		$total = array( 'sent' => 0, 'failed' => 0, 'removed' => 0 );

		if ( ! self::ready() ) {
			return $total;
		}

		if ( ! in_array( $event, Setting::getArray( 'push_on', array() ), true ) ) {
			return $total;
		}

		$siteId  = (int) ( $envelope['site']['id'] ?? 0 );
		$payload = array(
			'title' => EventBus::describe( $event ),
			'body'  => (string) ( $envelope['message'] ?? '' ),
			'url'   => $siteId > 0 ? '/sites/' . $siteId : '/',
			// Gleiche Seite, gleiches Ereignis: die neue Meldung ersetzt die alte,
			// statt den Sperrbildschirm zuzupflastern.
			'tag'   => $event . ':' . $siteId,
			'event' => $event,
		);

		foreach ( PushRepository::all() as $subscription ) {
			$userId = (int) $subscription['user_id'];

			if ( $siteId > 0 && ! self::maySee( $userId, $siteId ) ) {
				continue;
			}

			$result           = self::deliver( array( $subscription ), $payload );
			$total['sent']   += $result['sent'];
			$total['failed'] += $result['failed'];
			$total['removed'] += $result['removed'];
		}

		return $total;
	}

	/**
	 * Darf dieses Konto die Seite sehen?
	 *
	 * Auth::canSeeSite bezieht sich auf das angemeldete Konto; hier geht es um
	 * ein beliebiges, deshalb der Weg über das Repository.
	 */
	private static function maySee( int $userId, int $siteId ): bool {
		$user = UserRepository::find( $userId );

		if ( null === $user ) {
			return false;
		}

		$visible = UserRepository::siteIds( $userId );

		// Kein Eintrag heisst "alle Seiten" — so ist es auch in der Oberflaeche.
		return array() === $visible || in_array( $siteId, $visible, true );
	}

	/**
	 * @param array<int,array<string,mixed>> $subscriptions
	 * @param array<string,mixed>            $payload
	 * @return array{sent:int,failed:int,removed:int}
	 */
	public static function deliver( array $subscriptions, array $payload ): array {
		$result = array( 'sent' => 0, 'failed' => 0, 'removed' => 0 );

		if ( ! self::ready() ) {
			return $result;
		}

		$keys = self::keys();

		foreach ( $subscriptions as $subscription ) {
			try {
				$response = WebPush::send(
					array(
						'endpoint' => (string) $subscription['endpoint'],
						'p256dh'   => (string) $subscription['p256dh'],
						'auth'     => (string) $subscription['auth'],
					),
					$payload,
					$keys
				);
			} catch ( Throwable $e ) {
				Logger::warning( 'Push fehlgeschlagen: ' . $e->getMessage() );
				$response = array( 'status' => 0, 'error' => $e->getMessage() );
			}

			$status = (int) $response['status'];

			if ( $status >= 200 && $status < 300 ) {
				PushRepository::markSent( (int) $subscription['id'] );
				$result['sent']++;
				continue;
			}

			// 404 und 410 heissen: dieses Geraet gibt es nicht mehr. Sofort weg,
			// sonst laeuft jede kuenftige Meldung in denselben Fehler.
			$gone = in_array( $status, array( 404, 410 ), true );

			if ( PushRepository::markFailed( (int) $subscription['id'], $gone ) ) {
				$result['removed']++;
			} else {
				$result['failed']++;
			}

			if ( ! $gone ) {
				Logger::warning(
					sprintf( 'Push abgelehnt (HTTP %d): %s', $status, (string) $response['error'] )
				);
			}
		}

		return $result;
	}

	/**
	 * Probemeldung an das angemeldete Konto.
	 *
	 * @return array{sent:int,failed:int,removed:int}
	 */
	public static function test(): array {
		return self::toUser(
			Auth::id(),
			array(
				'title' => Setting::get( 'agency_name', 'NorthLab' ),
				'body'  => 'Probemeldung — wenn du das liest, kommen Meldungen an.',
				'url'   => '/settings',
				'tag'   => 'test',
			)
		);
	}
}
