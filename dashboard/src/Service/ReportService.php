<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Config;
use NorthLab\Core\Crypto;
use NorthLab\Core\Mailer;
use NorthLab\Core\Setting;
use NorthLab\Core\View;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\ClientRepository;
use NorthLab\Repository\ReportRepository;
use NorthLab\Repository\SiteRepository;
use NorthLab\Repository\UptimeRepository;

/**
 * Kundenberichte: Kennzahlen sammeln, HTML erzeugen, per Webhook und E-Mail ausliefern.
 */
final class ReportService {

	/**
	 * @return array{0:int,1:string|null} Bericht-ID und Fehlermeldung.
	 */
	public static function generate( ?int $clientId, string $from, string $to, bool $deliver = true ): array {
		$client = $clientId ? ClientRepository::find( $clientId ) : null;

		if ( $clientId && null === $client ) {
			return array( 0, 'Kunde nicht gefunden.' );
		}

		$sites = SiteRepository::all( $clientId ? array( 'client_id' => $clientId ) : array() );

		if ( ! $sites ) {
			return array( 0, 'Für diesen Bericht sind keine Seiten hinterlegt.' );
		}

		$payload = self::buildPayload( $client, $sites, $from, $to );
		$html    = self::renderHtml( $payload );

		$title = sprintf(
			'%s — %s bis %s',
			$client ? (string) $client['name'] : 'Alle Seiten',
			nl_date( $from, 'd.m.Y' ),
			nl_date( $to, 'd.m.Y' )
		);

		$reportId = ReportRepository::insert(
			array(
				'client_id'    => $clientId ?: null,
				'title'        => $title,
				'period_start' => $from,
				'period_end'   => $to,
				'payload'      => json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'html'         => $html,
				'status'       => 'generated',
				'created_at'   => nl_utc(),
			)
		);

		$payload['report_id']  = $reportId;
		$payload['report_url'] = rtrim( (string) Config::get( 'app.url', '' ), '/' ) . '/reports/' . $reportId;

		EventBus::dispatch( 'report.generated', $payload, array( 'message' => 'Bericht erstellt: ' . $title ) );

		if ( $deliver && null !== $client ) {
			self::deliverToClient( $client, $payload, $html, $title );
			ClientRepository::markReported( (int) $client['id'] );
		}

		return array( $reportId, null );
	}

	/**
	 * @param array<string,mixed>|null      $client
	 * @param array<int,array<string,mixed>> $sites
	 * @return array<string,mixed>
	 */
	public static function buildPayload( ?array $client, array $sites, string $from, string $to ): array {
		$siteReports    = array();
		$totalUpdates   = 0;
		$totalDowntime  = 0;
		$totalIncidents = 0;
		$uptimeSum      = 0.0;
		$pendingSum     = 0;
		$securitySum    = 0;

		foreach ( $sites as $site ) {
			$siteId = (int) $site['id'];

			$availability = UptimeRepository::availability( $siteId, $from, $to );
			$incidents    = UptimeRepository::incidents( $siteId, $from, $to );
			$applied      = self::appliedUpdates( $siteId, $from, $to );
			$payload      = SiteRepository::payload( $siteId );

			$totalUpdates   += count( $applied );
			$totalDowntime  += $availability['downtime_seconds'];
			$totalIncidents += count( $incidents );
			$uptimeSum      += $availability['percent'];
			$pendingSum     += (int) $site['pending_updates'];
			$securitySum    += (int) $site['security_score'];

			$siteReports[] = array(
				'id'              => $siteId,
				'name'            => (string) $site['name'],
				'url'             => (string) $site['url'],
				'status'          => (string) $site['status'],
				'uptime_status'   => (string) $site['uptime_status'],
				'wp_version'      => (string) $site['wp_version'],
				'php_version'     => (string) $site['php_version'],
				'security_score'  => (int) $site['security_score'],
				'pending_updates' => (int) $site['pending_updates'],
				'availability'    => $availability,
				'incidents'       => $incidents,
				'updates_applied' => $applied,
				'security_issues' => self::securityIssues( $payload ),
				'maintenance'     => self::maintenanceRuns( $siteId, $from, $to ),
			);
		}

		$count = max( 1, count( $sites ) );

		return array(
			'generated_at' => gmdate( 'c' ),
			'agency'       => array(
				'name'  => Setting::get( 'agency_name', 'NorthLab' ),
				'email' => Setting::get( 'agency_email', '' ),
				'logo'  => Setting::get( 'agency_logo_url', '' ),
				'color' => Setting::get( 'agency_color', '#2f6df6' ),
			),
			'client'       => $client ? array(
				'id'      => (int) $client['id'],
				'name'    => (string) $client['name'],
				'contact' => (string) $client['contact_name'],
				'email'   => (string) $client['email'],
			) : null,
			'period'       => array(
				'start' => $from,
				'end'   => $to,
				'days'  => (int) round( ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / 86400 ),
			),
			'summary'      => array(
				'sites'            => count( $sites ),
				'updates_applied'  => $totalUpdates,
				'pending_updates'  => $pendingSum,
				'avg_uptime'       => round( $uptimeSum / $count, 3 ),
				'downtime_seconds' => $totalDowntime,
				'incidents'        => $totalIncidents,
				'avg_security'     => (int) round( $securitySum / $count ),
			),
			'sites'        => $siteReports,
		);
	}

