<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Config;
use NorthLab\Core\Crypto;
use NorthLab\Core\Csrf;
use NorthLab\Core\Database;
use NorthLab\Core\Migrator;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Core\Setting;
use NorthLab\Core\View;
use NorthLab\Repository\UserRepository;
use Throwable;

/**
 * Einrichtungsassistent. Läuft nur, solange keine config.php existiert.
 */
final class InstallController {

	public function run( Request $request ): void {
		$errors = array();
		$checks = $this->requirements();

		$blocked = false;
		foreach ( $checks as $check ) {
			if ( $check['required'] && ! $check['ok'] ) {
				$blocked = true;
			}
		}

		$input = array(
			'db_host'      => $request->string( 'db_host', 'localhost' ),
			'db_port'      => $request->string( 'db_port', '3306' ),
			'db_name'      => $request->string( 'db_name' ),
			'db_user'      => $request->string( 'db_user' ),
			'db_pass'      => (string) $request->post( 'db_pass', '' ),
			'db_prefix'    => $request->string( 'db_prefix', 'nl_' ),
			'app_url'      => $request->string( 'app_url', $this->guessUrl() ),
			'agency_name'  => $request->string( 'agency_name', 'NorthLab' ),
			'timezone'     => $request->string( 'timezone', 'Europe/Berlin' ),
			'admin_name'   => $request->string( 'admin_name' ),
			'admin_email'  => $request->string( 'admin_email' ),
			'admin_pass'   => (string) $request->post( 'admin_pass', '' ),
			'admin_pass2'  => (string) $request->post( 'admin_pass2', '' ),
		);

		if ( 'POST' === $request->method() && ! $blocked ) {
			// Der Installer läuft vor der globalen CSRF-Prüfung, deshalb hier selbst absichern.
			if ( ! Csrf::check( (string) $request->post( '_token', '' ) ) ) {
				$errors = array( 'Sicherheitstoken ungültig oder abgelaufen. Bitte das Formular neu laden.' );
			} else {
				$errors = $this->validate( $input );
			}

			if ( ! $errors ) {
				try {
					$this->install( $input );

					View::render(
						'install-done',
						array( 'appUrl' => rtrim( $input['app_url'], '/' ), 'agencyName' => $input['agency_name'] ),
						'layout/auth'
					);
					return;
				} catch ( Throwable $e ) {
					$errors[] = 'Installation fehlgeschlagen: ' . $e->getMessage();
				}
			}
		}

		View::render(
			'install',
			array(
				'checks'    => $checks,
				'blocked'   => $blocked,
				'errors'    => $errors,
				'input'     => $input,
				'timezones' => \DateTimeZone::listIdentifiers(),
			),
			'layout/auth'
		);
	}

	/**
	 * @return array<int,array{label:string,ok:bool,required:bool,hint:string}>
	 */
	private function requirements(): array {
		$checks = array(
			array(
				'label'    => 'PHP 8.1 oder neuer',
				'ok'       => PHP_VERSION_ID >= 80100,
				'required' => true,
				'hint'     => 'Gefunden: PHP ' . PHP_VERSION,
			),
		);

		foreach ( array( 'pdo_mysql' => true, 'openssl' => true, 'curl' => true, 'mbstring' => true, 'json' => true, 'zip' => false, 'sodium' => false ) as $ext => $required ) {
			$checks[] = array(
				'label'    => 'PHP-Erweiterung ' . $ext,
				'ok'       => extension_loaded( $ext ),
				'required' => $required,
				'hint'     => $required ? 'Zwingend erforderlich.' : 'Optional, verbessert Paketbau bzw. Verschlüsselung.',
			);
		}

		$checks[] = array(
			'label'    => 'config.php schreibbar',
			'ok'       => is_writable( NL_ROOT ),
			'required' => true,
			'hint'     => 'Das Verzeichnis ' . NL_ROOT . ' muss für den Webserver beschreibbar sein.',
		);

		$checks[] = array(
			'label'    => 'storage/ beschreibbar',
			'ok'       => is_dir( NL_STORAGE ) && is_writable( NL_STORAGE ),
			'required' => true,
			'hint'     => 'Wird für Logs, Cache und den Plugin-Download benötigt.',
		);

		return $checks;
	}

