<?php
defined( 'ABSPATH' ) || exit;

/**
 * Updates fuer das Child-Plugin selbst.
 *
 * Das Plugin liegt nicht auf wordpress.org, also erfaehrt WordPress von sich aus
 * nie von einer neuen Version. Diese Klasse macht das verbundene Panel zur
 * Update-Quelle und haengt das Ergebnis in genau den Transient, aus dem
 * WordPress seine Update-Liste liest. Dadurch erscheint das Update
 *
 *   - im Backend der Kundenseite unter "Plugins" wie jedes andere,
 *   - in der Update-Zentrale des Panels wie jedes andere,
 *   - und in den automatischen Updates, falls dafuer eingeschaltet.
 *
 * Es gibt also keine zweite Update-Bahn, die auseinanderlaufen koennte.
 *
 * Vertrauen: Das Manifest ist mit dem privaten Schluessel des Panels signiert;
 * geprueft wird mit dem oeffentlichen Schluessel aus der Verbindung. Im
 * signierten Manifest steht die SHA-256-Summe des Pakets, und das
 * heruntergeladene ZIP wird dagegen geprueft, bevor WordPress es auspackt.
 * Ein manipuliertes Panel-Manifest oder ein unterwegs getauschtes Paket
 * kommt damit nicht durch.
 */
class NLC_Selfupdate {

	const SLUG      = 'north-lab-child';
	const BASENAME  = 'north-lab-child/north-lab-child.php';
	const CACHE     = 'nlc_update_manifest';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** Aelter als das darf ein Manifest nicht sein, sonst zaehlt es nicht. */
	const MAX_AGE = 86400;

	public function hooks() {
		add_filter( 'site_transient_update_plugins', array( $this, 'inject' ) );
		add_filter( 'upgrader_pre_download', array( $this, 'verified_download' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'forget' ), 10, 0 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
	}

	/**
	 * Manifest vom Panel holen und pruefen.
	 *
	 * @param bool $force Zwischenspeicher uebergehen.
	 * @return array<string,mixed>|null
	 */
	public static function manifest( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			// Ein leerer Eintrag merkt sich einen Fehlschlag, damit nicht jeder
			// Seitenaufruf erneut ins Netz greift.
			if ( '' === $cached ) {
				return null;
			}
		}

		$connection = NLC_Options::connection();

		if ( ! $connection || empty( $connection['dashboard_url'] ) || empty( $connection['public_key'] ) ) {
			return null;
		}

		$response = wp_remote_post(
			rtrim( (string) $connection['dashboard_url'], '/' ) . '/api/child/manifest',
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array( 'connection_id' => (string) $connection['connection_id'] ),
			)
		);

		$manifest = self::interpret( $response, (string) $connection['public_key'] );

		set_transient( self::CACHE, null === $manifest ? '' : $manifest, self::CACHE_TTL );

