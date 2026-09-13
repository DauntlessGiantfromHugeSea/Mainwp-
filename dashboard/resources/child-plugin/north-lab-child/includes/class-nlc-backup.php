<?php
defined( 'ABSPATH' ) || exit;

/**
 * Liefert Dateien und Datenbank für die Sicherung durch das Panel.
 *
 * Das Panel entscheidet, was es holt. Diese Seite erzeugt kein fertiges Archiv —
 * genau daran scheitern Backup-Plugins auf günstigem Hosting regelmäßig: Ein
 * Zwanzig-Gigabyte-Tar in einem PHP-Request geht nicht gut. Stattdessen:
 * Dateiliste melden, Datenbank in Etappen schreiben, Inhalte blockweise abgeben.
 */
class NLC_Backup {

	/** Verzeichnis für Zwischenstände, liegt unter uploads/. */
	const WORK_DIR = 'northlab-backup';

	/** Zustand des laufenden Datenbank-Exports. */
	const OPT_DB_JOB = 'nlc_backup_db_job';

	/** Sekunden, die ein einzelner Schritt höchstens arbeitet. */
	const STEP_SECONDS = 12;

	/** Zeilen je Durchgang beim Auslesen einer Tabelle. */
	const ROWS_PER_BATCH = 500;

	/* ------------------------------------------------------------- Dateien */

	/**
	 * Verzeichnisse, die niemals in eine Sicherung gehören.
	 *
	 * @return array<int,string>
	 */
	public static function default_excludes() {
		return array(
			'wp-content/cache',
			'wp-content/uploads/cache',
			'wp-content/upgrade',
			'wp-content/upgrade-temp-backup',
			'wp-content/backup',
			'wp-content/backups',
			'wp-content/ai1wm-backups',
			'wp-content/updraft',
			'wp-content/uploads/backwpup',
			'wp-content/uploads/' . self::WORK_DIR,
			'wp-content/debug.log',
			'node_modules',
			'.git',
			'.svn',
		);
	}

	/**
	 * Wurzelverzeichnisse, die gesichert werden dürfen.
	 *
	 * @return array<string,string>
	 */
	public static function roots() {
		return array(
			'content' => WP_CONTENT_DIR,
			'root'    => untrailingslashit( ABSPATH ),
		);
	}

