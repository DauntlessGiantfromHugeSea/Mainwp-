<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin-Oberfläche des Child-Plugins: Verbindungscode und Berechtigungen.
 */
class NLC_Admin {

	/** @var NLC_Admin|null */
	protected static $instance = null;

	/** @var string|null Zuletzt erzeugter Code (nur für die aktuelle Ausgabe). */
	protected $fresh_code = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_nlc_generate_code', array( $this, 'handle_generate_code' ) );
		add_action( 'admin_post_nlc_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_nlc_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_notices', array( $this, 'connection_notice' ) );
	}

	public function menu() {
		add_options_page(
			'NorthLab Child',
			'NorthLab',
			'manage_options',
			'north-lab-child',
			array( $this, 'render' )
		);
	}

	public function connection_notice() {
		if ( ! current_user_can( 'manage_options' ) || NLC_Options::is_connected() ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && 'settings_page_north-lab-child' === $screen->id ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( 'NorthLab Child ist noch mit keinem Dashboard verbunden.' ),
			esc_url( admin_url( 'options-general.php?page=north-lab-child' ) ),
			esc_html( 'Jetzt Verbindungscode erzeugen' )
		);
	}

	public function handle_generate_code() {
		$this->guard( 'nlc_generate_code' );
		$code = NLC_Options::generate_connect_code();
		set_transient( 'nlc_fresh_code_' . get_current_user_id(), $code, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'options-general.php?page=north-lab-child&code=1' ) );
		exit;
	}

	public function handle_save_settings() {
		$this->guard( 'nlc_save_settings' );

		$booleans = array( 'allow_updates', 'allow_install', 'allow_user_mgmt', 'allow_maintenance', 'allow_content', 'allow_backup', 'allow_autologin', 'allow_mmode', 'require_ssl' );
		$values   = array();
		foreach ( $booleans as $key ) {
			$values[ $key ] = ! empty( $_POST[ $key ] );
		}
		$values['ip_allowlist'] = isset( $_POST['ip_allowlist'] )
			? sanitize_textarea_field( wp_unslash( $_POST['ip_allowlist'] ) )
			: '';

		NLC_Options::save_settings( $values );

		wp_safe_redirect( admin_url( 'options-general.php?page=north-lab-child&saved=1' ) );
		exit;
	}

	public function handle_disconnect() {
		$this->guard( 'nlc_disconnect' );
		NLC_Options::clear_connection();
		wp_safe_redirect( admin_url( 'options-general.php?page=north-lab-child&disconnected=1' ) );
		exit;
	}

	/**
	 * @param string $action
	 */
	protected function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( $action );
	}

	public function render() {
		$connection = NLC_Options::connection();
		$settings   = NLC_Options::settings();
		$code       = get_transient( 'nlc_fresh_code_' . get_current_user_id() );
		if ( $code ) {
			delete_transient( 'nlc_fresh_code_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1>NorthLab Child</h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Einstellungen gespeichert.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['disconnected'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Verbindung getrennt.</p></div>
			<?php endif; ?>
			<?php if ( ! function_exists( 'openssl_verify' ) ) : ?>
				<div class="notice notice-error"><p><strong>OpenSSL fehlt.</strong> Ohne die PHP-Erweiterung <code>openssl</code> kann keine Verbindung aufgebaut werden.</p></div>
			<?php endif; ?>

			<h2 class="title">Verbindung</h2>
			<?php if ( $connection ) : ?>
				<table class="widefat striped" style="max-width:760px">
					<tbody>
						<tr><th style="width:220px">Dashboard</th><td><?php echo esc_html( $connection['dashboard_name'] ?: $connection['dashboard_url'] ); ?></td></tr>
						<tr><th>Dashboard-URL</th><td><code><?php echo esc_html( $connection['dashboard_url'] ); ?></code></td></tr>
						<tr><th>Verbindungs-ID</th><td><code><?php echo esc_html( $connection['connection_id'] ); ?></code></td></tr>
						<tr><th>Verbunden seit</th><td><?php echo esc_html( $connection['connected_at'] ); ?></td></tr>
						<tr><th>Letzter Kontakt</th><td>
							<?php
							$last = (int) get_option( NLC_Options::OPT_LAST_CONTACT, 0 );
							echo $last ? esc_html( human_time_diff( $last ) . ' her' ) : '—';
							?>
						</td></tr>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
					<?php wp_nonce_field( 'nlc_disconnect' ); ?>
					<input type="hidden" name="action" value="nlc_disconnect">
					<button class="button button-secondary" onclick="return confirm('Verbindung wirklich trennen?')">Verbindung trennen</button>
				</form>
			<?php else : ?>
				<p>Diese Seite ist noch mit keinem Dashboard verbunden. Erzeuge einen Verbindungscode und trage ihn im NorthLab Dashboard ein.</p>
				<?php if ( $code ) : ?>
					<div class="notice notice-success" style="max-width:760px">
						<p><strong>Verbindungscode (60 Minuten gültig, wird nur einmal angezeigt):</strong></p>
						<p><input type="text" readonly value="<?php echo esc_attr( $code ); ?>" style="width:100%;font-family:monospace;font-size:15px;padding:8px" onclick="this.select()"></p>
						<p>Seiten-URL für das Dashboard: <code><?php echo esc_html( home_url() ); ?></code></p>
					</div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'nlc_generate_code' ); ?>
					<input type="hidden" name="action" value="nlc_generate_code">
					<button class="button button-primary">Verbindungscode erzeugen</button>
				</form>
			<?php endif; ?>

			<hr style="margin:28px 0">

			<h2 class="title">Berechtigungen</h2>
			<p class="description" style="max-width:760px">Legt fest, was das Dashboard auf dieser Seite tun darf. Änderungen wirken sofort.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'nlc_save_settings' ); ?>
				<input type="hidden" name="action" value="nlc_save_settings">
				<table class="form-table" role="presentation">
					<?php
					$labels = array(
						'allow_updates'     => 'Core-, Plugin- und Theme-Updates ausführen',
						'allow_install'     => 'Plugins und Themes installieren',
						'allow_user_mgmt'   => 'Benutzer verwalten',
						'allow_maintenance' => 'Wartungsaufgaben ausführen (DB-Cleanup etc.)',
						'allow_content'     => 'Inhalte anlegen',
						'allow_backup'      => 'Dateien und Datenbank für Sicherungen ausliefern',
						'allow_autologin'   => 'Ein-Klick-Anmeldung aus dem Panel erlauben',
						'allow_mmode'       => 'Wartungsmodus aus der Ferne schalten',
						'require_ssl'       => 'Nur HTTPS-Requests akzeptieren',
					);
					foreach ( $labels as $key => $label ) :
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?>>
									aktiv
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="nlc_ip">IP-Allowlist</label></th>
						<td>
							<textarea id="nlc_ip" name="ip_allowlist" rows="3" class="large-text code" placeholder="z. B. 203.0.113.10&#10;2001:db8::/32"><?php echo esc_textarea( $settings['ip_allowlist'] ); ?></textarea>
							<p class="description">Eine IP oder ein CIDR-Bereich pro Zeile. Leer lassen = keine IP-Beschränkung.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Einstellungen speichern' ); ?>
			</form>
		</div>
		<?php
	}
}