	/**
	 * Eingespielte Updates aus dem Aktivitätsprotokoll.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function appliedUpdates( int $siteId, string $from, string $to ): array {
		$entries = ActivityRepository::query(
			array(
				'site_id' => $siteId,
				'action'  => 'update.applied',
				'since'   => $from,
				'until'   => $to,
				'limit'   => 500,
			)
		);

		$applied = array();

		foreach ( $entries as $entry ) {
			$context = json_decode( (string) $entry['context'], true );

			foreach ( (array) ( $context['items'] ?? array() ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$applied[] = array(
					'type'         => (string) ( $item['type'] ?? '' ),
					'name'         => (string) ( $item['name'] ?? $item['slug'] ?? '' ),
					'from_version' => (string) ( $item['from_version'] ?? '' ),
					'to_version'   => (string) ( $item['to_version'] ?? '' ),
					'at'           => (string) $entry['created_at'],
				);
			}
		}

		return $applied;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function maintenanceRuns( int $siteId, string $from, string $to ): array {
		$runs = array();

		foreach ( ActivityRepository::query(
			array( 'site_id' => $siteId, 'action' => 'maintenance.done', 'since' => $from, 'until' => $to, 'limit' => 100 )
		) as $entry ) {
			$context = json_decode( (string) $entry['context'], true );

			$runs[] = array(
				'at'      => (string) $entry['created_at'],
				'tasks'   => (array) ( $context['results'] ?? array() ),
				'message' => (string) $entry['message'],
			);
		}

		return $runs;
	}

	/**
	 * Offene Sicherheitspunkte aus dem letzten Scan.
	 *
	 * @param array<string,mixed>|null $payload
	 * @return array<int,array<string,string>>
	 */
	private static function securityIssues( ?array $payload ): array {
		$issues = array();

		foreach ( (array) ( $payload['security']['checks'] ?? array() ) as $check ) {
			if ( ! is_array( $check ) || 'ok' === ( $check['status'] ?? 'ok' ) ) {
				continue;
			}
			$issues[] = array(
				'label'    => (string) ( $check['label'] ?? '' ),
				'status'   => (string) ( $check['status'] ?? '' ),
				'severity' => (string) ( $check['severity'] ?? 'low' ),
				'detail'   => (string) ( $check['detail'] ?? '' ),
			);
		}

		return $issues;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public static function renderHtml( array $payload ): string {
		return View::capture( 'report/document', array( 'report' => $payload ) );
	}

	/**
	 * @param array<string,mixed> $client
	 * @param array<string,mixed> $payload
	 */
	private static function deliverToClient( array $client, array $payload, string $html, string $title ): void {
		$webhookUrl = trim( (string) $client['report_webhook_url'] );

		if ( '' !== $webhookUrl ) {
			$secret = (string) $client['report_webhook_secret'];
			if ( '' === $secret ) {
				$secret = Crypto::secret( 16 );
			}

			$response = WebhookService::send(
				$webhookUrl,
				$secret,
				'report.generated',
				(string) json_encode(
					array(
						'event'       => 'report.generated',
						'occurred_at' => gmdate( 'c' ),
						'message'     => $title,
						'data'        => $payload,
					),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				)
			);

			ActivityRepository::log(
				'report.webhook',
				$response['ok']
					? sprintf( 'Bericht-Webhook an %s: HTTP %d', $client['name'], $response['status'] )
					: sprintf( 'Bericht-Webhook an %s fehlgeschlagen: %s', $client['name'], $response['error'] ?: 'HTTP ' . $response['status'] ),
				array( 'level' => $response['ok'] ? 'info' : 'error' )
			);
		}

		$email = trim( (string) $client['email'] );

		if ( ! empty( $client['report_email_enabled'] ) && '' !== $email ) {
			$sent = Mailer::send(
				$email,
				sprintf( '%s — Wartungsbericht %s', Setting::get( 'agency_name', 'NorthLab' ), $title ),
				$html
			);

			ActivityRepository::log(
				'report.email',
				$sent
					? sprintf( 'Bericht per E-Mail an %s verschickt.', $email )
					: sprintf( 'Bericht-E-Mail an %s fehlgeschlagen.', $email ),
				array( 'level' => $sent ? 'info' : 'error' )
			);
		}
	}

	/**
	 * Fällige Kundenberichte erzeugen (stündlich per Cron).
	 */
	public static function runScheduled(): int {
		if ( (int) date( 'G' ) !== Setting::getInt( 'report_send_hour', 8 ) ) {
			return 0;
		}

		$created = 0;

		foreach ( ClientRepository::dueForReport() as $client ) {
			$days = ClientRepository::periodDays( (string) $client['report_frequency'] );

			[ $id ] = self::generate(
				(int) $client['id'],
				gmdate( 'Y-m-d H:i:s', time() - $days * 86400 ),
				nl_utc()
			);

			if ( $id > 0 ) {
				$created++;
			}
		}

		return $created;
	}
}
