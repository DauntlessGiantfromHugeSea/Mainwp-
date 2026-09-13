<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Config;
use NorthLab\Core\Database;
use NorthLab\Core\Http;
use NorthLab\Core\Setting;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\WebhookRepository;

/**
 * Ausgehende Webhooks: Warteschlange, HMAC-Signatur, Wiederholungen mit Backoff.
 */
final class WebhookService {

	/** Wartezeit in Sekunden vor dem jeweils nächsten Versuch. */
	private const BACKOFF = array( 60, 300, 900, 3600, 10800 );

	private const MAX_ATTEMPTS = 5;

	/**
	 * Ereignis für alle passenden Webhooks einreihen.
	 *
	 * @param array<string,mixed> $envelope
	 */
	public static function enqueueForEvent( string $event, array $envelope, int $clientId = 0 ): void {
		foreach ( WebhookRepository::active() as $webhook ) {
			if ( ! in_array( $event, WebhookRepository::events( $webhook ), true ) ) {
				continue;
			}
			// Kundengebundene Webhooks erhalten nur Ereignisse dieses Kunden.
			if ( ! empty( $webhook['client_id'] ) && (int) $webhook['client_id'] !== $clientId ) {
				continue;
			}

			self::enqueue( (int) $webhook['id'], $event, $envelope );
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public static function enqueue( int $webhookId, string $event, array $payload ): int {
		$now = nl_utc();

		return Database::insert(
			'webhook_deliveries',
			array(
				'webhook_id'      => $webhookId,
				'event'           => $event,
				'payload'         => (string) json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'status'          => 'pending',
				'attempts'        => 0,
				'next_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
	}

	/**
	 * Fällige Zustellungen abarbeiten.
	 *
	 * @return array{sent:int,failed:int}
	 */
	public static function processQueue( int $batch = 25 ): array {
		$due = Database::select(
			'SELECT * FROM `' . Database::table( 'webhook_deliveries' ) . "`
			 WHERE `status` = 'pending' AND `next_attempt_at` <= :now
			 ORDER BY `next_attempt_at` ASC LIMIT " . max( 1, min( 100, $batch ) ),
			array( 'now' => nl_utc() )
		);

		$sent   = 0;
		$failed = 0;

		foreach ( $due as $delivery ) {
			if ( self::deliver( $delivery ) ) {
				$sent++;
			} else {
				$failed++;
			}
		}

		return array( 'sent' => $sent, 'failed' => $failed );
	}

	/**
	 * @param array<string,mixed> $delivery
	 */
	public static function deliver( array $delivery ): bool {
		$webhook = WebhookRepository::find( (int) $delivery['webhook_id'] );

		if ( null === $webhook ) {
			Database::delete( 'webhook_deliveries', array( 'id' => (int) $delivery['id'] ) );
			return false;
		}

		$attempts = (int) $delivery['attempts'] + 1;
		$payload  = (string) $delivery['payload'];

		$response = self::send( (string) $webhook['target_url'], (string) $webhook['secret'], (string) $delivery['event'], $payload );

		$success = $response['ok'];
		$body    = '' !== $response['error'] ? $response['error'] : substr( $response['body'], 0, 500 );
		$now     = nl_utc();

		if ( $success ) {
			$status = 'success';
			$next   = $now;
		} elseif ( $attempts >= self::MAX_ATTEMPTS ) {
			$status = 'failed';
			$next   = $now;
		} else {
			$status = 'pending';
			$next   = nl_utc( self::BACKOFF[ min( $attempts - 1, count( self::BACKOFF ) - 1 ) ] );
		}

		Database::update(
			'webhook_deliveries',
			array(
				'status'          => $status,
				'attempts'        => $attempts,
				'next_attempt_at' => $next,
				'response_code'   => $response['status'],
				'response_body'   => $body,
				'updated_at'      => $now,
			),
			array( 'id' => (int) $delivery['id'] )
		);

		Database::update(
			'webhooks',
			array(
				'last_delivery_at' => $now,
				'last_status'      => $success ? 'success' : ( 'failed' === $status ? 'failed' : 'retrying' ),
			),
			array( 'id' => (int) $webhook['id'] )
		);

		if ( 'failed' === $status ) {
			ActivityRepository::log(
				'webhook.failed',
				sprintf(
					'Webhook "%s" nach %d Versuchen aufgegeben (%s).',
					$webhook['name'],
					$attempts,
					$response['status'] ? 'HTTP ' . $response['status'] : 'Transportfehler'
				),
				array( 'level' => 'error', 'context' => array( 'webhook_id' => (int) $webhook['id'], 'response' => $body ) )
			);
		}

		return $success;
	}

	/**
	 * Signierter POST an ein Webhook-Ziel.
	 *
	 * Header:
	 *   X-NorthLab-Event      Ereignisname
	 *   X-NorthLab-Delivery   eindeutige ID dieses Versuchs
	 *   X-NorthLab-Timestamp  Unix-Zeit
	 *   X-NorthLab-Signature  sha256=HMAC(secret, "<timestamp>.<body>")
	 *
	 * @return array{ok:bool,status:int,body:string,error:string,ms:int,headers:array<string,string>}
	 */
	public static function send( string $url, string $secret, string $event, string $payload ): array {
		$timestamp = (string) time();

		return Http::postJson(
			$url,
			$payload,
			array(
				'X-NorthLab-Event'     => $event,
				'X-NorthLab-Delivery'  => bin2hex( random_bytes( 16 ) ),
				'X-NorthLab-Timestamp' => $timestamp,
				'X-NorthLab-Signature' => 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret ),
			),
			20,
			(bool) Config::get( 'http.verify_ssl', true )
		);
	}

	/**
	 * Testzustellung mit Beispieldaten.
	 *
	 * @return array{success:bool,code:int,message:string}
	 */
	public static function test( int $webhookId ): array {
		$webhook = WebhookRepository::find( $webhookId );

		if ( null === $webhook ) {
			return array( 'success' => false, 'code' => 0, 'message' => 'Webhook nicht gefunden.' );
		}

		$payload = (string) json_encode(
			array(
				'event'         => 'test.ping',
				'occurred_at'   => gmdate( 'c' ),
				'agency'        => Setting::get( 'agency_name', 'NorthLab' ),
				'dashboard_url' => rtrim( (string) Config::get( 'app.url', '' ), '/' ),
				'message'       => 'Testzustellung aus dem NorthLab Panel.',
				'data'          => array( 'ok' => true ),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		$response = self::send( (string) $webhook['target_url'], (string) $webhook['secret'], 'test.ping', $payload );

		if ( '' !== $response['error'] ) {
			return array( 'success' => false, 'code' => 0, 'message' => $response['error'] );
		}

		return array(
			'success' => $response['ok'],
			'code'    => $response['status'],
			'message' => sprintf( 'HTTP %d (%d ms)', $response['status'], $response['ms'] ),
		);
	}
}
