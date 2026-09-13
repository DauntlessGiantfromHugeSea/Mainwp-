<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

/**
 * Legt das Datenbankschema an und hält es aktuell.
 */
final class Migrator {

	public const SCHEMA_VERSION = 2;

	/**
	 * Alle Tabellen anlegen (idempotent).
	 */
	public static function migrate(): void {
		foreach ( self::statements() as $sql ) {
			Database::pdo()->exec( $sql );
		}

		self::upgradeColumns();

		Setting::set( 'schema_version', (string) self::SCHEMA_VERSION );
	}

	/**
	 * Spalten nachrüsten, die in späteren Versionen dazugekommen sind.
	 *
	 * CREATE TABLE IF NOT EXISTS lässt bestehende Tabellen unangetastet, deshalb
	 * werden neue Spalten hier einzeln geprüft und angelegt.
	 */
	private static function upgradeColumns(): void {
		$columns = array(
			// Schema 2: Zwei-Faktor-Anmeldung und Seitenzuordnung.
			'users' => array(
				'totp_secret'       => "VARCHAR(255) NOT NULL DEFAULT ''",
				'totp_enabled'      => 'TINYINT(1) NOT NULL DEFAULT 0',
				'totp_confirmed_at' => 'DATETIME NULL DEFAULT NULL',
				'recovery_codes'    => 'TEXT NULL',
				'site_access'       => "VARCHAR(10) NOT NULL DEFAULT 'all'",
			),
		);

		foreach ( $columns as $table => $definitions ) {
			foreach ( $definitions as $column => $definition ) {
				self::addColumn( $table, $column, $definition );
			}
		}
	}

	private static function addColumn( string $table, string $column, string $definition ): void {
		$name = Database::table( $table );

		$exists = Database::selectOne(
			'SELECT COLUMN_NAME FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
			array( 't' => $name, 'c' => $column )
		);

		if ( null === $exists ) {
			Database::pdo()->exec( sprintf( 'ALTER TABLE `%s` ADD COLUMN `%s` %s', $name, $column, $definition ) );
		}
	}

	public static function needsMigration(): bool {
		if ( ! Database::tableExists( 'settings' ) ) {
			return true;
		}
		return (int) Setting::get( 'schema_version', '0' ) < self::SCHEMA_VERSION;
	}

	/**
	 * @return array<int,string>
	 */
	private static function statements(): array {
		$p       = Database::prefix();
		$charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

		return array(

			"CREATE TABLE IF NOT EXISTS `{$p}settings` (
				`name` VARCHAR(191) NOT NULL,
				`value` LONGTEXT NULL,
				PRIMARY KEY (`name`)
			) {$charset}",

			"CREATE TABLE IF NOT EXISTS `{$p}users` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`email` VARCHAR(191) NOT NULL,
				`name` VARCHAR(191) NOT NULL DEFAULT '',
				`password_hash` VARCHAR(255) NOT NULL,
				`role` VARCHAR(20) NOT NULL DEFAULT 'member',
				`is_active` TINYINT(1) NOT NULL DEFAULT 1,
				`last_login_at` DATETIME NULL DEFAULT NULL,
				`last_login_ip` VARCHAR(45) NOT NULL DEFAULT '',
				`failed_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				`locked_until` DATETIME NULL DEFAULT NULL,
				`created_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `email` (`email`)
			) {$charset}",

