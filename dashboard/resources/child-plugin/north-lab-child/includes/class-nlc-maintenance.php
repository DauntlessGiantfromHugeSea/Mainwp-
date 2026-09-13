<?php
defined( 'ABSPATH' ) || exit;

/**
 * Wartungsaufgaben, die das Dashboard auslösen kann.
 */
class NLC_Maintenance {

	/**
	 * Verfügbare Aufgaben mit Beschreibung — das Dashboard rendert daraus die Auswahl.
	 *
	 * @return array<string,string>
	 */
	public static function tasks() {
		return array(
			'revisions'        => 'Beitragsrevisionen löschen',
			'auto_drafts'      => 'Automatische Entwürfe löschen',
			'trash_posts'      => 'Papierkorb (Beiträge) leeren',
			'spam_comments'    => 'Spam-Kommentare löschen',
			'trash_comments'   => 'Papierkorb (Kommentare) leeren',
			'expired_transients' => 'Abgelaufene Transients entfernen',
			'orphan_postmeta'  => 'Verwaiste Postmeta entfernen',
			'orphan_commentmeta' => 'Verwaiste Commentmeta entfernen',
			'optimize_tables'  => 'Datenbanktabellen optimieren',
			'flush_cache'      => 'Object-Cache leeren',
			'flush_rewrites'   => 'Permalinks neu schreiben',
		);
	}

	/**
	 * @param array<int,string> $tasks
	 * @return array<int,array<string,mixed>>
	 */
	public static function run( array $tasks ) {
		if ( ! NLC_Options::setting( 'allow_maintenance' ) ) {
			return array( self::result( 'maintenance', false, 'Wartungsaufgaben sind auf dieser Seite deaktiviert.', 0 ) );
		}

		$available = self::tasks();
		$results   = array();

		foreach ( $tasks as $task ) {
			$task = (string) $task;
			if ( ! isset( $available[ $task ] ) ) {
				$results[] = self::result( $task, false, 'Unbekannte Aufgabe.', 0 );
				continue;
			}
			$results[] = self::run_task( $task );
		}

		return $results;
	}

	/**
	 * @param string $task
	 * @return array<string,mixed>
	 */
	protected static function run_task( $task ) {
		global $wpdb;

		switch ( $task ) {
			case 'revisions':
				$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'" );
				foreach ( $ids as $id ) {
					wp_delete_post_revision( (int) $id );
				}
				return self::result( $task, true, 'Revisionen gelöscht.', count( $ids ) );

			case 'auto_drafts':
				$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
				foreach ( $ids as $id ) {
					wp_delete_post( (int) $id, true );
				}
				return self::result( $task, true, 'Automatische Entwürfe gelöscht.', count( $ids ) );

			case 'trash_posts':
				$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash'" );
				foreach ( $ids as $id ) {
					wp_delete_post( (int) $id, true );
				}
				return self::result( $task, true, 'Papierkorb geleert.', count( $ids ) );

			case 'spam_comments':
				$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
				foreach ( $ids as $id ) {
					wp_delete_comment( (int) $id, true );
				}
				return self::result( $task, true, 'Spam-Kommentare gelöscht.', count( $ids ) );

			case 'trash_comments':
				$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'trash'" );
				foreach ( $ids as $id ) {
					wp_delete_comment( (int) $id, true );
				}
				return self::result( $task, true, 'Kommentar-Papierkorb geleert.', count( $ids ) );

			case 'expired_transients':
				$count = self::delete_expired_transients();
				return self::result( $task, true, 'Abgelaufene Transients entfernt.', $count );

			case 'orphan_postmeta':
				$count = (int) $wpdb->query(
					"DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL"
				);
				return self::result( $task, true, 'Verwaiste Postmeta entfernt.', $count );

			case 'orphan_commentmeta':
				$count = (int) $wpdb->query(
					"DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL"
				);
				return self::result( $task, true, 'Verwaiste Commentmeta entfernt.', $count );

			case 'optimize_tables':
				$tables = $wpdb->get_col( 'SHOW TABLES' );
				$done   = 0;
				foreach ( $tables as $table ) {
					if ( 0 !== strpos( (string) $table, $wpdb->prefix ) ) {
						continue;
					}
					// Tabellennamen stammen aus SHOW TABLES und sind nicht per Platzhalter escapebar.
					$wpdb->query( 'OPTIMIZE TABLE `' . str_replace( '`', '', (string) $table ) . '`' );
					$done++;
				}
				return self::result( $task, true, 'Tabellen optimiert.', $done );

			case 'flush_cache':
				wp_cache_flush();
				return self::result( $task, true, 'Object-Cache geleert.', 0 );

			case 'flush_rewrites':
				flush_rewrite_rules( false );
				return self::result( $task, true, 'Permalink-Regeln neu geschrieben.', 0 );
		}

		return self::result( $task, false, 'Aufgabe nicht implementiert.', 0 );
	}

	/**
	 * @return int
	 */
	protected static function delete_expired_transients() {
		global $wpdb;

		$now  = time();
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);

		$count = 0;
		foreach ( $rows as $option ) {
			$key = substr( (string) $option, strlen( '_transient_timeout_' ) );
			if ( delete_transient( $key ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * @param string $task
	 * @param bool   $success
	 * @param string $message
	 * @param int    $affected
	 * @return array<string,mixed>
	 */
	protected static function result( $task, $success, $message, $affected ) {
		return array(
			'task'     => $task,
			'success'  => (bool) $success,
			'message'  => $message,
			'affected' => (int) $affected,
		);
	}
}
