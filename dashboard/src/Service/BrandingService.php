<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Setting;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;

/**
 * Agentur-Branding auf den Kundenseiten schalten.
 *
 * Was die Agentur betrifft — Logo, Kontaktwege, Farben — steht zentral in den
 * Einstellungen. Was die Kundenseite betrifft — deren eigenes Logo auf der
 * Anmeldeseite — steht pro Seite, denn jeder Kunde hat ein anderes.
 */
final class BrandingService {

	/** Wer die Support-Leiste im Backend sieht. */
	public const AUDIENCES = array(
		'read'           => 'Alle angemeldeten Benutzer',
		'edit_posts'     => 'Redakteure und höher',
		'manage_options' => 'Nur Administratoren',
	);

	/**
	 * Zentrale Gestaltung aus den Einstellungen.
	 *
	 * @return array<string,mixed>
	 */
	public static function design(): array {
		return array(
			'logo'           => Setting::get( 'branding_logo', Setting::get( 'agency_logo_url', '' ) ),
			'site'           => Setting::get( 'branding_site', '' ),
			'email'          => Setting::get( 'branding_email', Setting::get( 'agency_email', '' ) ),
			'phone'          => Setting::get( 'branding_phone', '' ),
			'text'           => Setting::get( 'branding_text', 'Kontakt bei Fragen oder Problemen:' ),
			'login_bar_text' => Setting::get( 'branding_login_text', 'Betreut von' ),
			'capability'     => Setting::get( 'branding_capability', 'read' ),
			'accent'         => Setting::get( 'branding_accent', '#e8917a' ),
			'tint'           => Setting::get( 'branding_tint', '#fdf3f0' ),
		);
	}

	/**
	 * Zustand von der Kundenseite holen.
	 *
	 * @return array{ok:bool,state:array<string,mixed>,error:string}
	 */
	public static function fetch( int $siteId ): array {
		$site = self::allowed( $siteId );

		if ( is_string( $site ) ) {
			return array( 'ok' => false, 'state' => array(), 'error' => $site );
		}

		$response = ChildClient::post( $site, '/branding', array( 'action' => 'get' ) );

		if ( ! $response['ok'] ) {
			return array( 'ok' => false, 'state' => array(), 'error' => $response['error'] );
		}

		$state = (array) ( $response['data']['branding'] ?? array() );
		self::mirror( $siteId, $state );

		return array( 'ok' => true, 'state' => $state, 'error' => '' );
	}

	/**
	 * Branding setzen.
	 *
	 * @param array<string,mixed> $options bar, login_bar, login_logo, login_logo_height, login_logo_link
	 */
	public static function apply( int $siteId, array $options ): ?string {
		$site = self::allowed( $siteId );

		if ( is_string( $site ) ) {
			return $site;
		}

		$settings = array_merge(
			self::design(),
			array(
				'bar_enabled'       => ! empty( $options['bar'] ),
				'login_bar_enabled' => ! empty( $options['login_bar'] ),
				'login_logo'        => trim( (string) ( $options['login_logo'] ?? '' ) ),
				'login_logo_height' => (int) ( $options['login_logo_height'] ?? 72 ),
				'login_logo_link'   => trim( (string) ( $options['login_logo_link'] ?? '' ) ),
			)
		);

		$response = ChildClient::post( $site, '/branding', array( 'action' => 'set', 'settings' => $settings ) );

		if ( ! $response['ok'] ) {
			return $response['error'];
		}

		$state = (array) ( $response['data']['branding'] ?? array() );
		self::mirror( $siteId, $state );

		ActivityRepository::log(
			'site.branding',
			sprintf(
				'Branding gesetzt: Support-Leiste %s, Login-Leiste %s, Kundenlogo %s.',
				! empty( $state['bar_enabled'] ) ? 'an' : 'aus',
				! empty( $state['login_bar_enabled'] ) ? 'an' : 'aus',
				! empty( $state['login_logo'] ) ? 'gesetzt' : 'keins'
			),
			array( 'site_id' => $siteId )
		);

		return null;
	}

	/**
	 * Beide Leisten abschalten, das Kundenlogo aber stehen lassen.
	 */
	public static function disable( int $siteId ): ?string {
		$site = self::allowed( $siteId );

		if ( is_string( $site ) ) {
			return $site;
		}

		$response = ChildClient::post(
			$site,
			'/branding',
			array( 'action' => 'set', 'settings' => array( 'bar_enabled' => false, 'login_bar_enabled' => false ) )
		);

		if ( ! $response['ok'] ) {
			return $response['error'];
		}

		self::mirror( $siteId, (array) ( $response['data']['branding'] ?? array() ) );

		ActivityRepository::log( 'site.branding', 'Branding abgeschaltet.', array( 'site_id' => $siteId ) );

		return null;
	}

	/**
	 * Nur die beiden Schalter setzen — für Sammelaktionen, die das Kundenlogo
	 * jeder Seite unangetastet lassen müssen.
	 */
	public static function toggle( int $siteId, bool $bar, bool $loginBar ): ?string {
		$site = self::allowed( $siteId );

		if ( is_string( $site ) ) {
			return $site;
		}

		// Aus dem gespeicherten Bericht, nicht per Nachfrage: eine Sammelaktion
		// ueber 30 Seiten braucht sonst 60 Anfragen statt 30.
		$current = self::stored( $siteId );

		return self::apply(
			$siteId,
			array(
				'bar'               => $bar,
				'login_bar'         => $loginBar,
				'login_logo'        => (string) ( $current['login_logo'] ?? '' ),
				'login_logo_height' => (int) ( $current['login_logo_height'] ?? 72 ),
				'login_logo_link'   => (string) ( $current['login_logo_link'] ?? '' ),
			)
		);
	}

	/* ------------------------------------------------------------- Intern */

	/**
	 * @param array<string,mixed> $state
	 */
	private static function mirror( int $siteId, array $state ): void {
		SiteRepository::update(
			$siteId,
			array(
				'branding_bar'   => ! empty( $state['bar_enabled'] ) ? 1 : 0,
				'branding_login' => ! empty( $state['login_bar_enabled'] ) || ! empty( $state['login_logo'] ) ? 1 : 0,
			)
		);

		SiteRepository::patchPayload( $siteId, 'branding', $state );
	}

	/**
	 * Stand, wie ihn die Kundenseite zuletzt gemeldet hat — ohne neue Anfrage.
	 *
	 * @return array<string,mixed>
	 */
	public static function stored( int $siteId ): array {
		$payload = SiteRepository::payload( $siteId );

		return (array) ( $payload['branding'] ?? array() );
	}

	/**
	 * @return array<string,mixed>|string
	 */
	private static function allowed( int $siteId ) {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return 'Seite nicht gefunden.';
		}

		return ChildFeature::unavailable( $site, 'branding' ) ?? $site;
	}
}
