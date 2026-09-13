<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

/**
 * Key-Value-Einstellungen in der Datenbank.
 */
final class Setting {

	/** @var array<string,string>|null */
	private static ?array $cache = null;

	/**
	 * @return array<string,string>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			self::$cache = array();
			foreach ( Database::select( 'SELECT `name`, `value` FROM `' . Database::table( 'settings' ) . '`' ) as $row ) {
				self::$cache[ (string) $row['name'] ] = (string) $row['value'];
			}
		}
		return self::$cache;
	}

	public static function get( string $name, string $default = '' ): string {
		$all = self::all();
		return array_key_exists( $name, $all ) ? $all[ $name ] : $default;
	}

	public static function getInt( string $name, int $default = 0 ): int {
		$value = self::get( $name, (string) $default );
		return '' === $value ? $default : (int) $value;
	}

	public static function getBool( string $name, bool $default = false ): bool {
		$value = self::get( $name, $default ? '1' : '0' );
		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * @return array<int|string,mixed>
	 */
	public static function getArray( string $name, array $default = array() ): array {
		$raw = self::get( $name, '' );
		if ( '' === $raw ) {
			return $default;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : $default;
	}

	public static function set( string $name, string|int|bool|array $value ): void {
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		} elseif ( is_array( $value ) ) {
			$value = (string) json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		Database::run(
			'INSERT INTO `' . Database::table( 'settings' ) . '` (`name`, `value`) VALUES (:name, :value)
			 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
			array( 'name' => $name, 'value' => (string) $value )
		);

		self::$cache[ $name ] = (string) $value;
	}

	/**
	 * @param array<string,string|int|bool|array> $values
	 */
	public static function setMany( array $values ): void {
		foreach ( $values as $name => $value ) {
			self::set( (string) $name, $value );
		}
	}

	public static function forget( string $name ): void {
		Database::delete( 'settings', array( 'name' => $name ) );
		unset( self::$cache[ $name ] );
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Standardwerte beim ersten Start.
	 */
	public static function seedDefaults(): void {
		$defaults = array(
			'agency_name'          => 'NorthLab',
			'agency_email'         => '',
			'agency_logo_url'      => 'https://north-flow.de/api/assets/9f7184443fbc433bbbbb552fd9c76809',
			'agency_color'         => '#f9907a',
			'sync_interval'        => '900',
			'heartbeat_enabled'    => '1',
			'heartbeat_interval'   => '300',
			'auto_update_policy'   => 'off',
			'auto_update_excludes' => '',
			'auto_update_window'   => '',
			'uptime_secret'        => '',
			'uptime_global_token'  => '',
			'api_token'            => '',
			'snapshot_daily'       => '1',
			'notify_email'         => '',
			'notify_on'            => json_encode( array( 'site.offline', 'sync.failed', 'update.failed' ) ),
			'report_default_freq'  => 'monthly',
			'report_send_hour'     => '8',
			'activity_retention'   => '180',
			'uptime_retention'     => '365',
		);

		$existing = self::all();

		foreach ( $defaults as $name => $value ) {
			if ( ! array_key_exists( $name, $existing ) ) {
				self::set( $name, (string) $value );
			}
		}
	}
}
