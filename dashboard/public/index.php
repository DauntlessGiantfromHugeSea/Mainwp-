<?php
/**
 * NorthLab Control Panel — Front Controller.
 *
 * Der Webserver muss auf dieses Verzeichnis (public/) zeigen.
 */

declare( strict_types = 1 );

require_once dirname( __DIR__ ) . '/src/bootstrap.php';

use NorthLab\Controller\ActivityController;
use NorthLab\Controller\ApiController;
use NorthLab\Controller\AuthController;
use NorthLab\Controller\ClientController;
use NorthLab\Controller\DashboardController;
use NorthLab\Controller\DownloadController;
use NorthLab\Controller\InstallController;
use NorthLab\Controller\ReportController;
use NorthLab\Controller\SettingsController;
use NorthLab\Controller\SiteController;
use NorthLab\Controller\UpdateController;
use NorthLab\Controller\UptimeController;
use NorthLab\Controller\UserController;
use NorthLab\Controller\WebhookController;
use NorthLab\Core\Config;
use NorthLab\Core\Csrf;
use NorthLab\Core\Migrator;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Core\Router;
use NorthLab\Core\Session;

$request = new Request();
$router  = new Router();

/* ------------------------------------------------------------ Installer */

if ( ! Config::isInstalled() ) {
	$router->any( '/install', array( InstallController::class, 'run' ) );
	$router->get( '/{any:.*}', static fn(): never => Response::redirect( '/install' ) );
	$router->post( '/{any:.*}', static fn(): never => Response::redirect( '/install' ) );
	$router->dispatch( $request );
	exit;
}

// Schema-Aktualisierung nach einem Update der Anwendung.
if ( Migrator::needsMigration() ) {
	Migrator::migrate();
}

Session::start();

/* -------------------------------------------------------------- Routen */

// Öffentlich: Monitoring-Webhooks und Cron-Trigger brauchen keine Anmeldung.
$router->any( '/api/uptime/{token:[a-f0-9]{32,64}}', array( ApiController::class, 'uptime' ) );
$router->any( '/api/cron/{token:[A-Za-z0-9]{16,80}}', array( ApiController::class, 'cron' ) );

$router->get( '/login', array( AuthController::class, 'showLogin' ) );
$router->post( '/login', array( AuthController::class, 'login' ) );
$router->post( '/logout', array( AuthController::class, 'logout' ) );

$router->get( '/', array( DashboardController::class, 'index' ) );

// Seiten
$router->get( '/sites', array( SiteController::class, 'index' ) );
$router->get( '/sites/new', array( SiteController::class, 'create' ) );
$router->post( '/sites', array( SiteController::class, 'store' ) );
$router->post( '/sites/bulk', array( SiteController::class, 'bulk' ) );
$router->get( '/sites/{id:\d+}', array( SiteController::class, 'show' ) );
$router->post( '/sites/{id:\d+}', array( SiteController::class, 'update' ) );
$router->post( '/sites/{id:\d+}/sync', array( SiteController::class, 'sync' ) );
$router->post( '/sites/{id:\d+}/reconnect', array( SiteController::class, 'reconnect' ) );
$router->post( '/sites/{id:\d+}/delete', array( SiteController::class, 'destroy' ) );
$router->post( '/sites/{id:\d+}/maintenance', array( SiteController::class, 'maintenance' ) );
$router->post( '/sites/{id:\d+}/security', array( SiteController::class, 'security' ) );
$router->post( '/sites/{id:\d+}/extensions', array( SiteController::class, 'extensions' ) );
$router->post( '/sites/{id:\d+}/token', array( SiteController::class, 'rotateToken' ) );

// Updates
$router->get( '/updates', array( UpdateController::class, 'index' ) );
$router->post( '/updates/apply', array( UpdateController::class, 'apply' ) );

// Uptime
$router->get( '/uptime', array( UptimeController::class, 'index' ) );

// Kunden
$router->get( '/clients', array( ClientController::class, 'index' ) );
$router->post( '/clients', array( ClientController::class, 'store' ) );
$router->get( '/clients/{id:\d+}', array( ClientController::class, 'show' ) );
$router->post( '/clients/{id:\d+}', array( ClientController::class, 'update' ) );
$router->post( '/clients/{id:\d+}/delete', array( ClientController::class, 'destroy' ) );

// Webhooks
$router->get( '/webhooks', array( WebhookController::class, 'index' ) );
$router->post( '/webhooks', array( WebhookController::class, 'store' ) );
$router->post( '/webhooks/{id:\d+}', array( WebhookController::class, 'update' ) );
$router->post( '/webhooks/{id:\d+}/delete', array( WebhookController::class, 'destroy' ) );
$router->post( '/webhooks/{id:\d+}/test', array( WebhookController::class, 'test' ) );
$router->post( '/webhooks/deliveries/{id:\d+}/retry', array( WebhookController::class, 'retry' ) );

// Berichte
$router->get( '/reports', array( ReportController::class, 'index' ) );
$router->post( '/reports', array( ReportController::class, 'generate' ) );
$router->get( '/reports/{id:\d+}', array( ReportController::class, 'show' ) );
$router->get( '/reports/{id:\d+}/download', array( ReportController::class, 'download' ) );
$router->post( '/reports/{id:\d+}/delete', array( ReportController::class, 'destroy' ) );

// Protokoll
$router->get( '/activity', array( ActivityController::class, 'index' ) );

// Download des Child-Plugins
$router->get( '/download', array( DownloadController::class, 'index' ) );
$router->get( '/download/child-plugin', array( DownloadController::class, 'childPlugin' ) );

// Einstellungen und Benutzer
$router->get( '/settings', array( SettingsController::class, 'index' ) );
$router->post( '/settings', array( SettingsController::class, 'save' ) );
$router->get( '/users', array( UserController::class, 'index' ) );
$router->post( '/users', array( UserController::class, 'store' ) );
$router->post( '/users/{id:\d+}', array( UserController::class, 'update' ) );
$router->post( '/users/{id:\d+}/delete', array( UserController::class, 'destroy' ) );
$router->post( '/profile', array( UserController::class, 'updateProfile' ) );

/* ------------------------------------------------------- CSRF-Prüfung */

if ( 'GET' !== $request->method() && ! str_starts_with( $request->path(), '/api/' ) ) {
	if ( ! Csrf::check( (string) $request->post( '_token', '' ) ) ) {
		if ( $request->wantsJson() ) {
			Response::json( array( 'ok' => false, 'error' => 'Sicherheitstoken ungültig. Bitte die Seite neu laden.' ), 419 );
			exit;
		}
		Session::flash( 'error', 'Sicherheitstoken ungültig oder abgelaufen. Bitte erneut versuchen.' );
		Response::redirect( $request->header( 'Referer' ) ? '/' : '/login' );
	}
}

$router->dispatch( $request );
