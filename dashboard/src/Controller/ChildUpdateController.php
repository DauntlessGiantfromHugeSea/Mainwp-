<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Config;
use NorthLab\Core\Crypto;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Repository\SiteRepository;
use NorthLab\Service\ChildPackager;
use Throwable;

/**
 * Update-Quelle für das Child-Plugin.
 *
 * Das Plugin steht nicht auf wordpress.org, also erfährt WordPress von sich aus
 * nie von einer neuen Version. Diese beiden Endpunkte machen das Panel selbst
 * zur Update-Quelle: die Kundenseite fragt hier nach der aktuellen Version und
 * lädt das Paket von hier.
 *
 * Beide Endpunkte sind ohne Anmeldung erreichbar — die Kundenseite hat keine
 * Panel-Sitzung. Vertrauen entsteht anders:
 *
 *  - Das Manifest wird mit dem privaten Schlüssel signiert, den das Panel für
 *    genau diese Verbindung hält. Die Kundenseite prüft die Signatur mit dem
 *    öffentlichen Schlüssel, den sie beim Verbinden bekommen hat.
 *  - Im signierten Manifest steht die SHA-256-Summe des Pakets. Ein unterwegs
 *    ausgetauschtes ZIP fällt beim Vergleich auf, bevor es ausgepackt wird.
 *
 * Die Verbindungskennung allein öffnet also nichts: wer sie kennt, bekommt ein
 * signiertes Manifest und ein öffentlich herunterladbares Plugin-Paket — beides
 * enthält nichts Vertrauliches.
 */
final class ChildUpdateController extends BaseController {

	public function manifest( Request $request ): void {
		$site = $this->site( (string) $request->post( 'connection_id', '' ) );

		if ( null === $site ) {
			Response::json( array( 'ok' => false, 'error' => 'Unbekannte Verbindung.' ), 404 );
			return;
		}

		try {
			$manifest = array(
				'slug'         => 'north-lab-child',
				'plugin'       => 'north-lab-child/north-lab-child.php',
				'version'      => ChildPackager::version(),
				'package'      => $this->packageUrl( (string) $site['connection_id'] ),
				'sha256'       => ChildPackager::sha256(),
				'requires'     => '6.0',
				'requires_php' => '7.4',
				'name'         => 'NorthLab Child',
				// Gegen Wiedereinspielen eines alten, echt signierten Manifests.
				'generated_at' => time(),
			);
		} catch ( Throwable $e ) {
			Response::json( array( 'ok' => false, 'error' => 'Paket konnte nicht erzeugt werden.' ), 500 );
			return;
		}

		$privateKey = Crypto::decrypt( (string) ( $site['private_key'] ?? '' ) );

		if ( '' === $privateKey ) {
			Response::json( array( 'ok' => false, 'error' => 'Für diese Verbindung ist kein Schlüssel hinterlegt.' ), 409 );
			return;
		}

		// Signiert wird exakt die Zeichenkette, die auch übertragen wird —
		// sonst prüft die Kundenseite am Ende etwas anderes als das Original.
		$canonical = (string) json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		Response::json(
			array(
				'ok'        => true,
				'manifest'  => $canonical,
				'signature' => Crypto::sign( $canonical, $privateKey ),
			)
		);
	}

	public function package( Request $request ): void {
		$site = $this->site( (string) ( $request->params['connection'] ?? '' ) );

		if ( null === $site ) {
			Response::notFound( 'Unbekannte Verbindung.' );
			return;
		}

		try {
			$archive = ChildPackager::build();
		} catch ( Throwable $e ) {
			Response::notFound( 'Paket konnte nicht erzeugt werden.' );
			return;
		}

		Response::download( $archive, ChildPackager::filename(), 'application/zip' );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function site( string $connectionId ): ?array {
		$connectionId = trim( $connectionId );

		if ( '' === $connectionId || ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $connectionId ) ) {
			return null;
		}

		return SiteRepository::findByConnectionId( $connectionId );
	}

	private function packageUrl( string $connectionId ): string {
		$base = rtrim( (string) Config::get( 'app.url', '' ), '/' );

		if ( '' === $base ) {
			$base = Config::guessBaseUrl();
		}

		return rtrim( $base, '/' ) . '/api/child/package/' . rawurlencode( $connectionId );
	}
}