	/**
	 * Dateiliste eines Wurzelverzeichnisses, seitenweise.
	 *
	 * Verglichen wird später über Grösse und Änderungszeit — eine Prüfsumme über
	 * jede Datei würde auf grossen Mediatheken jedes Zeitlimit sprengen.
	 *
	 * @param string            $root     Schlüssel aus roots().
	 * @param int               $offset   Ab welchem Eintrag.
	 * @param int               $limit    Wie viele Einträge.
	 * @param array<int,string> $excludes Zusätzliche Ausschlüsse.
	 * @return array<string,mixed>
	 */
	public static function manifest( $root, $offset = 0, $limit = 5000, array $excludes = array() ) {
		$roots = self::roots();
		$key   = isset( $roots[ $root ] ) ? $root : 'content';
		$base  = $roots[ $key ];

		if ( ! is_dir( $base ) ) {
			return array( 'root' => $key, 'files' => array(), 'total' => 0, 'complete' => true );
		}

		$excludes = array_merge( self::default_excludes(), $excludes );
		$prefix   = 'root' === $key ? '' : 'wp-content/';

		$files = array();
		$index = 0;
		$total = 0;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS ),
					static function ( $current ) use ( $base, $prefix, $excludes ) {
						$relative = $prefix . ltrim( str_replace( '\\', '/', substr( $current->getPathname(), strlen( $base ) ) ), '/' );

						foreach ( $excludes as $exclude ) {
							if ( $relative === $exclude || 0 === strpos( $relative, rtrim( $exclude, '/' ) . '/' ) ) {
								return false;
							}
						}

						return true;
					}
				),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $item ) {
				if ( ! $item->isFile() || $item->isLink() ) {
					continue;
				}

				$total++;

				if ( $index++ < $offset ) {
					continue;
				}
				if ( count( $files ) >= $limit ) {
					continue;
				}

				$relative = $prefix . ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $base ) ) ), '/' );

				$files[] = array(
					'path'  => $relative,
					'size'  => (int) $item->getSize(),
					'mtime' => (int) $item->getMTime(),
				);
			}
		} catch ( Exception $e ) {
			return array( 'root' => $key, 'files' => $files, 'total' => $total, 'complete' => true, 'error' => $e->getMessage() );
		}

		return array(
			'root'     => $key,
			'files'    => $files,
			'total'    => $total,
			'offset'   => $offset,
			'complete' => ( $offset + count( $files ) ) >= $total,
		);
	}

	/**
	 * Absoluter Pfad zu einer Datei, sofern sie im erlaubten Bereich liegt.
	 *
	 * Der Rückgabewert ist null, sobald irgendetwas nicht stimmt — lieber nichts
	 * ausliefern als versehentlich etwas ausserhalb des Wurzelverzeichnisses.
	 *
	 * @param string $root
	 * @param string $relative
	 * @return string|null
	 */
	public static function resolve( $root, $relative ) {
		$roots = self::roots();
		$key   = isset( $roots[ $root ] ) ? $root : 'content';
		$base  = realpath( $roots[ $key ] );

		if ( false === $base ) {
			return null;
		}

		$relative = str_replace( '\\', '/', (string) $relative );

		// Bei "content" enthält der Pfad das Präfix wp-content/ — hier abschneiden.
		if ( 'content' === $key && 0 === strpos( $relative, 'wp-content/' ) ) {
			$relative = substr( $relative, strlen( 'wp-content/' ) );
		}

		if ( '' === $relative || false !== strpos( $relative, "\0" ) ) {
			return null;
		}

		$candidate = realpath( $base . '/' . ltrim( $relative, '/' ) );

		if ( false === $candidate || ! is_file( $candidate ) ) {
			return null;
		}

		// Muss tatsächlich unterhalb des Wurzelverzeichnisses liegen.
		if ( 0 !== strpos( $candidate, $base . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		return $candidate;
	}

	/**
	 * Gibt einen Ausschnitt einer Datei roh aus und beendet den Request.
	 *
	 * Roh statt base64, weil die Kodierung ein Drittel mehr Daten bedeuten würde.
	 *
	 * @param string $absolute
	 * @param int    $offset
	 * @param int    $length
	 */
	public static function stream( $absolute, $offset = 0, $length = 0 ) {
		$size   = (int) filesize( $absolute );
		$offset = max( 0, (int) $offset );
		$length = (int) $length > 0 ? (int) $length : ( $size - $offset );
		$length = max( 0, min( $length, $size - $offset ) );

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . $length );
		header( 'X-NLC-File-Size: ' . $size );
		header( 'X-NLC-Offset: ' . $offset );
		header( 'X-NLC-Chunk: ' . $length );
		header( 'X-NLC-Sha256: ' . ( $length === $size && $size < 33554432 ? hash_file( 'sha256', $absolute ) : '' ) );

		$handle = fopen( $absolute, 'rb' );
		if ( ! $handle ) {
			status_header( 500 );
			exit;
		}

		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		$remaining = $length;
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$read = fread( $handle, (int) min( 262144, $remaining ) );
			if ( false === $read || '' === $read ) {
				break;
			}
			echo $read;
			$remaining -= strlen( $read );

			if ( ob_get_level() > 0 ) {
				ob_flush();
			}
			flush();
		}

		fclose( $handle );
		exit;
	}

	/* --------------------------------------------------------- Datenbank */

	/**
	 * Startet einen Datenbank-Export.
	 *
	 * @return array<string,mixed>
	 */
	public static function start_database() {
		global $wpdb;

		self::cleanup();

		$dir = self::work_dir();
		if ( is_wp_error( $dir ) ) {
			return array( 'error' => $dir->get_error_message() );
		}

		$tables = $wpdb->get_col( 'SHOW TABLES' );
		$mine   = array();

		foreach ( $tables as $table ) {
			// Nur Tabellen dieser Installation — auf geteilten Datenbanken liegen
			// oft mehrere WordPress-Instanzen nebeneinander.
			if ( 0 === strpos( (string) $table, $wpdb->prefix ) ) {
				$mine[] = (string) $table;
			}
		}

		$file = $dir . '/db-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false ) . '.sql.gz';

		$handle = gzopen( $file, 'wb6' );
		if ( ! $handle ) {
			return array( 'error' => 'Zieldatei für den Datenbank-Export konnte nicht angelegt werden.' );
		}

		gzwrite( $handle, "-- NorthLab Datenbank-Export\n" );
		gzwrite( $handle, '-- ' . gmdate( 'c' ) . "\n" );
		gzwrite( $handle, '-- Host: ' . home_url() . "\n" );
		gzwrite( $handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n" );
		gzclose( $handle );

		$job = array(
			'file'    => $file,
			'tables'  => $mine,
			'index'   => 0,
			'offset'  => 0,
			'rows'    => 0,
			'started' => time(),
		);

		update_option( self::OPT_DB_JOB, $job, false );

		return array(
			'started' => true,
			'tables'  => count( $mine ),
			'file'    => self::relative_work_path( $file ),
		);
	}

	/**
	 * Arbeitet ein Stück am Export weiter.
	 *
	 * Der Aufrufer ruft so lange auf, bis "done" wahr ist. Damit bleibt jeder
	 * einzelne Request kurz genug für die üblichen Zeitlimits.
	 *
	 * @return array<string,mixed>
	 */
	public static function step_database() {
		global $wpdb;

		$job = get_option( self::OPT_DB_JOB, null );

		if ( ! is_array( $job ) || empty( $job['file'] ) ) {
			return array( 'error' => 'Kein laufender Datenbank-Export.' );
		}
		if ( ! file_exists( $job['file'] ) ) {
			delete_option( self::OPT_DB_JOB );
			return array( 'error' => 'Die Exportdatei ist verschwunden. Bitte neu starten.' );
		}

		$handle = gzopen( $job['file'], 'ab' );
		if ( ! $handle ) {
			return array( 'error' => 'Exportdatei nicht beschreibbar.' );
		}

		$deadline = microtime( true ) + self::STEP_SECONDS;

		while ( $job['index'] < count( $job['tables'] ) && microtime( true ) < $deadline ) {
			$table = $job['tables'][ $job['index'] ];

			// Struktur nur beim ersten Durchgang der Tabelle.
			if ( 0 === (int) $job['offset'] ) {
				$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . str_replace( '`', '', $table ) . '`', ARRAY_N );

				gzwrite( $handle, "\n-- Tabelle {$table}\n" );
				gzwrite( $handle, 'DROP TABLE IF EXISTS `' . $table . "`;\n" );

				if ( isset( $create[1] ) ) {
					gzwrite( $handle, $create[1] . ";\n" );
				}
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM `' . str_replace( '`', '', $table ) . '` LIMIT %d OFFSET %d',
					self::ROWS_PER_BATCH,
					(int) $job['offset']
				),
				ARRAY_A
			);

			if ( ! $rows ) {
				$job['index']++;
				$job['offset'] = 0;
				continue;
			}

			foreach ( $rows as $row ) {
				$values = array();

				foreach ( $row as $value ) {
					if ( null === $value ) {
						$values[] = 'NULL';
					} elseif ( is_numeric( $value ) && ! is_string( $value ) ) {
						$values[] = $value;
					} else {
						$values[] = "'" . esc_sql( (string) $value ) . "'";
					}
				}

				gzwrite( $handle, 'INSERT INTO `' . $table . '` VALUES (' . implode( ',', $values ) . ");\n" );
			}

			$job['offset'] += count( $rows );
			$job['rows']   += count( $rows );
		}

		$done = $job['index'] >= count( $job['tables'] );

		if ( $done ) {
			gzwrite( $handle, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
		}

		gzclose( $handle );

		if ( $done ) {
			delete_option( self::OPT_DB_JOB );

			clearstatcache( true, $job['file'] );

			return array(
				'done'   => true,
				'file'   => self::relative_work_path( $job['file'] ),
				'size'   => (int) filesize( $job['file'] ),
				'rows'   => (int) $job['rows'],
				'tables' => count( $job['tables'] ),
			);
		}

		update_option( self::OPT_DB_JOB, $job, false );

		return array(
			'done'     => false,
			'table'    => $job['tables'][ $job['index'] ] ?? '',
			'progress' => count( $job['tables'] ) > 0 ? round( $job['index'] / count( $job['tables'] ) * 100 ) : 0,
			'rows'     => (int) $job['rows'],
		);
	}

	/* ----------------------------------------------------------- Aufräumen */

	/**
	 * Entfernt Zwischenstände. Wird nach dem Abholen und vor jedem Start gerufen.
	 *
	 * @return int Zahl gelöschter Dateien.
	 */
	public static function cleanup() {
		delete_option( self::OPT_DB_JOB );

		$dir = self::work_dir( false );
		if ( is_wp_error( $dir ) || ! is_dir( $dir ) ) {
			return 0;
		}

		$removed = 0;

		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_file( $file ) && @unlink( $file ) ) {
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * Arbeitsverzeichnis unterhalb von uploads, gegen Zugriff von aussen gesichert.
	 *
	 * @param bool $create
	 * @return string|WP_Error
	 */
	public static function work_dir( $create = true ) {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'nlc_uploads', $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::WORK_DIR;

		if ( ! $create ) {
			return $dir;
		}

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'nlc_mkdir', 'Arbeitsverzeichnis konnte nicht angelegt werden: ' . $dir );
		}

		// Der Export enthält alle Inhalte der Datenbank und darf niemals
		// über den Browser erreichbar sein.
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Deny from all\nRequire all denied\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Kein direkter Zugriff.\n" );
		}

		return $dir;
	}

	/**
	 * Pfad relativ zu wp-content, so wie ihn das Panel zum Abholen braucht.
	 *
	 * @param string $absolute
	 * @return string
	 */
	protected static function relative_work_path( $absolute ) {
		$content = str_replace( '\\', '/', WP_CONTENT_DIR );
		$path    = str_replace( '\\', '/', $absolute );

		return 'wp-content' . substr( $path, strlen( $content ) );
	}

	/**
	 * Grobe Schätzung des Sicherungsumfangs — für die Anzeige im Panel.
	 *
	 * @return array<string,mixed>
	 */
	public static function estimate() {
		global $wpdb;

		$db = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			)
		);

		$manifest = self::manifest( 'content', 0, 1 );

		return array(
			'database_mb' => $db ? round( ( (float) $db ) / 1048576, 2 ) : 0.0,
			'files'       => (int) $manifest['total'],
		);
	}
}
