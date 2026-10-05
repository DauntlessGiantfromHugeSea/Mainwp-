<?php
defined( 'ABSPATH' ) || exit;

/**
 * Woechentliche Suche nach toten Links — in kleinen Haeppchen.
 *
 * Drei Entscheidungen, die den Unterschied zwischen "laeuft nebenher" und
 * "legt die Seite lahm" ausmachen:
 *
 *  1. Nichts laeuft am Stueck. Jeder Durchgang zerfaellt in Etappen mit
 *     Zeitbudget; ist es verbraucht, geht es beim naechsten Mal weiter.
 *  2. Interne Links gehen nicht ueber HTTP. url_to_postid() und ein Blick
 *     ins Dateisystem beantworten die meisten davon, ohne dass sich die
 *     Seite selbst aufruft — das waere bei wenigen PHP-Prozessen der
 *     sicherste Weg, sich selbst auszusperren.
 *  3. Ein 403 ist kein toter Link. Viele Seiten blocken automatische
 *     Abrufe. Das als "kaputt" zu melden, waere eine Liste voller
 *     Fehlalarme — und die liest dann niemand mehr.
 */
class NLC_Links {

	const OPT_CONFIG = 'nlc_links_config';
	const OPT_STATE  = 'nlc_links_state';
	const OPT_RESULT = 'nlc_links_result';

	const HOOK_SCAN = 'nlc_links_scan';
	const HOOK_STEP = 'nlc_links_step';

	/** Hoechstens so viele Befunde aufbewahren — die Option soll klein bleiben. */
	const MAX_FUNDE = 200;

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'    => true,
			'post_types' => array( 'post', 'page' ),
			'max_links'  => 3000,  // Obergrenze je Durchgang.
			'batch'      => 25,    // Hoechstens so viele Pruefungen je Etappe.
			'budget'     => 15,    // ... und hoechstens so viele Sekunden.
			'timeout'    => 10,
			'interval'   => 300,   // Abstand zwischen den Etappen.
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function config() {
		$stored = get_option( self::OPT_CONFIG, array() );
		$config = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );

		$config['enabled']    = ! empty( $config['enabled'] );
		$config['max_links']  = min( 20000, max( 50, (int) $config['max_links'] ) );
		$config['batch']      = min( 200, max( 5, (int) $config['batch'] ) );
		$config['budget']     = min( 60, max( 5, (int) $config['budget'] ) );
		$config['timeout']    = min( 30, max( 3, (int) $config['timeout'] ) );
		$config['interval']   = min( 3600, max( 60, (int) $config['interval'] ) );
		$config['post_types'] = array_values( array_filter( array_map( 'sanitize_key', (array) $config['post_types'] ) ) );

		if ( ! $config['post_types'] ) {
			$config['post_types'] = array( 'post', 'page' );
		}

