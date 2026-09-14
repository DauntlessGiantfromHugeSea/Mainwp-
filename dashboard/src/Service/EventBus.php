<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Config;
use NorthLab\Core\Mailer;
use NorthLab\Core\Logger;
use NorthLab\Core\Setting;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;

/**
 * Zentraler Ereignisverteiler: Protokoll, Webhook-Warteschlange, E-Mail-Hinweis.
 */
final class EventBus {

	/**
	 * Alle Ereignisse, die abonniert werden können.
	 *
	 * @return array<string,string>
	 */
	public static function catalog(): array {
		return array(
			'site.connected'    => 'Seite verbunden',
			'site.disconnected' => 'Seite entfernt',
			'site.offline'      => 'Seite offline',
			'site.online'       => 'Seite wieder online',
			'sync.failed'       => 'Synchronisierung fehlgeschlagen',
			'updates.available' => 'Neue Updates erkannt',
			'update.applied'    => 'Update eingespielt',
			'update.failed'     => 'Update fehlgeschlagen',
			'security.changed'  => 'Sicherheitsbewertung verschlechtert',
			'maintenance.done'  => 'Wartung abgeschlossen',
			'mmode.on'          => 'Wartungsmodus eingeschaltet',
			'mmode.off'         => 'Wartungsmodus beendet',
			'site.autologin'    => 'Ein-Klick-Anmeldung benutzt',
			'child.updated'     => 'Child-Plugin aktualisiert',
			'backup.completed'  => 'Sicherung abgeschlossen',
			'backup.failed'     => 'Sicherung fehlgeschlagen',
			'report.generated'  => 'Bericht erstellt',
			'snapshot.full'     => 'Gesamtstand (Vollsynchronisation)',
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $args site_id, level, message
	 */
	public static function dispatch( string $event, array $payload = array(), array $args = array() ): void {
		$siteId  = (int) ( $args['site_id'] ?? 0 );
		$message = (string) ( $args['message'] ?? self::describe( $event ) );
		$level   = (string) ( $args['level'] ?? self::defaultLevel( $event ) );

		ActivityRepository::log(
			$event,
			$message,
			array(
				'site_id' => $siteId ?: null,
				'level'   => $level,
				'context' => $payload,
			)
		);

		$envelope = array(
			'event'         => $event,
			'occurred_at'   => gmdate( 'c' ),
			'agency'        => Setting::get( 'agency_name', 'NorthLab' ),
			'dashboard_url' => rtrim( (string) Config::get( 'app.url', '' ), '/' ),
			'message'       => $message,
			'data'          => $payload,
		);

		$clientId = 0;

		if ( $siteId > 0 ) {
			$site = SiteRepository::find( $siteId );
			if ( null !== $site ) {
				$clientId         = (int) ( $site['client_id'] ?? 0 );
				$envelope['site'] = array(
					'id'        => (int) $site['id'],
					'name'      => (string) $site['name'],
					'url'       => (string) $site['url'],
					'type'      => (string) ( $site['site_type'] ?? 'wordpress' ),
					'client_id' => $clientId ?: null,
				);
			}
		}

		WebhookService::enqueueForEvent( $event, $envelope, $clientId );

		self::maybeNotify( $event, $envelope );
		self::maybePush( $event, $envelope );
	}

	public static function describe( string $event ): string {
		return self::catalog()[ $event ] ?? $event;
	}

	private static function defaultLevel( string $event ): string {
		if ( in_array( $event, array( 'site.offline', 'sync.failed', 'update.failed', 'backup.failed' ), true ) ) {
			return 'error';
		}
		if ( in_array( $event, array( 'security.changed', 'updates.available' ), true ) ) {
			return 'warning';
		}
		return 'info';
	}

	/**
	 * Push an die Geraete der Benutzer.
	 *
	 * Getrennt von der E-Mail: ein Ereignis kann aufs Handy gehoeren, ohne
	 * gleich eine Mail wert zu sein. Ein Fehler beim Versand darf das
	 * ausloesende Ereignis nicht zum Absturz bringen.
	 *
	 * @param array<string,mixed> $envelope
	 */
	private static function maybePush( string $event, array $envelope ): void {
		try {
			PushService::forEvent( $event, $envelope );
		} catch ( \Throwable $e ) {
			Logger::warning( 'Push-Versand fehlgeschlagen: ' . $e->getMessage() );
		}
	}

	private static function maybeNotify( string $event, array $envelope ): void {
		$watched = Setting::getArray( 'notify_on', array() );
		if ( ! in_array( $event, $watched, true ) ) {
			return;
		}

		$to = trim( Setting::get( 'notify_email', '' ) );
		if ( '' === $to ) {
			$to = trim( Setting::get( 'agency_email', '' ) );
		}
		if ( '' === $to || ! filter_var( $to, FILTER_VALIDATE_EMAIL ) ) {
			return;
		}

		$siteName = (string) ( $envelope['site']['name'] ?? '—' );
		$subject  = sprintf( '[%s] %s: %s', Setting::get( 'agency_name', 'NorthLab' ), self::describe( $event ), $siteName );

		$rows = array(
			'Ereignis'  => $event,
			'Seite'     => $siteName,
			'URL'       => (string) ( $envelope['site']['url'] ?? '—' ),
			'Zeitpunkt' => (string) $envelope['occurred_at'],
		);

		$html = '<div style="font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;color:#1c2430">'
			. '<h2 style="margin:0 0 12px">' . e( self::describe( $event ) ) . '</h2>'
			. '<p style="margin:0 0 16px">' . e( (string) $envelope['message'] ) . '</p>'
			. '<table cellpadding="6" style="border-collapse:collapse;font-size:14px">';

		foreach ( $rows as $label => $value ) {
			$html .= '<tr><td style="color:#6b7684">' . e( $label ) . '</td><td><strong>' . e( $value ) . '</strong></td></tr>';
		}

		$html .= '</table><p style="margin-top:20px"><a href="' . e( url( '/' ) ) . '">Zum NorthLab Panel</a></p></div>';

		Mailer::send( $to, $subject, $html );
	}
}