		return $manifest;
	}

	/**
	 * Antwort auspacken, Signatur und Plausibilitaet pruefen.
	 *
	 * @param array<string,mixed>|WP_Error $response
	 * @param string                       $public_key
	 * @return array<string,mixed>|null
	 */
	protected static function interpret( $response, $public_key ) {
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['ok'] ) || empty( $body['manifest'] ) || empty( $body['signature'] ) ) {
			return null;
		}

		if ( ! function_exists( 'openssl_verify' ) ) {
			return null;
		}

		$raw       = (string) $body['manifest'];
		$signature = base64_decode( (string) $body['signature'], true );

		if ( false === $signature || '' === $signature ) {
			return null;
		}

		if ( 1 !== openssl_verify( $raw, $signature, $public_key, OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}

		$manifest = json_decode( $raw, true );

		if ( ! is_array( $manifest ) || empty( $manifest['version'] ) || empty( $manifest['package'] ) || empty( $manifest['sha256'] ) ) {
			return null;
		}

		// Ein altes, echt signiertes Manifest darf nicht ewig gueltig bleiben.
		if ( abs( time() - (int) ( $manifest['generated_at'] ?? 0 ) ) > self::MAX_AGE ) {
			return null;
		}

		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $manifest['sha256'] ) ) {
			return null;
		}

		// Das Paket muss vom verbundenen Panel kommen, nicht von irgendwoher.
		if ( ! self::belongs_to_dashboard( (string) $manifest['package'] ) ) {
			return null;
		}

		return $manifest;
	}

	/**
	 * @param string $url
	 * @return bool
	 */
	public static function belongs_to_dashboard( $url ) {
		$connection = NLC_Options::connection();

		if ( ! $connection || empty( $connection['dashboard_url'] ) ) {
			return false;
		}

		$want = wp_parse_url( (string) $connection['dashboard_url'] );
		$have = wp_parse_url( $url );

		if ( empty( $want['host'] ) || empty( $have['host'] ) ) {
			return false;
		}

		if ( strtolower( $want['host'] ) !== strtolower( $have['host'] ) ) {
			return false;
		}

		// Ein per http ausgeliefertes Paket waere unterwegs austauschbar. Nur
		// wenn das Panel selbst unverschluesselt laeuft, ist das kein Rueckschritt.
		$wantScheme = strtolower( (string) ( $want['scheme'] ?? 'https' ) );
		$haveScheme = strtolower( (string) ( $have['scheme'] ?? '' ) );

		return $haveScheme === $wantScheme;
	}

	/**
	 * Neue Version in die Update-Liste von WordPress haengen.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$manifest = self::manifest();

		if ( null === $manifest ) {
			return $transient;
		}

		$item = (object) array(
			'id'           => 'north-lab.de/' . self::SLUG,
			'slug'         => self::SLUG,
			'plugin'       => self::BASENAME,
			'new_version'  => (string) $manifest['version'],
			'url'          => 'https://north-lab.de/',
			'package'      => (string) $manifest['package'],
			'requires'     => (string) ( $manifest['requires'] ?? '' ),
			'requires_php' => (string) ( $manifest['requires_php'] ?? '' ),
			'tested'       => (string) ( $manifest['tested'] ?? '' ),
			'icons'        => array(),
			'banners'      => array(),
		);

		if ( version_compare( (string) $manifest['version'], NLC_VERSION, '>' ) ) {
			$transient->response[ self::BASENAME ] = $item;
			unset( $transient->no_update[ self::BASENAME ] );
		} else {
			// Ohne diesen Zweig zeigt WordPress "Auto-Updates" fuer das Plugin nicht an.
			$transient->no_update[ self::BASENAME ] = $item;
			unset( $transient->response[ self::BASENAME ] );
		}

		return $transient;
	}

	/**
	 * Paket selbst laden und gegen die signierte Pruefsumme halten.
	 *
	 * WordPress wuerde sonst blind auspacken, was unter der Paket-Adresse liegt.
	 *
	 * @param mixed  $reply
	 * @param string $package
	 * @param object $upgrader
	 * @return mixed Pfad zur geprueften Datei, WP_Error oder $reply unveraendert.
	 */
	public function verified_download( $reply, $package, $upgrader = null ) {
		$manifest = self::manifest();

		if ( null === $manifest || (string) $manifest['package'] !== (string) $package ) {
			return $reply;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $package, 300 );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$actual = hash_file( 'sha256', $file );

		if ( ! hash_equals( (string) $manifest['sha256'], (string) $actual ) ) {
			unlink( $file );

			return new WP_Error(
				'nlc_package_mismatch',
				'Das heruntergeladene Paket entspricht nicht der signierten Prüfsumme. Update abgebrochen.'
			);
		}

		return $file;
	}

	/**
	 * Angaben fuer das Detailfenster im Backend.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$manifest = self::manifest();

		if ( null === $manifest ) {
			return $result;
		}

		return (object) array(
			'name'          => 'NorthLab Child',
			'slug'          => self::SLUG,
			'version'       => (string) $manifest['version'],
			'author'        => '<a href="https://north-lab.de/">NorthLab</a>',
			'homepage'      => 'https://north-lab.de/',
			'requires'      => (string) ( $manifest['requires'] ?? '' ),
			'requires_php'  => (string) ( $manifest['requires_php'] ?? '' ),
			'download_link' => (string) $manifest['package'],
			'sections'      => array(
				'description' => 'Verbindet diese Seite mit dem NorthLab Control Panel. '
					. 'Updates kommen direkt vom verbundenen Panel und werden vor der Installation signaturgeprüft.',
			),
		);
	}

	public function forget() {
		delete_transient( self::CACHE );
	}

	/**
	 * Vom Panel angestossenes Update.
	 *
	 * @return array<string,mixed>
	 */
	public static function run() {
		if ( ! NLC_Options::setting( 'allow_self_update' ) ) {
			return array(
				'ok'      => false,
				'message' => 'Selbst-Updates sind auf dieser Seite deaktiviert.',
				'version' => NLC_VERSION,
			);
		}

		delete_transient( self::CACHE );
		$manifest = self::manifest( true );

		if ( null === $manifest ) {
			return array(
				'ok'      => false,
				'message' => 'Das Panel hat kein gültig signiertes Manifest geliefert.',
				'version' => NLC_VERSION,
			);
		}

		if ( ! version_compare( (string) $manifest['version'], NLC_VERSION, '>' ) ) {
			return array(
				'ok'        => true,
				'message'   => 'Bereits aktuell.',
				'version'   => NLC_VERSION,
				'available' => (string) $manifest['version'],
				'updated'   => false,
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Frischer Transient, sonst kennt der Upgrader die neue Version nicht.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( self::BASENAME );

		delete_transient( self::CACHE );

		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'message' => $result->get_error_message(), 'version' => NLC_VERSION );
		}

		if ( true !== $result ) {
			$error = $upgrader->skin instanceof WP_Upgrader_Skin && is_wp_error( $upgrader->skin->result )
				? $upgrader->skin->result->get_error_message()
				: 'Update fehlgeschlagen.';

			return array( 'ok' => false, 'message' => $error, 'version' => NLC_VERSION );
		}

		// Das Plugin wurde gerade unter den eigenen Fuessen ausgetauscht. Die
		// Antwort nennt die Zielversion; sie laeuft ab dem naechsten Aufruf.
		return array(
			'ok'        => true,
			'message'   => sprintf( 'Child-Plugin auf %s aktualisiert.', (string) $manifest['version'] ),
			'version'   => (string) $manifest['version'],
			'previous'  => NLC_VERSION,
			'updated'   => true,
		);
	}

	/**
	 * Nur nachsehen, nichts installieren.
	 *
	 * @return array<string,mixed>
	 */
	public static function check() {
		$manifest = self::manifest( true );

		return array(
			'ok'        => null !== $manifest,
			'version'   => NLC_VERSION,
			'available' => null !== $manifest ? (string) $manifest['version'] : null,
			'outdated'  => null !== $manifest && version_compare( (string) $manifest['version'], NLC_VERSION, '>' ),
			'message'   => null !== $manifest ? '' : 'Das Panel hat kein gültig signiertes Manifest geliefert.',
		);
	}
}