			"CREATE TABLE IF NOT EXISTS `{$p}clients` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`name` VARCHAR(191) NOT NULL,
				`contact_name` VARCHAR(191) NOT NULL DEFAULT '',
				`email` VARCHAR(191) NOT NULL DEFAULT '',
				`report_frequency` VARCHAR(20) NOT NULL DEFAULT 'monthly',
				`report_webhook_url` VARCHAR(255) NOT NULL DEFAULT '',
				`report_webhook_secret` VARCHAR(191) NOT NULL DEFAULT '',
				`report_email_enabled` TINYINT(1) NOT NULL DEFAULT 1,
				`last_report_at` DATETIME NULL DEFAULT NULL,
				`notes` TEXT NULL,
				`created_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				KEY `name` (`name`)
			) {$charset}",

			"CREATE TABLE IF NOT EXISTS `{$p}sites` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`client_id` BIGINT UNSIGNED NULL DEFAULT NULL,
				`name` VARCHAR(191) NOT NULL,
				`url` VARCHAR(255) NOT NULL,
				`admin_url` VARCHAR(255) NOT NULL DEFAULT '',
				`connection_id` VARCHAR(64) NOT NULL DEFAULT '',
				`private_key` LONGTEXT NULL,
				`public_key` LONGTEXT NULL,
				`status` VARCHAR(20) NOT NULL DEFAULT 'pending',
				`monitor_token` VARCHAR(64) NOT NULL DEFAULT '',
				`last_sync_at` DATETIME NULL DEFAULT NULL,
				`last_seen_at` DATETIME NULL DEFAULT NULL,
				`last_error` TEXT NULL,
				`uptime_status` VARCHAR(20) NOT NULL DEFAULT 'unknown',
				`uptime_since` DATETIME NULL DEFAULT NULL,
				`wp_version` VARCHAR(32) NOT NULL DEFAULT '',
				`php_version` VARCHAR(32) NOT NULL DEFAULT '',
				`child_version` VARCHAR(32) NOT NULL DEFAULT '',
				`security_score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
				`pending_updates` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				`auto_update_policy` VARCHAR(20) NOT NULL DEFAULT 'inherit',
				`tags` VARCHAR(255) NOT NULL DEFAULT '',
				`notes` TEXT NULL,
				`verify_ssl` TINYINT(1) NOT NULL DEFAULT 1,
				`http_user` VARCHAR(191) NOT NULL DEFAULT '',
				`http_pass` VARCHAR(255) NOT NULL DEFAULT '',
				`is_paused` TINYINT(1) NOT NULL DEFAULT 0,
				`created_at` DATETIME NOT NULL,
				`updated_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `url` (`url`),
				KEY `client_id` (`client_id`),
				KEY `status` (`status`),
				KEY `monitor_token` (`monitor_token`)
			) {$charset}",

			"CREATE TABLE IF NOT EXISTS `{$p}site_data` (
				`site_id` BIGINT UNSIGNED NOT NULL,
				`payload` LONGTEXT NULL,
				`updated_at` DATETIME NOT NULL,
				PRIMARY KEY (`site_id`)
			) {$charset}",

			"CREATE TABLE IF NOT EXISTS `{$p}updates` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`site_id` BIGINT UNSIGNED NOT NULL,
				`type` VARCHAR(20) NOT NULL,
				`slug` VARCHAR(191) NOT NULL,
				`name` VARCHAR(191) NOT NULL DEFAULT '',
				`current_version` VARCHAR(32) NOT NULL DEFAULT '',
				`new_version` VARCHAR(32) NOT NULL DEFAULT '',
				`is_ignored` TINYINT(1) NOT NULL DEFAULT 0,
				`detected_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `site_item` (`site_id`, `type`, `slug`),
				KEY `type` (`type`)
			) {$charset}",

			"CREATE TABLE IF NOT EXISTS `{$p}uptime_events` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`site_id` BIGINT UNSIGNED NOT NULL,
				`status` VARCHAR(20) NOT NULL,
				`source` VARCHAR(40) NOT NULL DEFAULT 'webhook',
				`http_code` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				`response_ms` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
				`message` VARCHAR(255) NOT NULL DEFAULT '',
				`raw` LONGTEXT NULL,
				`occurred_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				KEY `site_time` (`site_id`, `occurred_at`),
				KEY `status` (`status`)
			) {$charset}",

			"CREATE TABLE IF NOT EXISTS `{$p}webhooks` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`name` VARCHAR(191) NOT NULL,
				`target_url` VARCHAR(255) NOT NULL,
				`secret` VARCHAR(191) NOT NULL DEFAULT '',
				`events` TEXT NULL,
				`client_id` BIGINT UNSIGNED NULL DEFAULT NULL,
				`is_active` TINYINT(1) NOT NULL DEFAULT 1,
				`last_delivery_at` DATETIME NULL DEFAULT NULL,
				`last_status` VARCHAR(20) NOT NULL DEFAULT '',
				`created_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				KEY `is_active` (`is_active`)
			) {$charset}",

			"CREATE TABLE IF NOT EXISTS `{$p}webhook_deliveries` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`webhook_id` BIGINT UNSIGNED NOT NULL,
				`event` VARCHAR(60) NOT NULL,
				`payload` LONGTEXT NULL,
				`status` VARCHAR(20) NOT NULL DEFAULT 'pending',
				`attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
				`next_attempt_at` DATETIME NOT NULL,
				`response_code` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				`response_body` TEXT NULL,
				`created_at` DATETIME NOT NULL,
				`updated_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				KEY `dispatch` (`status`, `next_attempt_at`),
				KEY `webhook_id` (`webhook_id`)
			) {$charset}",

			"CREATE TABLE IF NOT EXISTS `{$p}activity` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`site_id` BIGINT UNSIGNED NULL DEFAULT NULL,
				`user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
				`action` VARCHAR(60) NOT NULL,
				`level` VARCHAR(20) NOT NULL DEFAULT 'info',
				`message` TEXT NULL,
				`context` LONGTEXT NULL,
				`created_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				KEY `site_time` (`site_id`, `created_at`),
				KEY `action_time` (`action`, `created_at`)
			) {$charset}",

			"CREATE TABLE IF NOT EXISTS `{$p}reports` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`client_id` BIGINT UNSIGNED NULL DEFAULT NULL,
				`title` VARCHAR(191) NOT NULL DEFAULT '',
				`period_start` DATETIME NOT NULL,
				`period_end` DATETIME NOT NULL,
				`payload` LONGTEXT NULL,
				`html` LONGTEXT NULL,
				`status` VARCHAR(20) NOT NULL DEFAULT 'generated',
				`created_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				KEY `client_id` (`client_id`)
			) {$charset}",

			"CREATE TABLE IF NOT EXISTS `{$p}jobs` (
				`name` VARCHAR(60) NOT NULL,
				`last_run_at` DATETIME NULL DEFAULT NULL,
				`next_run_at` DATETIME NULL DEFAULT NULL,
				`last_duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
				`last_result` TEXT NULL,
				`is_running` TINYINT(1) NOT NULL DEFAULT 0,
				`locked_at` DATETIME NULL DEFAULT NULL,
				PRIMARY KEY (`name`)
			) {$charset}",

			"CREATE TABLE IF NOT EXISTS `{$p}user_sites` (
				`user_id` BIGINT UNSIGNED NOT NULL,
				`site_id` BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY (`user_id`, `site_id`),
				KEY `site_id` (`site_id`)
			) {$charset}",

			"CREATE TABLE IF NOT EXISTS `{$p}sessions` (
				`id` VARCHAR(64) NOT NULL,
				`user_id` BIGINT UNSIGNED NOT NULL,
				`ip` VARCHAR(45) NOT NULL DEFAULT '',
				`user_agent` VARCHAR(255) NOT NULL DEFAULT '',
				`created_at` DATETIME NOT NULL,
				`expires_at` DATETIME NOT NULL,
				PRIMARY KEY (`id`),
				KEY `user_id` (`user_id`),
				KEY `expires_at` (`expires_at`)
			) {$charset}",
		);
	}
}