		return $config;
	}

	/**
	 * @param array<string,mixed> $values
	 * @return array<string,mixed>
	 */
	public static function save_config( array $values ) {
		$config = wp_parse_args( $values, self::config() );
		$config = array_intersect_key( $config, self::defaults() );

		update_option( self::OPT_CONFIG, $config, false );

		$config = self::config();
		self::schedule( $config['enabled'] );

		return $config;
	}

	/* ------------------------------------------------------------- Zeitplan */

	/**
	 * @param bool|null $enabled
	 */
	public static function schedule( $enabled = null ) {
		if ( null === $enabled ) {
			$config  = self::config();
			$enabled = $config['enabled'];
		}

		$geplant = wp_next_scheduled( self::HOOK_SCAN );

		if ( ! $enabled ) {
			if ( $geplant ) {
				wp_unschedule_event( $geplant, self::HOOK_SCAN );
			}
			return;
		}

		if ( ! $geplant ) {
			// Nicht sofort und nicht zur vollen Stunde: der erste Lauf soll
			// nicht mit allem anderen zusammenfallen, was nach einem Update
			// gleichzeitig anspringt.
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::HOOK_SCAN );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK_SCAN );
		wp_clear_scheduled_hook( self::HOOK_STEP );
	}

	/**
	 * Woechentlich — WordPress kennt von Haus aus nur stuendlich, zweimal
	 * taeglich und taeglich.
	 *
	 * @param array<string,array<string,mixed>> $plaene
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_interval( $plaene ) {
		if ( ! is_array( $plaene ) ) {
			$plaene = array();
		}

		if ( ! isset( $plaene['weekly'] ) ) {
			$plaene['weekly'] = array( 'interval' => WEEK_IN_SECONDS, 'display' => 'Einmal pro Woche' );
		}

		return $plaene;
	}

	/* ------------------------------------------------------------ Durchgang */

	/**
	 * Einen Durchgang beginnen.
	 *
	 * @return array<string,mixed>
	 */
	public static function begin() {
		$config = self::config();

		if ( ! $config['enabled'] ) {
			return array();
		}

		$state = array(
			'phase'    => 'collect',
			'offset'   => 0,
			'links'    => array(),   // url => array( post_id, titel )
			'queue'    => array(),
			'checked'  => 0,
			'broken'   => array(),
			'unsure'   => array(),
			'started'  => time(),
			'touched'  => time(),
		);

		update_option( self::OPT_STATE, $state, false );
		self::schedule_step( 1 );

		return $state;
	}

	/**
	 * @param int $delay
	 */
	protected static function schedule_step( $delay ) {
		if ( ! wp_next_scheduled( self::HOOK_STEP ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), self::HOOK_STEP );
		}
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function state() {
		$state = get_option( self::OPT_STATE, null );

		return is_array( $state ) && ! empty( $state['phase'] ) ? $state : null;
	}

	/**
	 * Eine Etappe. Laeuft hoechstens das Zeitbudget lang.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function step() {
		$state = self::state();

		if ( null === $state ) {
			return null;
		}

		$config = self::config();
		$ende   = microtime( true ) + $config['budget'];

		if ( 'collect' === $state['phase'] ) {
			$state = self::collect( $state, $config, $ende );
		}

		if ( 'check' === $state['phase'] ) {
			$state = self::check_batch( $state, $config, $ende );
		}

		$state['touched'] = time();

		if ( 'done' === $state['phase'] ) {
			return self::finish( $state );
		}

		update_option( self::OPT_STATE, $state, false );
		self::schedule_step( $config['interval'] );

		return $state;
	}

	/**
	 * Links einsammeln, seitenweise.
	 *
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $config
	 * @param float               $ende
	 * @return array<string,mixed>
	 */
	protected static function collect( array $state, array $config, $ende ) {
		global $wpdb;

		$typen = "'" . implode( "','", array_map( 'esc_sql', $config['post_types'] ) ) . "'";

		while ( microtime( true ) < $ende && count( $state['links'] ) < $config['max_links'] ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Typen oben maskiert.
					"SELECT ID, post_title, post_content FROM {$wpdb->posts}
					 WHERE post_status = 'publish' AND post_type IN ({$typen})
					 ORDER BY ID ASC LIMIT %d OFFSET %d",
					20,
					(int) $state['offset']
				),
				ARRAY_A
			);

			if ( ! $rows ) {
				$state['phase'] = 'check';
				$state['queue'] = array_keys( $state['links'] );
				return $state;
			}

			foreach ( $rows as $row ) {
				foreach ( self::extract( (string) $row['post_content'], (string) get_permalink( (int) $row['ID'] ) ) as $url ) {
					if ( count( $state['links'] ) >= $config['max_links'] ) {
						break 2;
					}
					if ( ! isset( $state['links'][ $url ] ) ) {
						$state['links'][ $url ] = array( (int) $row['ID'], (string) $row['post_title'] );
					}
				}
			}

			$state['offset'] += count( $rows );
		}

		if ( count( $state['links'] ) >= $config['max_links'] ) {
			$state['phase'] = 'check';
			$state['queue'] = array_keys( $state['links'] );
		}

		return $state;
	}

	/**
	 * Adressen aus einem Beitragstext.
	 *
	 * @param string $content
	 * @param string $base Adresse des Beitrags — fuer Links wie "seite.html".
	 * @return array<int,string>
	 */
	public static function extract( $content, $base = '' ) {
		if ( '' === trim( $content ) ) {
			return array();
		}

		if ( ! preg_match_all( '/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\']/i', $content, $treffer ) ) {
			return array();
		}

		$urls = array();

		foreach ( $treffer[1] as $roh ) {
			$url = self::normalize( html_entity_decode( trim( $roh ), ENT_QUOTES, 'UTF-8' ), $base );

			if ( '' !== $url ) {
				$urls[ $url ] = true;
			}
		}

		return array_keys( $urls );
	}

	/**
	 * Eine Adresse auf eine pruefbare Form bringen — oder verwerfen.
	 *
	 * @param string $url
	 * @param string $base Adresse des Beitrags, fuer Links ohne fuehrenden Schraegstrich.
	 * @return string Leer, wenn nichts zu pruefen ist.
	 */
	public static function normalize( $url, $base = '' ) {
		$url = trim( (string) $url );

		if ( '' === $url || '#' === $url[0] ) {
			return '';
		}

		// Alles, was kein Abruf ist.
		if ( preg_match( '/^(mailto|tel|javascript|data|sms|callto|skype|whatsapp|ftp):/i', $url ) ) {
			return '';
		}

		// Protokollrelativ.
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}

		// Seitenrelativ.
		if ( '/' === $url[0] ) {
			$url = untrailingslashit( home_url() ) . $url;
		}

		// Beitragsrelativ ("seite.html", "../anderes/"). Ohne diesen Zweig
		// fielen solche Links stillschweigend aus der Pruefung — und ein
		// uebersehener toter Link ist schlimmer als einer, der fehlt.
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) && '' !== $base ) {
			$url = self::resolve( $url, $base );
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		// Der Anker gehoert nicht zur Adresse — sonst wird dieselbe Seite
		// fuenfmal geprueft, nur weil fuenf Sprungmarken darauf zeigen.
		$pos = strpos( $url, '#' );
		if ( false !== $pos ) {
			$url = substr( $url, 0, $pos );
		}

		return '' === $url ? '' : $url;
	}

	/**
	 * Eine relative Adresse gegen die Adresse des Beitrags aufloesen.
	 *
	 * @param string $url
	 * @param string $base
	 * @return string
	 */
	public static function resolve( $url, $base ) {
		$teile = wp_parse_url( $base );

		if ( empty( $teile['scheme'] ) || empty( $teile['host'] ) ) {
			return '';
		}

		$wurzel = $teile['scheme'] . '://' . $teile['host']
			. ( empty( $teile['port'] ) ? '' : ':' . $teile['port'] );

		$verzeichnis = isset( $teile['path'] ) ? (string) $teile['path'] : '/';

		// Von "/a/b/seite" bleibt "/a/b/" — bei "/a/b/" bleibt es dabei.
		if ( '' === $verzeichnis || '/' !== substr( $verzeichnis, -1 ) ) {
			$verzeichnis = substr( $verzeichnis, 0, (int) strrpos( $verzeichnis, '/' ) + 1 );
		}

		$pfad  = $verzeichnis . $url;
		$teile = array();

		foreach ( explode( '/', $pfad ) as $stueck ) {
			if ( '.' === $stueck ) {
				continue;
			}
			if ( '..' === $stueck ) {
				array_pop( $teile );
				continue;
			}
			$teile[] = $stueck;
		}

		return $wurzel . implode( '/', $teile );
	}

	/**
	 * Eine Portion Adressen pruefen.
	 *
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $config
	 * @param float               $ende
	 * @return array<string,mixed>
	 */
	protected static function check_batch( array $state, array $config, $ende ) {
		$getan = 0;

		while ( $state['queue'] && $getan < $config['batch'] && microtime( true ) < $ende ) {
			$url    = array_shift( $state['queue'] );
			$quelle = isset( $state['links'][ $url ] ) ? $state['links'][ $url ] : array( 0, '' );

			$ergebnis = self::check_url( $url, $config['timeout'] );

			$state['checked']++;
			$getan++;

			if ( 'broken' === $ergebnis['kind'] && count( $state['broken'] ) < self::MAX_FUNDE ) {
				$state['broken'][] = array(
					'url'     => $url,
					'status'  => $ergebnis['status'],
					'reason'  => $ergebnis['reason'],
					'post_id' => $quelle[0],
					'title'   => $quelle[1],
				);
			} elseif ( 'unsure' === $ergebnis['kind'] && count( $state['unsure'] ) < self::MAX_FUNDE ) {
				$state['unsure'][] = array(
					'url'     => $url,
					'status'  => $ergebnis['status'],
					'reason'  => $ergebnis['reason'],
					'post_id' => $quelle[0],
					'title'   => $quelle[1],
				);
			}
		}

		if ( ! $state['queue'] ) {
			$state['phase'] = 'done';
		}

		return $state;
	}

	/**
	 * Eine einzelne Adresse.
	 *
	 * @param string $url
	 * @param int    $timeout
	 * @return array{kind:string,status:int,reason:string}
	 */
	public static function check_url( $url, $timeout = 10 ) {
		if ( self::is_internal( $url ) ) {
			$intern = self::check_internal( $url );

			if ( null !== $intern ) {
				return $intern;
			}
		}

		$args = array(
			'timeout'     => (int) $timeout,
			'redirection' => 3,
			'sslverify'   => true,
			'user-agent'  => 'NorthLab Linkpruefung/1.0 (+' . home_url() . ')',
		);

		$antwort = wp_remote_head( $url, $args );
		$code    = is_wp_error( $antwort ) ? 0 : (int) wp_remote_retrieve_response_code( $antwort );

		// Viele Server moegen HEAD nicht. Ein 405 oder 501 heisst nicht, dass
		// der Link tot ist — dann noch einmal mit GET.
		if ( is_wp_error( $antwort ) || in_array( $code, array( 0, 403, 405, 500, 501 ), true ) ) {
			$antwort = wp_remote_get( $url, $args );
			$code    = is_wp_error( $antwort ) ? 0 : (int) wp_remote_retrieve_response_code( $antwort );
		}

		if ( is_wp_error( $antwort ) ) {
			return self::result( 'broken', 0, $antwort->get_error_message() );
		}

		return self::classify( $code );
	}

	/**
	 * @param int $code
	 * @return array{kind:string,status:int,reason:string}
	 */
	public static function classify( $code ) {
		$code = (int) $code;

		if ( $code >= 200 && $code < 400 ) {
			return self::result( 'ok', $code, '' );
		}

		// Kein toter Link, sondern eine Seite, die Maschinen aussperrt.
		// Als "kaputt" gemeldet waere das ein Fehlalarm, der die echten
		// Funde zudeckt.
		if ( in_array( $code, array( 401, 403, 429, 999 ), true ) ) {
			return self::result( 'unsure', $code, 'Die Seite blockt automatische Abrufe — von Hand prüfen.' );
		}

		if ( $code >= 500 ) {
			return self::result( 'unsure', $code, 'Serverfehler auf der Gegenseite — vielleicht nur vorübergehend.' );
		}

		return self::result( 'broken', $code, 404 === $code ? 'Seite nicht gefunden.' : 'Antwort ' . $code );
	}

	/**
	 * @return array{kind:string,status:int,reason:string}
	 */
	protected static function result( $kind, $status, $reason ) {
		return array( 'kind' => $kind, 'status' => (int) $status, 'reason' => (string) $reason );
	}

	/**
	 * @param string $url
	 * @return bool
	 */
	public static function is_internal( $url ) {
		$eigen = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return '' !== $host && $host === $eigen;
	}

	/**
	 * Interne Adresse ohne HTTP beantworten — oder null, wenn es nicht geht.
	 *
	 * Spart der Kundenseite genau die Abrufe auf sich selbst, die bei wenigen
	 * PHP-Prozessen zum Stillstand fuehren koennen.
	 *
	 * @param string $url
	 * @return array{kind:string,status:int,reason:string}|null
	 */
	protected static function check_internal( $url ) {
		$post_id = url_to_postid( $url );

		if ( $post_id > 0 ) {
			return 'publish' === get_post_status( $post_id )
				? self::result( 'ok', 200, '' )
				: self::result( 'broken', 404, 'Der verlinkte Beitrag ist nicht mehr veröffentlicht.' );
		}

		// Datei im Upload- oder Plugin-Verzeichnis: im Dateisystem nachsehen.
		$pfad = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = (string) wp_parse_url( home_url(), PHP_URL_PATH );

		if ( '' !== $base && 0 === strpos( $pfad, $base ) ) {
			$pfad = substr( $pfad, strlen( $base ) );
		}

		$pfad = ltrim( rawurldecode( $pfad ), '/' );

		if ( '' !== $pfad && 0 === strpos( $pfad, 'wp-content/' ) && false === strpos( $pfad, '..' ) ) {
			return file_exists( ABSPATH . $pfad )
				? self::result( 'ok', 200, '' )
				: self::result( 'broken', 404, 'Die verlinkte Datei liegt nicht mehr da.' );
		}

		// Archiv, Kategorie, Startseite, eigene Route: das beantwortet nur ein
		// echter Abruf. Hier ehrlich sagen, dass wir es nicht wissen.
		return null;
	}

	/**
	 * Durchgang abschliessen.
	 *
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	protected static function finish( array $state ) {
		$result = array(
			'at'       => gmdate( 'c' ),
			'seconds'  => max( 0, time() - (int) $state['started'] ),
			'links'    => count( $state['links'] ),
			'checked'  => (int) $state['checked'],
			'broken'   => array_values( $state['broken'] ),
			'unsure'   => array_values( $state['unsure'] ),
			'capped'   => count( $state['broken'] ) >= self::MAX_FUNDE,
		);

		update_option( self::OPT_RESULT, $result, false );
		delete_option( self::OPT_STATE );
		wp_clear_scheduled_hook( self::HOOK_STEP );

		return $result;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function result_last() {
		$result = get_option( self::OPT_RESULT, null );

		return is_array( $result ) && ! empty( $result['at'] ) ? $result : null;
	}

	/**
	 * Kurzfassung fuer das Panel.
	 *
	 * Bewusst nicht die ganze Liste: bei 200 Funden blaeht das jeden Sync auf.
	 * Die vollstaendige Liste steht dort, wo sie hingehoert — auf der
	 * Kundenseite, bei den Beitraegen, die sich bearbeiten lassen.
	 *
	 * @return array<string,mixed>
	 */
	public static function summary() {
		$result = self::result_last();

		$kurz = array(
			'enabled'  => (bool) self::config()['enabled'],
			'progress' => self::progress(),
		);

		if ( null === $result ) {
			return $kurz + array( 'at' => '', 'checked' => 0, 'broken' => 0, 'unsure' => 0, 'examples' => array() );
		}

		return $kurz + array(
			'at'       => (string) $result['at'],
			'checked'  => (int) $result['checked'],
			'broken'   => count( (array) $result['broken'] ),
			'unsure'   => count( (array) $result['unsure'] ),
			'examples' => array_slice( (array) $result['broken'], 0, 20 ),
		);
	}

	/**
	 * Fortschritt fuer die Anzeige.
	 *
	 * @return array<string,mixed>
	 */
	public static function progress() {
		$state = self::state();

		if ( null === $state ) {
			return array( 'running' => false );
		}

		$gesamt = count( $state['links'] );

		return array(
			'running' => true,
			'phase'   => (string) $state['phase'],
			'total'   => $gesamt,
			'done'    => (int) $state['checked'],
			'broken'  => count( $state['broken'] ),
			'started' => (int) $state['started'],
		);
	}

	public function hooks() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_interval' ) );
		add_action( self::HOOK_SCAN, array( __CLASS__, 'begin' ) );
		add_action( self::HOOK_STEP, array( __CLASS__, 'step' ) );
	}
}
