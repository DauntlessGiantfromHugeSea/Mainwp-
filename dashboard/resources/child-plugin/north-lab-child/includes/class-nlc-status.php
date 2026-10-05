<?php
defined( 'ABSPATH' ) || exit;

/**
 * Die Seite, die der Kunde selbst sieht: Sicherungen und Link-Pruefung.
 *
 * Bewusst ein eigener Menuepunkt ganz oben und nicht unter Einstellungen —
 * die Verbindungsseite dort richtet sich an die Agentur, diese hier an den
 * Kunden.
 */
class NLC_Status {

	const SLUG = 'north-lab';

	/** @var NLC_Status|null */
	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_nlc_links_scan', array( $this, 'handle_scan' ) );
		add_action( 'wp_ajax_nlc_fortschritt', array( $this, 'handle_progress' ) );
	}

	/**
	 * Stand der laufenden Sicherung — fuer die Anzeige, die sich selbst
	 * nachfuehrt. Eine Seite, die man neu laden muss, um einen Fortschritt
	 * zu sehen, zeigt keinen Fortschritt.
	 */
	public function handle_progress() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$laeuft = NLC_Backuplog::running();

		if ( null === $laeuft ) {
			wp_send_json_success( array( 'running' => false ) );
		}

		wp_send_json_success(
			array(
				'running' => true,
				'text'    => NLC_Backuplog::progress_text( $laeuft ),
				'percent' => isset( $laeuft['percent'] ) ? $laeuft['percent'] : null,
				'stale'   => NLC_Backuplog::stale( $laeuft ),
				'since'   => human_time_diff( (int) $laeuft['started'] ),
			)
		);
	}

	/**
	 * Wie die Agentur hier heisst.
	 *
	 * @return string
	 */
	public static function agency() {
		$connection = NLC_Options::connection();
		$name       = $connection ? trim( (string) $connection['dashboard_name'] ) : '';

		return '' !== $name ? $name : 'NorthLab';
	}

	public function menu() {
		add_menu_page(
			self::agency(),
			self::agency(),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-shield-alt',
			3
		);
	}

	public function handle_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'nlc_links_scan' );

		NLC_Links::begin();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&scan=1' ) );
		exit;
	}

	/**
	 * Zeitpunkt aus einer ISO-Angabe lesbar machen.
	 *
	 * @param string $iso
	 * @return string
	 */
	protected static function moment( $iso ) {
		$ts = strtotime( (string) $iso );

		if ( ! $ts ) {
			return '—';
		}

		return sprintf(
			'%s (%s her)',
			date_i18n( 'd.m.Y H:i', $ts + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ),
			human_time_diff( $ts )
		);
	}

	public function render() {
		$laeuft  = NLC_Backuplog::running();
		$log     = NLC_Backuplog::entries();
		$links   = NLC_Links::result_last();
		$fortsch = NLC_Links::progress();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( self::agency() ); ?></h1>
			<p class="description" style="max-width:760px">
				Diese Website wird von <?php echo esc_html( self::agency() ); ?> betreut. Hier siehst du,
				wann zuletzt gesichert wurde und ob Links ins Leere zeigen.
			</p>

			<?php if ( isset( $_GET['scan'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Die Link-Prüfung wurde gestartet. Sie läuft im Hintergrund in kleinen Schritten weiter.</p></div>
			<?php endif; ?>

			<?php if ( $laeuft ) : ?>
				<div class="notice notice-info" id="nlc-lauf">
					<p>
						<strong>Es läuft gerade eine Sicherung.</strong>
						Begonnen <span id="nlc-lauf-seit"><?php echo esc_html( human_time_diff( (int) $laeuft['started'] ) ); ?></span> her.
						Die Website kann währenddessen etwas langsamer reagieren.
					</p>
					<p id="nlc-lauf-schritt" style="margin:0 0 6px;color:#50575e">
						<?php echo esc_html( NLC_Backuplog::progress_text( $laeuft ) ); ?>
					</p>
					<div id="nlc-lauf-balken"
						style="<?php echo null === $laeuft['percent'] ? 'display:none;' : ''; ?>max-width:420px;height:6px;border-radius:3px;background:#dcdcde;overflow:hidden;margin-bottom:12px">
						<div id="nlc-lauf-fuellung"
							style="height:6px;border-radius:3px;background:#2271b1;width:<?php echo (int) $laeuft['percent']; ?>%"></div>
					</div>
					<?php if ( NLC_Backuplog::stale( $laeuft ) ) : ?>
						<p id="nlc-lauf-still" style="margin:0 0 8px;color:#996800">
							Seit über zehn Minuten kam keine Meldung mehr. Der Vorgang läuft vielleicht noch,
							die Zahlen oben sind dann aber nicht mehr aktuell.
						</p>
					<?php endif; ?>
				</div>
				<script>
				(function () {
					var kasten  = document.getElementById('nlc-lauf');
					var schritt = document.getElementById('nlc-lauf-schritt');
					var balken  = document.getElementById('nlc-lauf-balken');
					var fuell   = document.getElementById('nlc-lauf-fuellung');
					if (!kasten) { return; }

					var adresse = <?php echo wp_json_encode( admin_url( 'admin-ajax.php?action=nlc_fortschritt' ) ); ?>;
					var leer    = 0;

					function hole() {
						fetch(adresse, { credentials: 'same-origin' })
							.then(function (a) { return a.ok ? a.json() : null; })
							.then(function (a) {
								if (!a || !a.success) { return; }

								// Fertig: die Seite einmal neu laden, dann steht
								// der Lauf in der Tabelle darunter.
								if (!a.data.running) {
									if (++leer >= 2) { location.reload(); }
									return;
								}

								leer = 0;
								schritt.textContent = a.data.text || '';

								if (a.data.percent === null) {
									balken.style.display = 'none';
								} else {
									balken.style.display = '';
									fuell.style.width = a.data.percent + '%';
								}
							})
							// Ein fehlgeschlagener Abruf ist kein Grund, die Seite
							// mit einer Fehlermeldung zu behelligen - beim naechsten
							// Mal klappt es vielleicht wieder.
							.catch(function () {});
					}

					setInterval(hole, 20000);
				})();
				</script>
			<?php endif; ?>

			<h2 class="title">Sicherungen</h2>
			<?php if ( ! $log ) : ?>
				<p>Es ist noch keine Sicherung vermerkt. Sobald die erste gelaufen ist, steht sie hier.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:860px">
					<thead>
						<tr>
							<th style="width:230px">Zeitpunkt</th>
							<th style="width:110px">Ergebnis</th>
							<th style="width:110px">Größe</th>
							<th style="width:100px">Dauer</th>
							<th>Hinweis</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $log as $eintrag ) : ?>
						<tr>
							<td><?php echo esc_html( self::moment( (string) $eintrag['at'] ) ); ?></td>
							<td>
								<?php if ( 'failed' === ( $eintrag['status'] ?? 'ok' ) ) : ?>
									<span style="color:#b32d2e;font-weight:600">Fehlgeschlagen</span>
								<?php else : ?>
									<span style="color:#1a7f37;font-weight:600">Erfolgreich</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( NLC_Backuplog::size( (int) ( $eintrag['bytes'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( NLC_Backuplog::duration( (int) ( $eintrag['seconds'] ?? 0 ) ) ); ?></td>
							<td class="description"><?php echo esc_html( (string) ( $eintrag['message'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					Die Sicherungen liegen außerhalb dieses Servers. Zum Wiederherstellen wende dich an
					<?php echo esc_html( self::agency() ); ?>.
				</p>
			<?php endif; ?>

			<hr style="margin:28px 0">

			<h2 class="title">Link-Prüfung</h2>

			<?php if ( ! empty( $fortsch['running'] ) ) : ?>
				<p>
					<strong>Läuft gerade.</strong>
					<?php if ( 'collect' === $fortsch['phase'] ) : ?>
						Die Links werden gerade eingesammelt.
					<?php else : ?>
						<?php echo esc_html( sprintf( '%d von %d geprüft.', (int) $fortsch['done'], (int) $fortsch['total'] ) ); ?>
					<?php endif; ?>
					Die Prüfung läuft in kleinen Schritten, damit die Website nicht ausgebremst wird.
				</p>
			<?php endif; ?>

			<?php if ( null === $links ) : ?>
				<p>Es liegt noch kein Ergebnis vor. Die Prüfung läuft automatisch einmal pro Woche.</p>
			<?php else : ?>
				<p>
					Zuletzt geprüft: <?php echo esc_html( self::moment( (string) $links['at'] ) ); ?> —
					<?php echo esc_html( sprintf( '%d Adressen geprüft', (int) $links['checked'] ) ); ?>.
				</p>

				<?php $kaputt = (array) $links['broken']; ?>
				<?php if ( ! $kaputt ) : ?>
					<p><strong>Kein toter Link gefunden.</strong></p>
				<?php else : ?>
					<h3><?php echo esc_html( sprintf( '%d tote Links', count( $kaputt ) ) ); ?></h3>
					<table class="widefat striped" style="max-width:1000px">
						<thead><tr><th>Adresse</th><th style="width:90px">Antwort</th><th style="width:280px">Steht in</th></tr></thead>
						<tbody>
						<?php foreach ( $kaputt as $fund ) : ?>
							<tr>
								<td style="word-break:break-all"><code><?php echo esc_html( (string) $fund['url'] ); ?></code>
									<div class="description"><?php echo esc_html( (string) $fund['reason'] ); ?></div>
								</td>
								<td><?php echo esc_html( $fund['status'] ? (string) $fund['status'] : '—' ); ?></td>
								<td>
									<?php if ( ! empty( $fund['post_id'] ) ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( (int) $fund['post_id'] ) ); ?>">
											<?php echo esc_html( (string) $fund['title'] ); ?>
										</a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php $unklar = (array) $links['unsure']; ?>
				<?php if ( $unklar ) : ?>
					<h3><?php echo esc_html( sprintf( '%d nicht sicher prüfbar', count( $unklar ) ) ); ?></h3>
					<p class="description" style="max-width:760px">
						Diese Adressen haben den Abruf abgewiesen oder mit einem Serverfehler geantwortet.
						Das heißt nicht, dass der Link tot ist — viele Seiten sperren automatische Abrufe aus.
						Sie stehen hier getrennt, damit die Liste oben nur echte Funde enthält.
					</p>
					<table class="widefat striped" style="max-width:1000px">
						<thead><tr><th>Adresse</th><th style="width:90px">Antwort</th><th style="width:280px">Steht in</th></tr></thead>
						<tbody>
						<?php foreach ( $unklar as $fund ) : ?>
							<tr>
								<td style="word-break:break-all"><code><?php echo esc_html( (string) $fund['url'] ); ?></code></td>
								<td><?php echo esc_html( $fund['status'] ? (string) $fund['status'] : '—' ); ?></td>
								<td>
									<?php if ( ! empty( $fund['post_id'] ) ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( (int) $fund['post_id'] ) ); ?>">
											<?php echo esc_html( (string) $fund['title'] ); ?>
										</a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( ! empty( $links['capped'] ) ) : ?>
					<p class="description">Es wurden mehr Funde abgeschnitten — die Liste zeigt die ersten <?php echo esc_html( (string) NLC_Links::MAX_FUNDE ); ?>.</p>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( empty( $fortsch['running'] ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px">
					<?php wp_nonce_field( 'nlc_links_scan' ); ?>
					<input type="hidden" name="action" value="nlc_links_scan">
					<button class="button">Jetzt prüfen</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
