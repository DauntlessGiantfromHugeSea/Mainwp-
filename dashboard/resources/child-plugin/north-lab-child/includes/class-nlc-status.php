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
				<div class="notice notice-info">
					<p><strong>Es läuft gerade eine Sicherung.</strong>
					Begonnen <?php echo esc_html( human_time_diff( (int) $laeuft['started'] ) ); ?> her.
					Die Website kann währenddessen etwas langsamer reagieren.</p>
				</div>
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
