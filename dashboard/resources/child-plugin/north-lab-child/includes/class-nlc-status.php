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
		add_action( 'wp_dashboard_setup', array( $this, 'widget' ) );
		add_action( 'admin_head', array( $this, 'menu_style' ) );
		add_action( 'admin_post_nlc_restore', array( $this, 'handle_restore' ) );
	}

	/**
	 * Das Logo der Agentur als Menuesymbol — einfarbig.
	 *
	 * Als CSS-Maske, nicht als Bild: dabei zaehlt nur, wo etwas
	 * undurchsichtig ist, die Farbe kommt von WordPress selbst. Dadurch ist
	 * das Symbol grau wie die anderen und weiss, wenn der Punkt aktiv ist,
	 * statt als buntes Logo danebenzustehen. Ein Bild als Maske wird
	 * ausserdem nie ausgefuehrt.
	 *
	 * Ohne hinterlegtes Logo bleibt das Schild aus dem Dashicons-Satz.
	 */
	public function menu_style() {
		$glyph = (string) NLC_Branding::state()['menu_icon'];

		if ( '' === $glyph ) {
			return;
		}

		$bild = sprintf( 'url("%s")', esc_url_raw( $glyph ) );

		printf(
			'<style id="nlc-menu-symbol">'
				. '#adminmenu .toplevel_page_%1$s .wp-menu-image::before{content:"";display:block;'
				. 'width:20px;height:20px;margin:7px auto 0;background-color:currentColor;'
				. '-webkit-mask:%2$s no-repeat center/contain;mask:%2$s no-repeat center/contain}'
				. '</style>',
			esc_attr( self::SLUG ),
			// Die Daten-URI ist bereits geprueft (NLC_Branding::glyph) und
			// enthaelt nur Base64-Zeichen — in einem CSS-Wert kann sie nichts
			// anrichten. esc_url_raw oben nimmt zusaetzlich alles heraus, was
			// aus dem url() ausbrechen koennte.
			$bild // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Eine Kachel auf der Startseite des Backends.
	 *
	 * Das eigene Menue findet, wer danach sucht. Hier landet jeder, der sich
	 * anmeldet — und genau da gehoert die Antwort auf "wird meine Seite
	 * eigentlich betreut?" hin, in einem Satz.
	 */
	public function widget() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'nlc_betreuung',
			sprintf( 'Betreut von %s', self::agency() ),
			array( $this, 'render_widget' )
		);
	}

	public function render_widget() {
		$wartung = NLC_Maintlog::latest();
		$sicher  = NLC_Backuplog::latest();
		$stand   = NLC_Maintlog::state();
		$laeuft  = NLC_Backuplog::running();

		echo '<ul style="margin:0">';

		if ( $laeuft ) {
			printf(
				'<li><strong>%s</strong></li>',
				esc_html( 'Es läuft gerade eine Sicherung.' )
			);
		}

		printf(
			'<li>Zuletzt gewartet: <strong>%s</strong>%s</li>',
			esc_html( null !== $wartung ? self::seit( (string) $wartung['at'] ) : 'noch nicht' ),
			null !== $wartung ? ' — ' . esc_html( NLC_Maintlog::summary( $wartung ) ) : ''
		);

		printf(
			'<li>Zuletzt gesichert: <strong>%s</strong></li>',
			esc_html( null !== $sicher ? self::seit( (string) $sicher['at'] ) : 'noch nicht' )
		);

		if ( isset( $stand['pending'] ) ) {
			$offen = (int) $stand['pending'];

			printf(
				'<li>Offene Updates: <strong>%d</strong>%s</li>',
				$offen,
				$offen > 0 ? esc_html( ' — wir kümmern uns darum' ) : esc_html( ' — alles aktuell' )
			);
		}

		echo '</ul>';

		printf(
			'<p style="margin-bottom:0"><a href="%s">Alle Einzelheiten</a>%s</p>',
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			'' !== (string) ( $stand['contact'] ?? '' )
				? ' &middot; <a href="mailto:' . esc_attr( (string) $stand['contact'] ) . '">Kontakt</a>'
				: ''
		);
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
			// Liegt ein Logo vor, zeichnet menu_style() es per Maske in die
			// leere Flaeche. Sonst bleibt das Schild.
			'' !== (string) NLC_Branding::state()['menu_icon'] ? 'none' : 'dashicons-shield-alt',
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

	/**
	 * Vier Kacheln ganz oben: was ein Kunde wissen will, ohne zu scrollen.
	 *
	 * Die Zahlen kommen aus zwei Quellen — was WordPress selbst weiss, und
	 * was das Panel geschickt hat. Die Restlaufzeit des Zertifikats etwa
	 * kennt nur das Panel, weil sie aus der Ueberwachung kommt. Fehlt eine
	 * Angabe, steht ein Strich da und keine erfundene Null.
	 *
	 * @param array<string,mixed>            $stand
	 * @param array<int,array<string,mixed>> $log
	 * @param array<int,array<string,mixed>> $wartung
	 */
	protected function stand_karten( array $stand, array $log, array $wartung ) {
		$letzteWartung = $wartung ? $wartung[0] : null;
		$letzteSicher  = $log ? $log[0] : null;
		$tage          = isset( $stand['ssl_days'] ) && null !== $stand['ssl_days'] ? (int) $stand['ssl_days'] : null;
		$offen         = isset( $stand['pending'] ) ? (int) $stand['pending'] : null;

		$kachel = static function ( $titel, $wert, $zusatz = '', $farbe = '' ) {
			printf(
				'<div style="flex:1 1 180px;min-width:180px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 14px">'
					. '<div style="font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.04em">%s</div>'
					. '<div style="font-size:20px;font-weight:600;margin-top:4px%s">%s</div>'
					. '<div style="font-size:12px;color:#646970;margin-top:2px">%s</div></div>',
				esc_html( $titel ),
				'' !== $farbe ? ';color:' . esc_attr( $farbe ) : '',
				esc_html( $wert ),
				esc_html( $zusatz )
			);
		};
		?>
		<div style="display:flex;flex-wrap:wrap;gap:12px;margin:16px 0 24px;max-width:860px">
			<?php
			$kachel(
				'Zuletzt gewartet',
				null !== $letzteWartung ? self::seit( (string) $letzteWartung['at'] ) : '—',
				null !== $letzteWartung ? NLC_Maintlog::summary( $letzteWartung ) : 'noch nichts eingespielt'
			);

			$kachel(
				'Zuletzt gesichert',
				null !== $letzteSicher ? self::seit( (string) $letzteSicher['at'] ) : '—',
				null !== $letzteSicher ? NLC_Backuplog::size( (int) ( $letzteSicher['bytes'] ?? 0 ) ) : 'noch keine Sicherung'
			);

			$kachel(
				'Offene Updates',
				null === $offen ? '—' : (string) $offen,
				null === $offen ? 'wird beim nächsten Abgleich gemeldet'
					: ( 0 === $offen ? 'alles aktuell' : 'wir kümmern uns darum' ),
				null !== $offen && $offen > 0 ? '#996800' : ''
			);

			$kachel(
				'Zertifikat',
				null === $tage ? '—' : sprintf( '%d Tage', $tage ),
				null === $tage ? 'wird überwacht' : ( $tage > 21 ? 'läuft automatisch weiter' : 'erneuert sich demnächst' ),
				null !== $tage && $tage <= 14 ? '#b32d2e' : ''
			);
			?>
		</div>
		<?php
	}

	/**
	 * "vor 3 Tagen" — oder ein Strich, wenn der Zeitpunkt nicht lesbar ist.
	 *
	 * @param string $iso
	 * @return string
	 */
	protected static function seit( $iso ) {
		$ts = strtotime( (string) $iso );

		if ( ! $ts ) {
			return '—';
		}

		if ( time() - $ts < 90 ) {
			return 'gerade eben';
		}

		return 'vor ' . human_time_diff( $ts );
	}

	/**
	 * Der Abschnitt zum Zurückspielen.
	 *
	 * Steht bewusst über der Wartung und nicht ganz unten: wer hier landet,
	 * hat in der Regel ein Problem.
	 *
	 * Der Kunde kann das Zurückspielen auslösen, aber nichts daran ändern.
	 * Welcher Stand das ist und was davon zurückgeht, hat die Agentur beim
	 * Ausgeben des Codes festgelegt.
	 */
	protected function zurueckspielen() {
		if ( ! NLC_Options::setting( 'allow_restore' ) ) {
			return;
		}

		$hinweis = get_transient( 'nlc_restore_hinweis_' . get_current_user_id() );

		if ( is_array( $hinweis ) ) {
			delete_transient( 'nlc_restore_hinweis_' . get_current_user_id() );

			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				empty( $hinweis['ok'] ) ? 'error' : 'success',
				esc_html( (string) ( $hinweis['text'] ?? '' ) )
			);
		}

		$lauf = NLC_Restore::remote_state();
		?>
		<h2 class="title">Sicherung zurückspielen</h2>

		<?php if ( ! empty( $lauf['running'] ) ) : ?>
			<div class="notice notice-info">
				<p>
					<strong>Es wird gerade zurückgespielt.</strong>
					<?php echo esc_html( (string) ( $lauf['phase'] ?? '' ) ); ?>
				</p>
				<p class="description" style="margin-bottom:8px">
					Die Website ist währenddessen im Wartungsmodus. Bitte in dieser Zeit nichts
					bearbeiten — es würde gleich wieder überschrieben.
				</p>
			</div>
		<?php else : ?>
			<p style="max-width:760px">
				Wenn an dieser Website etwas schiefgegangen ist, können wir einen früheren Stand
				zurückspielen. Damit das nicht versehentlich passiert, braucht es einen Freigabecode
				von <?php echo esc_html( self::agency() ); ?> — melde dich bei uns, wir legen fest,
				welcher Stand zurückgeht, und nennen dir sechs Ziffern.
			</p>
			<p class="description" style="max-width:760px">
				Der Code gilt eine Stunde und nur ein einziges Mal. Vor dem Zurückspielen wird der
				jetzige Stand gesichert — es geht also nichts verloren, was sich nicht wiederholen
				liesse.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px">
				<input type="hidden" name="action" value="nlc_restore">
				<?php wp_nonce_field( 'nlc_restore' ); ?>
				<label for="nlc-code" class="screen-reader-text">Freigabecode</label>
				<input type="text" id="nlc-code" name="code" inputmode="numeric" autocomplete="off"
					pattern="[0-9]*" maxlength="6" placeholder="123456" required
					style="font-size:20px;letter-spacing:.3em;width:160px;text-align:center;font-family:ui-monospace,monospace">
				<button type="submit" class="button button-primary">Zurückspielen starten</button>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Den Freigabecode entgegennehmen.
	 *
	 * Geprüft wird er beim Dashboard — hier wird nur weitergereicht. Der
	 * Hinweis landet in einem Transient, weil danach umgeleitet wird und
	 * eine Meldung in der Adresszeile nichts zu suchen hat.
	 */
	public function handle_restore() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Dafür fehlen die Rechte.', 'north-lab-child' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'nlc_restore' );

		$ergebnis = NLC_Restore::redeem( isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '' );

		set_transient(
			'nlc_restore_hinweis_' . get_current_user_id(),
			array(
				'ok'   => ! empty( $ergebnis['ok'] ),
				'text' => ! empty( $ergebnis['ok'] )
					? sprintf(
						'Freigabe angenommen (%s). Das Zurückspielen beginnt in Kürze — die Website geht '
							. 'währenddessen kurz in den Wartungsmodus.',
						(string) ( $ergebnis['label'] ?? '' )
					)
					: (string) ( $ergebnis['error'] ?? 'Der Code stimmt nicht.' ),
			),
			60
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public function render() {
		$laeuft  = NLC_Backuplog::running();
		$log     = NLC_Backuplog::entries();
		$links   = NLC_Links::result_last();
		$fortsch = NLC_Links::progress();
		$wartung = NLC_Maintlog::entries();
		$stand   = NLC_Maintlog::state();
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

			<?php $this->stand_karten( $stand, $log, $wartung ); ?>

			<?php $this->zurueckspielen(); ?>

			<h2 class="title">Wartung</h2>
			<?php if ( ! $wartung ) : ?>
				<p>
					Hier steht, was wir an dieser Website aktualisiert haben, sobald das erste Mal
					etwas eingespielt wurde.
				</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:860px">
					<thead>
						<tr>
							<th style="width:230px">Zeitpunkt</th>
							<th style="width:150px">Ergebnis</th>
							<th>Was wurde aktualisiert</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $wartung as $eintrag ) : ?>
						<tr>
							<td><?php echo esc_html( self::moment( (string) $eintrag['at'] ) ); ?></td>
							<td>
								<?php if ( empty( $eintrag['ok'] ) ) : ?>
									<span style="color:#b32d2e;font-weight:600">Mit Fehler</span>
								<?php else : ?>
									<span style="color:#1a7f37;font-weight:600"><?php echo esc_html( NLC_Maintlog::summary( $eintrag ) ); ?></span>
								<?php endif; ?>
							</td>
							<td class="description">
								<?php
								$namen = NLC_Maintlog::names( $eintrag );
								echo '' !== $namen ? esc_html( $namen ) : '&mdash;';
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					Vor jeder Aktualisierung wird gesichert. Geht dabei etwas schief, lässt sich der
					Stand davor wiederherstellen.
				</p>
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
