<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Repository\SiteRepository;

/**
 * Prüft vorab, ob eine Kundenseite eine Funktion überhaupt anbieten kann.
 *
 * Ohne das bekommt man bei einer zu alten Child-Version ein nacktes „HTTP 404"
 * und bei einer abgeschalteten Freigabe ein nacktes „Zugriff verweigert" — beides
 * sagt nicht, was zu tun ist. Hier steht, woran es liegt, bevor überhaupt eine
 * Anfrage rausgeht.
 */
final class ChildFeature {

	/**
	 * Kennung => [ab welcher Child-Version, Freigabe-Schalter, Anzeigename].
	 *
	 * @var array<string,array{min:string,flag:string,label:string}>
	 */
	public const FEATURES = array(
		'users'      => array( 'min' => '1.0.0', 'flag' => 'allow_user_mgmt', 'label' => 'Die Benutzerverwaltung' ),
		'autologin'  => array( 'min' => '1.2.0', 'flag' => 'allow_autologin', 'label' => 'Die Ein-Klick-Anmeldung' ),
		'mmode'      => array( 'min' => '1.2.0', 'flag' => 'allow_mmode', 'label' => 'Der Wartungsmodus' ),
		'selfupdate' => array( 'min' => '1.3.0', 'flag' => 'allow_self_update', 'label' => 'Das Selbst-Update des Child-Plugins' ),
		'backup'     => array( 'min' => '1.0.0', 'flag' => 'allow_backup', 'label' => 'Die Sicherung' ),
		'updates'    => array( 'min' => '1.0.0', 'flag' => 'allow_updates', 'label' => 'Das Einspielen von Updates' ),
		'install'    => array( 'min' => '1.0.0', 'flag' => 'allow_install', 'label' => 'Das Installieren von Erweiterungen' ),
		'cleanup'    => array( 'min' => '1.0.0', 'flag' => 'allow_maintenance', 'label' => 'Die Wartungsaufgaben' ),
	);

	/** @var array<int,array<string,bool>> Einmal je Seite, nicht einmal je Funktion. */
	private static array $cache = array();

	/**
	 * Grund, warum die Funktion dort nicht läuft — oder null, wenn alles passt.
	 *
	 * @param array<string,mixed>     $site
	 * @param array<string,bool>|null $capabilities Bereits geladene Freigaben.
	 */
	public static function unavailable( array $site, string $feature, ?array $capabilities = null ): ?string {
		$spec = self::FEATURES[ $feature ] ?? null;

		if ( null === $spec ) {
			return null;
		}

		if ( ! SiteRepository::isManaged( $site ) ) {
			return 'Diese Seite wird nur überwacht — dort läuft kein Child-Plugin.';
		}

		$installed = trim( (string) ( $site['child_version'] ?? '' ) );

		// Unbekannte Version heisst nicht "zu alt": erst einmal probieren lassen.
		if ( '' !== $installed && version_compare( $installed, $spec['min'], '<' ) ) {
			return sprintf(
				'%s braucht das Child-Plugin %s, auf dieser Seite läuft %s. '
					. 'Bitte dort einmal das aktuelle Plugin einspielen — danach geht es aus der Ferne.',
				$spec['label'],
				$spec['min'],
				$installed
			);
		}

		$capabilities ??= self::capabilities( (int) $site['id'] );

		// Nur widersprechen, wenn die Seite den Schalter wirklich gemeldet hat.
		if ( array_key_exists( $spec['flag'], $capabilities ) && false === $capabilities[ $spec['flag'] ] ) {
			return sprintf(
				'%s ist auf dieser Seite abgeschaltet. Sie lässt sich dort unter '
					. 'Einstellungen → NorthLab wieder freigeben.',
				$spec['label']
			);
		}

		return null;
	}

	/**
	 * Freigaben, wie sie die Kundenseite zuletzt gemeldet hat.
	 *
	 * @return array<string,bool>
	 */
	public static function capabilities( int $siteId ): array {
		if ( isset( self::$cache[ $siteId ] ) ) {
			return self::$cache[ $siteId ];
		}

		$payload = SiteRepository::payload( $siteId );
		$flags   = (array) ( $payload['capabilities'] ?? array() );
		$out     = array();

		foreach ( $flags as $key => $value ) {
			$out[ (string) $key ] = (bool) $value;
		}

		self::$cache[ $siteId ] = $out;

		return $out;
	}

	/**
	 * Nur für Tests und nach einem Sync, der neue Freigaben gebracht hat.
	 */
	public static function forget( ?int $siteId = null ): void {
		if ( null === $siteId ) {
			self::$cache = array();
			return;
		}

		unset( self::$cache[ $siteId ] );
	}

	/**
	 * Alle Funktionen mit ihrem Zustand — für die Anzeige auf der Seitendetailansicht.
	 *
	 * @param array<string,mixed> $site
	 * @return array<int,array{key:string,label:string,ok:bool,reason:?string}>
	 */
	public static function overview( array $site ): array {
		$out = array();

		// Einmal laden und durchreichen — sonst fragt jede Funktion die Datenbank neu.
		$capabilities = self::capabilities( (int) $site['id'] );

		foreach ( array_keys( self::FEATURES ) as $feature ) {
			$reason = self::unavailable( $site, $feature, $capabilities );

			$out[] = array(
				'key'    => $feature,
				'label'  => self::FEATURES[ $feature ]['label'],
				'ok'     => null === $reason,
				'reason' => $reason,
			);
		}

		return $out;
	}
}