	/**
	 * @param array<string,string> $input
	 * @return array<int,string>
	 */
	private function validate( array $input ): array {
		$errors = array();

		if ( '' === $input['db_name'] || '' === $input['db_user'] ) {
			$errors[] = 'Bitte Datenbankname und Benutzer angeben.';
		}
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,20}$/', $input['db_prefix'] ) ) {
			$errors[] = 'Das Tabellenpräfix darf nur Buchstaben, Ziffern und Unterstriche enthalten.';
		}
		if ( '' === $input['app_url'] || ! filter_var( $input['app_url'], FILTER_VALIDATE_URL ) ) {
			$errors[] = 'Bitte eine gültige Panel-URL angeben (inkl. https://).';
		}
		if ( ! in_array( $input['timezone'], \DateTimeZone::listIdentifiers(), true ) ) {
			$errors[] = 'Unbekannte Zeitzone.';
		}
		if ( ! filter_var( $input['admin_email'], FILTER_VALIDATE_EMAIL ) ) {
			$errors[] = 'Bitte eine gültige E-Mail-Adresse für das Administratorkonto angeben.';
		}
		if ( $input['admin_pass'] !== $input['admin_pass2'] ) {
			$errors[] = 'Die beiden Passwörter stimmen nicht überein.';
		}

		$passwordError = UserRepository::validatePassword( $input['admin_pass'] );
		if ( null !== $passwordError ) {
			$errors[] = $passwordError;
		}

		if ( ! $errors ) {
			$dbError = Database::test(
				array(
					'host'    => $input['db_host'],
					'port'    => (int) $input['db_port'],
					'name'    => $input['db_name'],
					'user'    => $input['db_user'],
					'pass'    => $input['db_pass'],
					'charset' => 'utf8mb4',
				)
			);

			if ( null !== $dbError ) {
				$errors[] = 'Datenbankverbindung fehlgeschlagen: ' . $dbError;
			}
		}

		return $errors;
	}

	/**
	 * @param array<string,string> $input
	 */
	private function install( array $input ): void {
		$config = array(
			'app'      => array(
				'name'     => 'NorthLab',
				'url'      => rtrim( $input['app_url'], '/' ),
				'timezone' => $input['timezone'],
				'locale'   => 'de',
				'debug'    => false,
				'key'      => Crypto::newAppKey(),
			),
			'db'       => array(
				'host'    => $input['db_host'],
				'port'    => (int) $input['db_port'],
				'name'    => $input['db_name'],
				'user'    => $input['db_user'],
				'pass'    => $input['db_pass'],
				'prefix'  => $input['db_prefix'],
				'charset' => 'utf8mb4',
			),
			'mail'     => array(
				'transport'  => 'mail',
				'from_name'  => $input['agency_name'],
				'from_email' => $input['admin_email'],
				'smtp'       => array( 'host' => '', 'port' => 587, 'encryption' => 'tls', 'user' => '', 'pass' => '' ),
			),
			'http'     => array( 'timeout' => 60, 'update_timeout' => 300, 'verify_ssl' => true ),
			'security' => array(
				'login_attempts' => 5,
				'lockout_time'   => 900,
				'session_name'   => 'northlab_session',
				'session_ttl'    => 43200,
			),
		);

		if ( ! Config::write( NL_CONFIG_FILE, $config ) ) {
			throw new \RuntimeException( 'config.php konnte nicht geschrieben werden. Bitte Schreibrechte auf ' . NL_ROOT . ' prüfen.' );
		}

		// Konfiguration sofort aktivieren, damit Migration und Benutzeranlage laufen können.
		Config::load( NL_CONFIG_FILE );
		date_default_timezone_set( $input['timezone'] );
		Database::boot( $config['db'] );

		Migrator::migrate();
		Setting::seedDefaults();

		Setting::setMany(
			array(
				'agency_name'  => $input['agency_name'],
				'agency_email' => $input['admin_email'],
				'cron_token'   => Crypto::secret( 16 ),
			)
		);

		[ $userId, $error ] = UserRepository::create(
			$input['admin_email'],
			$input['admin_name'] !== '' ? $input['admin_name'] : 'Administrator',
			$input['admin_pass'],
			'admin'
		);

		if ( 0 === $userId ) {
			throw new \RuntimeException( (string) $error );
		}
	}

	private function guessUrl(): string {
		$base = Config::guessBaseUrl();

		// Der Installer läuft unter /install; die Panel-URL ist die Basis darüber.
		return rtrim( $base, '/' );
	}
}
