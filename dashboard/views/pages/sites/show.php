<?php
/**
 * @var array<string,mixed>            $site
 * @var array<string,mixed>|null       $client
 * @var array<int,array<string,mixed>> $clients
 * @var array<string,mixed>|null       $payload
 * @var array<int,array<string,mixed>> $updates
 * @var array<string,mixed>            $uptime
 * @var array<int,array<string,mixed>> $incidents
 * @var array<int,array<string,mixed>> $series
 * @var array<int,array<string,mixed>> $activity
 * @var string                         $monitorUrl
 * @var array<string,string>           $tasks
 * @var array<string,mixed>|null       $backupLast
 * @var int                            $backupMirror
 * @var bool                           $backupScheduled
 * @var bool                           $backupReady
 */

use NorthLab\Core\Auth;
use NorthLab\Core\View;
use NorthLab\Repository\SiteRepository;

View::set( 'pageTitle', (string) $site['name'] );

$canWrite = Auth::canWrite();
$siteUrl  = url( '/sites/' . $site['id'] );

// Ohne Child-Plugin gibt es keine Updates, keine Plugin-Liste und keine
// Wartung - die entsprechenden Bereiche werden gar nicht erst gezeigt.
$managed = SiteRepository::isManaged( $site );

$actions = '<a class="btn" target="_blank" rel="noopener" href="' . e( (string) $site['url'] ) . '">Seite öffnen</a>';

if ( $managed ) {
	$actions .= ' <a class="btn" href="' . e( $siteUrl . '/users' ) . '">Benutzer</a>';

	if ( $canWrite ) {
		// POST, damit die Anmeldung nicht von einem fremden Link ausgeloest werden kann.
		$actions .= '<form method="post" action="' . e( $siteUrl . '/login' ) . '" style="display:inline">'
			. csrf_field()
			. '<button class="btn primary" data-busy="Öffne…">Ein-Klick-Anmeldung</button></form>';
	}
}

if ( ! empty( $site['admin_url'] ) ) {
	$actions .= ' <a class="btn" target="_blank" rel="noopener" href="' . e( (string) $site['admin_url'] ) . '">WP-Admin</a>';
}

View::set( 'headerActions', $actions );

$plugins  = (array) ( $payload['plugins'] ?? array() );
$themes   = (array) ( $payload['themes'] ?? array() );
$security = (array) ( $payload['security'] ?? array() );
$env      = (array) ( $payload['environment'] ?? array() );
$content  = (array) ( $payload['content'] ?? array() );
$users    = (array) ( $payload['users'] ?? array() );
$links    = (array) ( $payload['links'] ?? array() );
?>

<?php if ( ! empty( $site['last_error'] ) ) : ?>
	<div class="notice bad"><strong>Letzter Fehler:</strong> <?= e( (string) $site['last_error'] ) ?></div>
<?php endif; ?>

<?php if ( $childOutdated ) : ?>
	<div class="notice warn">
		<strong>Child-Plugin veraltet:</strong>
		installiert ist <?= e( (string) $site['child_version'] ) ?>, das Panel liefert <?= e( $childShipped ) ?>.
		<?php if ( $canWrite ) : ?>
			<form method="post" action="<?= e( $siteUrl . '/child-update' ) ?>" style="display:inline;margin-left:8px">
				<?= csrf_field() ?>
				<button class="btn sm primary" data-busy="Aktualisiere…">Jetzt aktualisieren</button>
			</form>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( ! empty( $site['maintenance_mode'] ) ) : ?>
	<div class="notice warn">
		<strong>Wartungsmodus aktiv.</strong>
		Besucher sehen die Wartungsseite, angemeldete Redakteure arbeiten normal weiter.
		<?php if ( ! empty( $site['maintenance_until'] ) ) : ?>
			Endet automatisch am <?= e( nl_date( (string) $site['maintenance_until'] ) ) ?>.
		<?php else : ?>
			Läuft bis auf Widerruf.
		<?php endif; ?>
		<?php if ( $canWrite ) : ?>
			<form method="post" action="<?= e( $siteUrl . '/maintenance-mode' ) ?>" style="display:inline;margin-left:8px">
				<?= csrf_field() ?>
				<input type="hidden" name="disable" value="1">
				<button class="btn sm" data-busy="Beende…">Jetzt beenden</button>
			</form>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( ! empty( $site['is_paused'] ) ) : ?>
	<div class="notice warn">Diese Seite ist pausiert. Automatische Syncs, Updates und Prüfungen laufen nicht.</div>
<?php endif; ?>

<div class="grid <?= $managed ? 'cols-4' : 'cols-3' ?>" style="margin-bottom:18px">
	<div class="stat">
		<div class="label"><?= $managed ? 'Verbindung' : 'Erreichbarkeit' ?></div>
		<div class="value" style="font-size:18px;margin-top:6px"><?= nl_status_badge( $site ) ?></div>
		<div class="sub"><?= $managed ? 'Sync' : 'Geprüft' ?> <?= e( nl_ago( $site['last_sync_at'] ) ) ?></div>
	</div>
	<?php if ( $managed ) : ?>
		<div class="stat <?= (int) $site['pending_updates'] > 0 ? 'warn' : 'ok' ?>">
			<div class="label">Offene Updates</div>
			<div class="value"><?= e( (string) $site['pending_updates'] ) ?></div>
			<div class="sub">WordPress <?= e( $site['wp_version'] ?: '?' ) ?> · PHP <?= e( $site['php_version'] ?: '?' ) ?></div>
		</div>
	<?php endif; ?>
	<div class="stat <?= e( nl_percent_class( (float) $uptime['percent'] ) ) ?>">
		<div class="label">Verfügbarkeit (30 Tage)</div>
		<div class="value"><?= e( nl_number( (float) $uptime['percent'], 2 ) ) ?> %</div>
		<div class="sub"><?= e( (string) $uptime['incidents'] ) ?> Störung(en) · <?= e( nl_duration( (int) $uptime['downtime_seconds'] ) ) ?> Ausfall</div>
	</div>
	<?php if ( $managed ) : ?>
		<div class="stat <?= e( nl_score_class( (int) $site['security_score'] ) ) ?>">
			<div class="label">Sicherheitswert</div>
			<div class="value"><?= e( (string) $site['security_score'] ) ?></div>
			<div class="sub"><?= e( (string) ( $security['passed'] ?? 0 ) ) ?> von <?= e( (string) ( $security['total'] ?? 0 ) ) ?> Prüfungen bestanden</div>
		</div>
	<?php else : ?>
		<div class="stat">
			<div class="label">Betreuungsart</div>
			<div class="value" style="font-size:18px;margin-top:6px"><span class="badge">Nur Überwachung</span></div>
			<div class="sub">Ohne Child-Plugin — keine Updates, keine Backups</div>
		</div>
	<?php endif; ?>
	<?php
	// Die Spalte kam erst mit Schema 12 dazu, und eine Ansicht fragt nichts
	// selbst bei der Datenbank nach — die Schwelle reicht der Controller durch.
	$sslTage   = isset( $site['ssl_days_left'] ) && null !== $site['ssl_days_left'] ? (int) $site['ssl_days_left'] : null;
	$sslWarn   = (int) ( $sslWarnDays ?? 21 );
	$sslKlasse = null === $sslTage ? '' : ( $sslTage <= 7 ? 'bad' : ( $sslTage <= $sslWarn ? 'warn' : 'ok' ) );
	?>
	<div class="stat <?= e( $sslKlasse ) ?>">
		<div class="label">Zertifikat</div>
		<?php if ( null === $sslTage ) : ?>
			<div class="value" style="font-size:18px;margin-top:6px"><span class="badge">unbekannt</span></div>
			<div class="sub">Noch keine Meldung von Uptime Kuma</div>
		<?php elseif ( $sslTage < 0 ) : ?>
			<div class="value"><?= e( (string) abs( $sslTage ) ) ?></div>
			<div class="sub">Tage <strong>abgelaufen</strong><?= '' !== (string) ( $site['ssl_subject'] ?? '' ) ? ' · ' . e( (string) $site['ssl_subject'] ) : '' ?></div>
		<?php else : ?>
			<div class="value"><?= e( (string) $sslTage ) ?></div>
			<div class="sub">
				Tage Restlaufzeit<?= '' !== (string) ( $site['ssl_subject'] ?? '' ) ? ' · ' . e( (string) $site['ssl_subject'] ) : '' ?>
				<?php if ( ! empty( $site['ssl_checked_at'] ) ) : ?>
					· gemeldet <?= e( nl_ago( (string) $site['ssl_checked_at'] ) ) ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</div>

<div class="tabs">
	<button data-tab="overview" class="active">Überblick</button>
	<?php if ( $managed ) : ?>
		<button data-tab="updates">Updates <?= (int) $site['pending_updates'] > 0 ? '(' . e( (string) $site['pending_updates'] ) . ')' : '' ?></button>
		<button data-tab="plugins">Plugins</button>
		<button data-tab="themes">Themes</button>
		<button data-tab="security">Sicherheit</button>
	<?php endif; ?>
	<button data-tab="uptime">Uptime</button>
	<?php if ( $managed ) : ?>
		<button data-tab="maintenance">Wartung</button>
		<button data-tab="backup">Sicherung</button>
	<?php endif; ?>
	<button data-tab="settings">Einstellungen</button>
	<button data-tab="activity">Protokoll</button>
</div>

<!-- ------------------------------------------------------------ Überblick -->
<div class="tab-panel active" data-tab-panel="overview">
	<div class="grid side">
		<div>
			<div class="card">
				<div class="card-head">
					<h2><?= $managed ? 'Umgebung' : 'Überwachung' ?></h2>
					<div class="spacer"></div>
					<?php if ( $canWrite ) : ?>
						<form method="post" action="<?= e( $siteUrl . '/sync' ) ?>">
							<?= csrf_field() ?>
							<button class="btn sm primary" data-busy="<?= $managed ? 'Sync läuft…' : 'Prüfe…' ?>">
								<?= $managed ? 'Jetzt synchronisieren' : 'Jetzt prüfen' ?>
							</button>
						</form>
					<?php endif; ?>
				</div>
				<div class="card-body">
					<?php if ( ! $managed ) : ?>
						<dl class="meta">
							<dt>Betreuungsart</dt><dd><?= e( SiteRepository::typeLabel( $site ) ) ?></dd>
							<dt>Letzte Prüfung</dt><dd><?= e( nl_ago( $site['last_sync_at'] ) ) ?></dd>
							<dt>Zuletzt online</dt><dd><?= e( nl_ago( $site['last_seen_at'] ) ) ?></dd>
						</dl>
						<p class="small muted mt">
							Diese Seite läuft nicht auf WordPress oder hat kein Child-Plugin. Das Panel prüft
							regelmäßig, ob sie antwortet, und nimmt Statusmeldungen aus dem externen Monitoring
							entgegen. Uptime, Kundenzuordnung, Berichte und Webhooks funktionieren wie gewohnt;
							Updates, Plugin- und Theme-Listen, Sicherheitsprüfung, Wartung und Backups nicht.
						</p>
					<?php elseif ( null === $payload ) : ?>
						<div class="empty">Noch keine Daten. Bitte synchronisieren.</div>
					<?php else : ?>
						<dl class="meta">
							<dt>WordPress</dt><dd><?= e( (string) ( $env['wp_version'] ?? '—' ) ) ?></dd>
							<dt>PHP</dt><dd><?= e( (string) ( $env['php_version'] ?? '—' ) ) ?></dd>
							<dt>MySQL</dt><dd><?= e( (string) ( $env['mysql_version'] ?? '—' ) ) ?></dd>
							<dt>Server</dt><dd class="small"><?= e( (string) ( $env['server_software'] ?? '—' ) ) ?></dd>
							<dt>Child-Plugin</dt>
							<dd>
								<?= e( $site['child_version'] ?: '—' ) ?>
								<?php if ( $childOutdated ) : ?>
									<span class="badge warn">→ <?= e( $childShipped ) ?></span>
									<?php if ( $canWrite ) : ?>
										<form method="post" action="<?= e( $siteUrl . '/child-update' ) ?>" style="display:inline;margin-left:6px">
											<?= csrf_field() ?>
											<button class="btn sm primary" data-busy="Aktualisiere…">Aktualisieren</button>
										</form>
									<?php endif; ?>
								<?php endif; ?>
							</dd>
							<dt>Speicherlimit</dt><dd><?= e( (string) ( $env['memory_limit'] ?? '—' ) ) ?></dd>
							<dt>Datenbank</dt><dd><?= e( nl_bytes( (float) ( $env['db_size_mb'] ?? 0 ) ) ) ?></dd>
							<dt>Uploads</dt><dd><?= e( nl_bytes( (float) ( $env['uploads_size_mb'] ?? 0 ) ) ) ?></dd>
							<dt>Freier Speicher</dt><dd><?= e( nl_bytes( (float) ( $env['disk_free_mb'] ?? 0 ) ) ) ?></dd>
							<dt>WP-Cron</dt>
							<dd><?= ! empty( $env['wp_cron_disabled'] ) ? '<span class="badge warn">deaktiviert</span>' : '<span class="badge ok">aktiv</span>' ?></dd>
							<dt>Objekt-Cache</dt>
							<dd><?= ! empty( $payload['health']['object_cache'] ) ? '<span class="badge ok">aktiv</span>' : '<span class="badge">keiner</span>' ?></dd>
						</dl>
					<?php endif; ?>
				</div>
			</div>

			<?php
			$blocked = array_values( array_filter( $features, static fn( array $f ): bool => ! $f['ok'] ) );
			?>
			<?php if ( $managed && $blocked ) : ?>
				<div class="card">
					<div class="card-head">
						<h2>Nicht verfügbar auf dieser Seite</h2>
						<div class="spacer"></div>
						<span class="badge warn"><?= e( (string) count( $blocked ) ) ?></span>
					</div>
					<div class="card-body">
						<p class="small muted">
							Wenn eine Aktion im Panel scheitert, steht der Grund hier — meist eine zu alte
							Child-Version oder eine abgeschaltete Freigabe auf der Kundenseite.
						</p>
						<dl class="meta">
							<?php foreach ( $blocked as $feature ) : ?>
								<dt><?= e( $feature['label'] ) ?></dt>
								<dd class="small"><?= e( (string) $feature['reason'] ) ?></dd>
							<?php endforeach; ?>
						</dl>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $payload ) : ?>
				<div class="card">
					<div class="card-head"><h2>Inhalte und Benutzer</h2></div>
					<div class="card-body">
						<div class="grid cols-4">
							<div><div class="muted small">Beiträge</div><strong><?= e( nl_number( (int) ( $content['posts'] ?? 0 ) ) ) ?></strong></div>
							<div><div class="muted small">Seiten</div><strong><?= e( nl_number( (int) ( $content['pages'] ?? 0 ) ) ) ?></strong></div>
							<div><div class="muted small">Medien</div><strong><?= e( nl_number( (int) ( $content['attachments'] ?? 0 ) ) ) ?></strong></div>
							<div><div class="muted small">Revisionen</div><strong><?= e( nl_number( (int) ( $content['revisions'] ?? 0 ) ) ) ?></strong></div>
							<div><div class="muted small">Kommentare</div><strong><?= e( nl_number( (int) ( $content['comments'] ?? 0 ) ) ) ?></strong></div>
							<div><div class="muted small">Unmoderiert</div><strong><?= e( nl_number( (int) ( $content['comments_pending'] ?? 0 ) ) ) ?></strong></div>
							<div><div class="muted small">Spam</div><strong><?= e( nl_number( (int) ( $content['comments_spam'] ?? 0 ) ) ) ?></strong></div>
							<div><div class="muted small">Benutzer</div><strong><?= e( nl_number( (int) ( $users['total'] ?? 0 ) ) ) ?></strong></div>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<div>
			<div class="card">
				<div class="card-head"><h3>Stammdaten</h3></div>
				<div class="card-body">
					<dl class="meta">
						<dt>URL</dt><dd class="truncate"><a href="<?= e( (string) $site['url'] ) ?>" target="_blank" rel="noopener"><?= e( nl_host( (string) $site['url'] ) ) ?></a></dd>
						<dt>Kunde</dt>
						<dd>
							<?php if ( $client ) : ?>
								<a href="<?= e( url( '/clients/' . $client['id'] ) ) ?>"><?= e( (string) $client['name'] ) ?></a>
							<?php else : ?>
								<span class="muted">—</span>
							<?php endif; ?>
						</dd>
						<dt>Tags</dt>
						<dd>
							<?php $siteTags = \NorthLab\Repository\SiteRepository::tags( $site ); ?>
							<?php if ( $siteTags ) : ?>
								<?php foreach ( $siteTags as $tag ) : ?><span class="tag"><?= e( $tag ) ?></span><?php endforeach; ?>
							<?php else : ?>
								<span class="muted">—</span>
							<?php endif; ?>
						</dd>
						<dt>Verbunden</dt><dd><?= e( nl_date( $site['created_at'] ) ) ?></dd>
						<dt>Letzter Kontakt</dt><dd><?= e( nl_ago( $site['last_seen_at'] ) ) ?></dd>
					</dl>

					<?php if ( ! empty( $site['notes'] ) ) : ?>
						<div class="mt small" style="white-space:pre-wrap"><?= e( (string) $site['notes'] ) ?></div>
					<?php endif; ?>
				</div>
			</div>

			<div class="card">
				<div class="card-head"><h3>Monitoring-Webhook</h3></div>
				<div class="card-body">
					<p class="small muted">Diese URL im externen Monitoring als Webhook hinterlegen — jede Statusmeldung landet direkt hier.</p>
					<div class="copy-row">
						<input type="text" id="monitor-url" readonly value="<?= e( $monitorUrl ) ?>" class="mono">
						<button class="btn sm" type="button" data-copy="#monitor-url">Kopieren</button>
					</div>
					<?php if ( $canWrite ) : ?>
						<form method="post" action="<?= e( $siteUrl . '/token' ) ?>" class="mt"
							data-confirm="Neues Token erzeugen? Die alte URL wird sofort ungültig.">
							<?= csrf_field() ?>
							<button class="btn sm">Token neu erzeugen</button>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>

<?php if ( $managed ) : ?>
<!-- -------------------------------------------------------------- Updates -->
<div class="tab-panel" data-tab-panel="updates">
	<div class="card">
		<div class="card-head">
			<h2>Verfügbare Updates</h2>
			<div class="spacer"></div>
			<?php if ( $canWrite && $updates ) : ?>
				<form method="post" action="<?= e( url( '/updates/apply' ) ) ?>"
					data-confirm="Alle offenen Updates dieser Seite einspielen?">
					<?= csrf_field() ?>
					<input type="hidden" name="mode" value="site">
					<input type="hidden" name="site_id" value="<?= e( (string) $site['id'] ) ?>">
					<button class="btn primary sm" data-busy="läuft…">Alle einspielen</button>
				</form>
			<?php endif; ?>
		</div>
		<div class="card-body tight">
			<?php if ( ! $updates ) : ?>
				<div class="empty"><strong>Alles aktuell</strong>Für diese Seite liegen keine Updates vor.</div>
			<?php else : ?>
				<form method="post" action="<?= e( url( '/updates/apply' ) ) ?>" data-selection-scope id="site-updates">
					<?= csrf_field() ?>
					<input type="hidden" name="mode" value="selected">

					<div class="table-wrap">
						<table class="data">
							<thead>
							<tr>
								<?php if ( $canWrite ) : ?>
									<th class="shrink"><input type="checkbox" data-check-all="#site-updates"></th>
								<?php endif; ?>
								<th>Erweiterung</th><th>Typ</th><th>Installiert</th><th>Verfügbar</th><th></th>
							</tr>
							</thead>
							<tbody>
							<?php foreach ( $updates as $row ) : ?>
								<tr>
									<?php if ( $canWrite ) : ?>
										<td class="shrink">
											<input type="checkbox" data-item name="items[]"
												value="<?= e( $site['id'] . '|' . $row['type'] . '|' . $row['slug'] ) ?>"
												<?= ! empty( $row['is_ignored'] ) ? 'disabled' : '' ?>>
										</td>
									<?php endif; ?>
									<td>
										<strong><?= e( (string) $row['name'] ) ?></strong>
										<div class="muted small mono"><?= e( (string) $row['slug'] ) ?></div>
									</td>
									<td><span class="badge info"><?= e( nl_update_type( (string) $row['type'] ) ) ?></span></td>
									<td class="mono small"><?= e( (string) $row['current_version'] ) ?></td>
									<td class="mono small"><strong><?= e( (string) $row['new_version'] ) ?></strong></td>
									<td class="shrink">
										<?php if ( ! empty( $row['is_ignored'] ) ) : ?>
											<span class="badge">ignoriert</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<?php if ( $canWrite ) : ?>
						<div class="card-foot">
							<button class="btn primary" data-selection-disable disabled data-busy="läuft…">
								Auswahl einspielen (<span data-selection-count>0</span>)
							</button>
						</div>
					<?php endif; ?>
				</form>
			<?php endif; ?>
		</div>
	</div>
</div>

<!-- -------------------------------------------------------------- Plugins -->
<?php
$outdatedPlugins = array_values(
	array_filter( $plugins, static fn( array $plugin ): bool => ! empty( $plugin['new_version'] ) )
);
?>
<div class="tab-panel" data-tab-panel="plugins">
	<div class="card">
		<div class="card-head">
			<h2><?= e( (string) count( $plugins ) ) ?> Plugin(s)</h2>
			<?php if ( $outdatedPlugins ) : ?>
				<span class="badge warn"><?= e( (string) count( $outdatedPlugins ) ) ?> mit Update</span>
			<?php endif; ?>
			<div class="spacer"></div>
			<?php if ( $canWrite && $outdatedPlugins ) : ?>
				<form method="post" action="<?= e( url( '/updates/apply' ) ) ?>"
					data-confirm="Alle <?= e( (string) count( $outdatedPlugins ) ) ?> Plugin-Updates dieser Seite jetzt einspielen?">
					<?= csrf_field() ?>
					<input type="hidden" name="mode" value="selected">
					<?php foreach ( $outdatedPlugins as $outdated ) : ?>
						<input type="hidden" name="items[]"
							value="<?= e( $site['id'] . '|plugin|' . ( $outdated['file'] ?? '' ) ) ?>">
					<?php endforeach; ?>
					<button class="btn sm primary" data-busy="Aktualisiere…">Alle Plugin-Updates einspielen</button>
				</form>
			<?php endif; ?>
		</div>
		<div class="card-body tight">
			<?php if ( ! $plugins ) : ?>
				<div class="empty">Keine Daten. Bitte synchronisieren.</div>
			<?php else : ?>
				<div class="table-wrap">
					<table class="data">
						<thead><tr><th>Plugin</th><th>Version</th><th>Status</th><th>Auto-Update</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $plugins as $plugin ) : ?>
							<tr>
								<td>
									<strong><?= e( (string) ( $plugin['name'] ?? '' ) ) ?></strong>
									<div class="muted small mono"><?= e( (string) ( $plugin['file'] ?? '' ) ) ?></div>
								</td>
								<td class="mono small">
									<?= e( (string) ( $plugin['version'] ?? '' ) ) ?>
									<?php if ( ! empty( $plugin['new_version'] ) ) : ?>
										<span class="badge warn">→ <?= e( (string) $plugin['new_version'] ) ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?= ! empty( $plugin['active'] )
										? '<span class="badge ok"><span class="dot"></span>Aktiv</span>'
										: '<span class="badge">Inaktiv</span>' ?>
								</td>
								<td class="small muted"><?= ! empty( $plugin['auto_update'] ) ? 'an' : 'aus' ?></td>
								<td class="shrink nowrap">
									<?php if ( $canWrite ) : ?>
										<div class="btn-row">
											<?php if ( ! empty( $plugin['new_version'] ) ) : ?>
												<?php /* Eigenes Formular: die Update-Zentrale nimmt ein anderes Format. */ ?>
												<form method="post" action="<?= e( url( '/updates/apply' ) ) ?>" style="display:inline">
													<?= csrf_field() ?>
													<input type="hidden" name="mode" value="selected">
													<input type="hidden" name="items[]"
														value="<?= e( $site['id'] . '|plugin|' . ( $plugin['file'] ?? '' ) ) ?>">
													<button class="btn sm primary" data-busy="Aktualisiere…">Aktualisieren</button>
												</form>
											<?php endif; ?>

											<form method="post" action="<?= e( $siteUrl . '/extensions' ) ?>" style="display:inline"
												data-confirm="Aktion wirklich ausführen?">
												<?= csrf_field() ?>
												<input type="hidden" name="kind" value="plugin">
												<input type="hidden" name="target" value="<?= e( (string) ( $plugin['file'] ?? '' ) ) ?>">
												<?php if ( ! empty( $plugin['active'] ) ) : ?>
													<button class="btn sm" name="extension_action" value="deactivate">Deaktivieren</button>
												<?php else : ?>
													<button class="btn sm" name="extension_action" value="activate">Aktivieren</button>
												<?php endif; ?>
											</form>

											<?php if ( empty( $plugin['active'] ) ) : ?>
												<form method="post" action="<?= e( $siteUrl . '/extensions' ) ?>" style="display:inline"
													data-confirm="Plugin &quot;<?= e( (string) ( $plugin['name'] ?? '' ) ) ?>&quot; wirklich löschen? Das lässt sich nicht rückgängig machen.">
													<?= csrf_field() ?>
													<input type="hidden" name="kind" value="plugin">
													<input type="hidden" name="target" value="<?= e( (string) ( $plugin['file'] ?? '' ) ) ?>">
													<button class="btn sm danger" name="extension_action" value="delete">Löschen</button>
												</form>
											<?php endif; ?>
										</div>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $canWrite ) : ?>
			<div class="card-foot">
				<form method="post" action="<?= e( $siteUrl . '/extensions' ) ?>" class="btn-row">
					<?= csrf_field() ?>
					<input type="hidden" name="kind" value="plugin">
					<input type="hidden" name="extension_action" value="install">
					<input type="text" name="targets[]" placeholder="wordpress.org-Slug, z. B. wordfence" style="max-width:320px">
					<button class="btn" data-busy="Installiere…">Plugin installieren</button>
				</form>
			</div>
		<?php endif; ?>
	</div>
</div>

<!-- --------------------------------------------------------------- Themes -->
<div class="tab-panel" data-tab-panel="themes">
	<div class="card">
		<div class="card-head"><h2><?= e( (string) count( $themes ) ) ?> Theme(s)</h2></div>
		<div class="card-body tight">
			<?php if ( ! $themes ) : ?>
				<div class="empty">Keine Daten. Bitte synchronisieren.</div>
			<?php else : ?>
				<div class="table-wrap">
					<table class="data">
						<thead><tr><th>Theme</th><th>Version</th><th>Status</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $themes as $theme ) : ?>
							<tr>
								<td>
									<strong><?= e( (string) ( $theme['name'] ?? '' ) ) ?></strong>
									<div class="muted small mono"><?= e( (string) ( $theme['stylesheet'] ?? '' ) ) ?></div>
								</td>
								<td class="mono small">
									<?= e( (string) ( $theme['version'] ?? '' ) ) ?>
									<?php if ( ! empty( $theme['new_version'] ) ) : ?>
										<span class="badge warn">→ <?= e( (string) $theme['new_version'] ) ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?= ! empty( $theme['active'] )
										? '<span class="badge ok"><span class="dot"></span>Aktiv</span>'
										: '<span class="badge">Inaktiv</span>' ?>
								</td>
								<td class="shrink nowrap">
									<?php if ( $canWrite ) : ?>
										<div class="btn-row">
											<?php if ( ! empty( $theme['new_version'] ) ) : ?>
												<form method="post" action="<?= e( url( '/updates/apply' ) ) ?>" style="display:inline">
													<?= csrf_field() ?>
													<input type="hidden" name="mode" value="selected">
													<input type="hidden" name="items[]"
														value="<?= e( $site['id'] . '|theme|' . ( $theme['stylesheet'] ?? '' ) ) ?>">
													<button class="btn sm primary" data-busy="Aktualisiere…">Aktualisieren</button>
												</form>
											<?php endif; ?>

											<?php if ( empty( $theme['active'] ) ) : ?>
												<form method="post" action="<?= e( $siteUrl . '/extensions' ) ?>" style="display:inline"
													data-confirm="Aktion wirklich ausführen?">
													<?= csrf_field() ?>
													<input type="hidden" name="kind" value="theme">
													<input type="hidden" name="target" value="<?= e( (string) ( $theme['stylesheet'] ?? '' ) ) ?>">
													<button class="btn sm" name="extension_action" value="activate">Aktivieren</button>
												</form>
												<form method="post" action="<?= e( $siteUrl . '/extensions' ) ?>" style="display:inline"
													data-confirm="Theme &quot;<?= e( (string) ( $theme['name'] ?? '' ) ) ?>&quot; wirklich löschen? Das lässt sich nicht rückgängig machen.">
													<?= csrf_field() ?>
													<input type="hidden" name="kind" value="theme">
													<input type="hidden" name="target" value="<?= e( (string) ( $theme['stylesheet'] ?? '' ) ) ?>">
													<button class="btn sm danger" name="extension_action" value="delete">Löschen</button>
												</form>
											<?php endif; ?>
										</div>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<!-- ----------------------------------------------------------- Sicherheit -->
<div class="tab-panel" data-tab-panel="security">
	<div class="card">
		<div class="card-head">
			<h2>Sicherheitsprüfung</h2>
			<div class="spacer"></div>
			<?php if ( $canWrite ) : ?>
				<form method="post" action="<?= e( $siteUrl . '/security' ) ?>">
					<?= csrf_field() ?>
					<input type="hidden" name="op" value="enforce">
					<button class="btn sm" data-busy="Ziehe nach…" title="Alle dauerhaften Punkte sofort erneut setzen, ohne auf das nächste Update zu warten.">Dauerhaftes nachziehen</button>
				</form>
				<form method="post" action="<?= e( $siteUrl . '/security' ) ?>">
					<?= csrf_field() ?>
					<button class="btn sm" data-busy="Prüfe…">Neu prüfen</button>
				</form>
			<?php endif; ?>
		</div>
		<div class="card-body tight">
			<?php $checks = (array) ( $security['checks'] ?? array() ); ?>
			<?php if ( ! $checks ) : ?>
				<div class="empty">Keine Prüfergebnisse. Bitte synchronisieren.</div>
			<?php else : ?>
				<form method="post" action="<?= e( $siteUrl . '/security' ) ?>">
					<?= csrf_field() ?>
					<div class="table-wrap">
						<table class="data">
							<thead><tr><th>Prüfung</th><th>Ergebnis</th><th>Hinweis</th><th class="shrink">Auswahl</th></tr></thead>
							<tbody>
							<?php foreach ( $checks as $check ) : ?>
								<?php
								$status    = (string) ( $check['status'] ?? 'ok' );
								$dauerhaft = ! empty( $check['enforced'] );
								?>
								<tr>
									<td>
										<strong><?= e( (string) ( $check['label'] ?? '' ) ) ?></strong>
										<?php if ( $dauerhaft ) : ?>
											<span class="badge sm" title="Wird nach jedem Core-Update automatisch erneut gesetzt.">dauerhaft</span>
										<?php endif; ?>
									</td>
									<td class="nowrap">
										<?php if ( 'ok' === $status ) : ?>
											<span class="badge ok"><span class="dot"></span>Bestanden</span>
										<?php elseif ( 'ack' === $status ) : ?>
											<span class="badge" title="Bewusst so gelassen — zählt nicht mehr als offener Punkt."><span class="dot"></span>Bewusst so</span>
										<?php elseif ( 'warn' === $status ) : ?>
											<span class="badge warn"><span class="dot"></span>Hinweis</span>
										<?php else : ?>
											<span class="badge bad"><span class="dot"></span>Problem</span>
										<?php endif; ?>
									</td>
									<td class="small muted"><?= e( (string) ( $check['detail'] ?? '' ) ) ?></td>
									<td class="shrink">
										<?php if ( $canWrite && ( ( ! empty( $check['fixable'] ) && 'ok' !== $status ) || $dauerhaft || ! empty( $check['acknowledgeable'] ) ) ) : ?>
											<label class="small">
												<input type="checkbox" name="checks[]" value="<?= e( (string) $check['id'] ) ?>">
												auswählen
											</label>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php if ( $canWrite ) : ?>
						<div class="card-foot" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
							<button class="btn primary" name="op" value="harden" data-busy="Wende an…">Ausgewählte dauerhaft setzen</button>
							<button class="btn" name="op" value="relax" data-busy="Gebe auf…" title="Die dauerhafte Härtung für die ausgewählten Punkte wieder aufgeben.">Dauerhaft aufheben</button>
							<div class="spacer"></div>
							<input type="text" name="reason" class="input sm" style="max-width:280px" placeholder="Grund, z. B. „Kunde will keine Migration“">
							<button class="btn" name="op" value="acknowledge" data-busy="Vermerke…" title="Punkte, die sich nicht gefahrlos aus der Ferne beheben lassen, bewusst stehen lassen.">Bewusst so lassen</button>
							<button class="btn" name="op" value="unacknowledge" data-busy="Hebe auf…">Vermerk entfernen</button>
						</div>
						<p class="small muted" style="padding:0 16px 14px">
							„Dauerhaft setzen“ merkt die Punkte vor und wendet sie nach jedem Core-Update erneut an —
							readme.html etwa legt WordPress bei jedem Update wieder an.
						</p>
					<?php endif; ?>
				</form>
			<?php endif; ?>
		</div>
	</div>

	<!-- ------------------------------------------------------ Link-Prüfung -->
	<div class="card">
		<div class="card-head">
			<h2>Link-Prüfung</h2>
			<div class="spacer"></div>
			<?php if ( $canWrite ) : ?>
				<form method="post" action="<?= e( $siteUrl . '/links' ) ?>">
					<?= csrf_field() ?>
					<button class="btn sm" data-busy="Starte…">Jetzt prüfen</button>
				</form>
			<?php endif; ?>
		</div>
		<div class="card-body">
			<?php $lauf = (array) ( $links['progress'] ?? array() ); ?>

			<?php if ( isset( $links['enabled'] ) && ! $links['enabled'] ) : ?>
				<div class="empty">Die Link-Prüfung ist auf dieser Seite abgeschaltet (Einstellungen → NorthLab).</div>
			<?php elseif ( ! empty( $lauf['running'] ) ) : ?>
				<p>
					<strong>Läuft gerade.</strong>
					<?php if ( 'collect' === ( $lauf['phase'] ?? '' ) ) : ?>
						Die Adressen werden eingesammelt.
					<?php else : ?>
						<?= e( sprintf( '%d von %d geprüft', (int) ( $lauf['done'] ?? 0 ), (int) ( $lauf['total'] ?? 0 ) ) ) ?>.
					<?php endif; ?>
				</p>
				<p class="small muted">
					Die Prüfung läuft auf der Kundenseite in kleinen Etappen über deren WP-Cron —
					dieser Server hat damit keine Arbeit.
				</p>
			<?php elseif ( empty( $links['at'] ) ) : ?>
				<div class="empty">Noch kein Ergebnis. Die Prüfung läuft automatisch einmal pro Woche.</div>
			<?php else : ?>
				<div class="grid cols-4">
					<div><div class="muted small">Zuletzt</div><strong><?= e( nl_ago( (string) $links['at'] ) ) ?></strong></div>
					<div><div class="muted small">Geprüft</div><strong><?= e( (string) (int) ( $links['checked'] ?? 0 ) ) ?></strong></div>
					<div><div class="muted small">Tote Links</div><strong><?= e( (string) (int) ( $links['broken'] ?? 0 ) ) ?></strong></div>
					<div><div class="muted small">Unklar</div><strong><?= e( (string) (int) ( $links['unsure'] ?? 0 ) ) ?></strong></div>
				</div>

				<?php $beispiele = (array) ( $links['examples'] ?? array() ); ?>
				<?php if ( $beispiele ) : ?>
					<div class="table-wrap" style="margin-top:14px">
						<table class="data">
							<thead><tr><th>Adresse</th><th class="shrink">Antwort</th><th>Steht in</th></tr></thead>
							<tbody>
							<?php foreach ( $beispiele as $fund ) : ?>
								<tr>
									<td class="small" style="word-break:break-all"><?= e( (string) ( $fund['url'] ?? '' ) ) ?></td>
									<td class="shrink"><?= e( (string) ( $fund['status'] ?? 0 ) ?: '—' ) ?></td>
									<td class="small muted"><?= e( (string) ( $fund['title'] ?? '' ) ) ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<p class="small muted" style="margin-top:12px">
					Die vollständige Liste steht auf der Kundenseite unter „<?= e( (string) ( $site['name'] ?? '' ) ) ?> → NorthLab“ —
					dort lässt sich jeder Fund direkt im betroffenen Beitrag öffnen.
					<?php if ( (int) ( $links['unsure'] ?? 0 ) > 0 ) : ?>
						„Unklar“ sind Adressen, die automatische Abrufe abweisen; das heißt nicht, dass der Link tot ist.
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- --------------------------------------------------------------- Uptime -->
<div class="tab-panel" data-tab-panel="uptime">
	<div class="card">
		<div class="card-head">
			<h2>Verfügbarkeit — letzte 30 Tage</h2>
			<div class="spacer"></div>
			<?= nl_spark( $series ) ?>
		</div>
		<div class="card-body">
			<div class="grid cols-4">
				<div><div class="muted small">Verfügbarkeit</div><strong><?= e( nl_number( (float) $uptime['percent'], 3 ) ) ?> %</strong></div>
				<div><div class="muted small">Ausfallzeit</div><strong><?= e( nl_duration( (int) $uptime['downtime_seconds'] ) ) ?></strong></div>
				<div><div class="muted small">Störungen</div><strong><?= e( (string) $uptime['incidents'] ) ?></strong></div>
				<div><div class="muted small">Ø Antwortzeit</div><strong><?= e( (string) $uptime['avg_response_ms'] ) ?> ms</strong></div>
			</div>
		</div>
	</div>

	<div class="card">
		<div class="card-head"><h3>Störungen</h3></div>
		<div class="card-body tight">
			<?php if ( ! $incidents ) : ?>
				<div class="empty">Keine Ausfälle im Zeitraum.</div>
			<?php else : ?>
				<table class="data">
					<thead><tr><th>Beginn</th><th>Ende</th><th>Dauer</th><th>Quelle</th><th>Ursache</th></tr></thead>
					<tbody>
					<?php foreach ( $incidents as $incident ) : ?>
						<tr>
							<td class="nowrap"><?= e( nl_date( $incident['started_at'] ) ) ?></td>
							<td class="nowrap"><?= $incident['ended_at'] ? e( nl_date( $incident['ended_at'] ) ) : '<span class="badge bad">läuft</span>' ?></td>
							<td class="nowrap"><?= e( nl_duration( (int) $incident['seconds'] ) ) ?></td>
							<td class="small muted"><?= e( (string) $incident['source'] ) ?></td>
							<td class="small muted truncate"><?= e( (string) $incident['reason'] ) ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php if ( $managed ) : ?>
<!-- -------------------------------------------------------------- Wartung -->
<div class="tab-panel" data-tab-panel="maintenance">
	<div class="card">
		<div class="card-head">
			<h2>Wartungsmodus</h2>
			<div class="spacer"></div>
			<?php if ( $childOutdated ) : ?>
	<div class="notice warn">
		<strong>Child-Plugin veraltet:</strong>
		installiert ist <?= e( (string) $site['child_version'] ) ?>, das Panel liefert <?= e( $childShipped ) ?>.
		<?php if ( $canWrite ) : ?>
			<form method="post" action="<?= e( $siteUrl . '/child-update' ) ?>" style="display:inline;margin-left:8px">
				<?= csrf_field() ?>
				<button class="btn sm primary" data-busy="Aktualisiere…">Jetzt aktualisieren</button>
			</form>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( ! empty( $site['maintenance_mode'] ) ) : ?>
				<span class="badge warn">aktiv</span>
			<?php endif; ?>
		</div>
		<div class="card-body">
			<?php if ( ! $canWrite ) : ?>
				<div class="empty">Dein Konto hat nur Leserechte.</div>
			<?php else : ?>
				<p class="small muted">
					Besucher bekommen eine gebrandete Seite mit dem Status <code>503</code> und
					<code>Retry-After</code> — Suchmaschinen werten das als vorübergehend und nehmen die Seite
					nicht aus dem Index. Angemeldete Redakteure sehen die Seite unverändert.
					Gestaltung und Logo stehen in den <a href="<?= e( url( '/settings#mmode' ) ) ?>">Einstellungen</a>.
				</p>

				<form method="post" action="<?= e( $siteUrl . '/maintenance-mode' ) ?>"
					data-confirm="Wartungsmodus jetzt einschalten? Besucher sehen die Seite dann nicht mehr.">
					<?= csrf_field() ?>
					<div class="form-grid">
						<div class="field">
							<label for="mm_headline">Überschrift</label>
							<input type="text" id="mm_headline" name="headline"
								value="<?= e( (string) $mmodeDesign['headline'] ) ?>" maxlength="80">
						</div>
						<div class="field">
							<label for="mm_minutes">Dauer</label>
							<select id="mm_minutes" name="minutes">
								<?php foreach ( $mmodeTimes as $minutes => $label ) : ?>
									<option value="<?= e( (string) $minutes ) ?>" <?= 60 === $minutes ? 'selected' : '' ?>>
										<?= e( $label ) ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="field full">
							<label for="mm_message">Text</label>
							<input type="text" id="mm_message" name="message"
								value="<?= e( (string) $mmodeDesign['message'] ) ?>" maxlength="200">
						</div>
					</div>
					<button class="btn primary" data-busy="Schalte ein…">Wartungsmodus einschalten</button>
				</form>

				<?php if ( $childOutdated ) : ?>
	<div class="notice warn">
		<strong>Child-Plugin veraltet:</strong>
		installiert ist <?= e( (string) $site['child_version'] ) ?>, das Panel liefert <?= e( $childShipped ) ?>.
		<?php if ( $canWrite ) : ?>
			<form method="post" action="<?= e( $siteUrl . '/child-update' ) ?>" style="display:inline;margin-left:8px">
				<?= csrf_field() ?>
				<button class="btn sm primary" data-busy="Aktualisiere…">Jetzt aktualisieren</button>
			</form>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( ! empty( $site['maintenance_mode'] ) ) : ?>
					<form method="post" action="<?= e( $siteUrl . '/maintenance-mode' ) ?>" class="mt">
						<?= csrf_field() ?>
						<input type="hidden" name="disable" value="1">
						<button class="btn" data-busy="Beende…">Wartungsmodus beenden</button>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

	<div class="card">
		<div class="card-head"><h2>Wartungsaufgaben</h2></div>
		<div class="card-body">
			<?php if ( ! $canWrite ) : ?>
				<div class="empty">Dein Konto hat nur Leserechte.</div>
			<?php else : ?>
				<p class="small muted">Die Aufgaben laufen direkt auf der Kundenseite. Vor größeren Aufräumaktionen ein Backup einplanen.</p>
				<form method="post" action="<?= e( $siteUrl . '/maintenance' ) ?>"
					data-confirm="Ausgewählte Wartungsaufgaben jetzt ausführen?">
					<?= csrf_field() ?>
					<div class="grid cols-2">
						<?php foreach ( $tasks as $key => $label ) : ?>
							<div class="field inline">
								<input type="checkbox" id="task_<?= e( $key ) ?>" name="tasks[]" value="<?= e( $key ) ?>">
								<label for="task_<?= e( $key ) ?>"><?= e( $label ) ?></label>
							</div>
						<?php endforeach; ?>
					</div>
					<button class="btn primary" data-busy="Läuft…">Ausführen</button>
				</form>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- ------------------------------------------------------------- Sicherung -->
<?php if ( $managed ) : ?>
<div class="tab-panel" data-tab-panel="backup">
	<?php if ( ! $backupReady ) : ?>
		<div class="notice warn">
			<strong>Es ist noch kein Sicherungsziel eingerichtet.</strong>
			Das gilt fürs ganze Panel, nicht nur für diese Seite —
			<a href="<?= e( url( '/backups' ) ) ?>">unter Sicherungen</a> einzurichten.
		</div>
	<?php endif; ?>

	<div class="grid side">
		<div class="card" id="snapshot-box" data-snapshot-url="<?= e( url( '/backups/' . $site['id'] . '/snapshots' ) ) ?>">
			<div class="card-head">
				<h2>Sicherungspunkte auf dem Speicher</h2>
				<div class="spacer"></div>
				<a class="btn sm" href="<?= e( url( '/sites/' . $site['id'] . '/restore' ) ) ?>">Darin blättern</a>
				<div class="spacer"></div>
				<?php if ( $backupReady ) : ?>
					<button class="btn sm" type="button" data-snapshot-load>Abrufen</button>
				<?php endif; ?>
			</div>
			<div class="card-body">
				<p class="small muted">
					Jede Seite liegt als eigener Eintrag im selben Repository — getrennt nach Wirtsnamen,
					mit eigener Aufbewahrung. Was hier steht, kommt direkt vom Speicher, nicht aus der
					Datenbank des Panels.
				</p>
				<div data-snapshot-out></div>
			</div>
		</div>

		<div>
			<div class="card">
				<div class="card-head">
					<h3>Stand</h3>
					<div class="spacer"></div>
					<?php if ( ! $backupLast ) : ?>
						<span class="badge warn">noch nie</span>
					<?php elseif ( 'success' === $backupLast['status'] ) : ?>
						<span class="badge ok"><span class="dot"></span>erfolgreich</span>
					<?php elseif ( 'running' === $backupLast['status'] ) : ?>
						<span class="badge warn">läuft</span>
					<?php else : ?>
						<span class="badge bad"><span class="dot"></span>Fehler</span>
					<?php endif; ?>
				</div>
				<div class="card-body">
					<table class="data">
						<tbody>
							<tr>
								<td class="small muted">Letzter Lauf</td>
								<td class="small"><?= $backupLast ? e( nl_ago( (string) $backupLast['started_at'] ) ) : '—' ?></td>
							</tr>
							<tr>
								<td class="small muted">Dateien</td>
								<td class="small"><?= $backupLast ? e( nl_number( (int) $backupLast['files_total'] ) ) : '—' ?></td>
							</tr>
							<tr>
								<td class="small muted">Spiegel auf dem Panel</td>
								<td class="small"><?= e( size_format_de( $backupMirror ) ) ?></td>
							</tr>
						</tbody>
					</table>

					<?php if ( $backupLast && 'failed' === $backupLast['status'] && ! empty( $backupLast['message'] ) ) : ?>
						<div class="notice bad mt"><?= e( (string) $backupLast['message'] ) ?></div>
					<?php endif; ?>
				</div>
				<?php if ( $canWrite ) : ?>
					<div class="card-foot">
						<form method="post" action="<?= e( url( '/backups/' . $site['id'] . '/run' ) ) ?>">
							<?= csrf_field() ?>
							<button class="btn primary" data-busy="…">Jetzt sichern</button>
							<span class="small muted">
								<?php if ( \NorthLab\Service\BackupService::isRequested( $site ) ) : ?>
									Vorgemerkt — startet innerhalb einer Minute.
								<?php else : ?>
									Läuft im Hintergrund, nicht im Browser.
								<?php endif; ?>
							</span>
						</form>
					</div>
				<?php endif; ?>
			</div>

			<div class="card">
				<div class="card-head">
					<h3>Nächtlicher Lauf</h3>
					<div class="spacer"></div>
					<span class="badge <?= $backupScheduled ? 'ok' : 'warn' ?>">
						<?= $backupScheduled ? 'dabei' : 'ausgenommen' ?>
					</span>
				</div>
				<div class="card-body">
					<p class="small muted">
						<?php if ( $backupScheduled ) : ?>
							Diese Seite wird im nächtlichen Lauf mitgesichert.
						<?php else : ?>
							Diese Seite bleibt nachts aussen vor. Von Hand lässt sie sich weiterhin sichern.
						<?php endif; ?>
					</p>
					<?php if ( $canWrite ) : ?>
						<form method="post" action="<?= e( url( '/backups/' . $site['id'] . '/schedule' ) ) ?>">
							<?= csrf_field() ?>
							<input type="hidden" name="backup_enabled" value="<?= $backupScheduled ? '0' : '1' ?>">
							<button class="btn<?= $backupScheduled ? '' : ' primary' ?>" data-busy="…">
								<?= $backupScheduled ? 'Aus dem Zeitplan nehmen' : 'In den Zeitplan aufnehmen' ?>
							</button>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- --------------------------------------------------------- Einstellungen -->
<div class="tab-panel" data-tab-panel="settings">
	<div class="grid side">
		<div class="card">
			<div class="card-head"><h2>Einstellungen</h2></div>
			<div class="card-body">
				<form method="post" action="<?= e( $siteUrl ) ?>">
					<?= csrf_field() ?>
					<div class="form-grid">
						<div class="field">
							<label for="s_name">Anzeigename</label>
							<input type="text" id="s_name" name="name" value="<?= e( (string) $site['name'] ) ?>" required>
						</div>
						<div class="field">
							<label for="s_client">Kunde</label>
							<select id="s_client" name="client_id">
								<option value="">— keiner —</option>
								<?php foreach ( $clients as $option ) : ?>
									<option value="<?= e( (string) $option['id'] ) ?>" <?= (int) $site['client_id'] === (int) $option['id'] ? 'selected' : '' ?>>
										<?= e( $option['name'] ) ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="field">
							<label for="s_tags">Tags</label>
							<input type="text" id="s_tags" name="tags" value="<?= e( (string) $site['tags'] ) ?>">
						</div>
						<?php if ( $managed ) : ?>
						<div class="field">
							<label for="s_policy">Automatische Updates</label>
							<select id="s_policy" name="auto_update_policy">
								<?php
								$policies = array(
									'inherit' => 'Globale Einstellung übernehmen',
									'off'     => 'Aus',
									'minor'   => 'Nur Minor- und Patch-Versionen',
									'all'     => 'Alle Updates',
								);
								foreach ( $policies as $value => $label ) :
									?>
									<option value="<?= e( $value ) ?>" <?= (string) $site['auto_update_policy'] === $value ? 'selected' : '' ?>>
										<?= e( $label ) ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<?php endif; ?>
						<?php if ( $managed ) : ?>
							<div class="field full">
								<label for="s_update_excludes">Hier zusätzlich von Auto-Updates ausgenommen</label>
								<textarea id="s_update_excludes" name="update_excludes" rows="3"
									placeholder="angepasstes-plugin&#10;mein-theme/functions.php"><?= e( (string) ( $site['update_excludes'] ?? '' ) ) ?></textarea>
								<div class="hint">
									Ein Eintrag pro Zeile, zusätzlich zur globalen Liste aus den Einstellungen.
									Für den Fall, dass genau hier ein Plugin angepasst wurde.
								</div>
							</div>
						<?php endif; ?>
						<div class="field full">
							<label for="s_notes">Notizen</label>
							<textarea id="s_notes" name="notes"><?= e( (string) $site['notes'] ) ?></textarea>
						</div>
						<div class="field inline">
							<input type="checkbox" id="s_paused" name="is_paused" value="1" <?= ! empty( $site['is_paused'] ) ? 'checked' : '' ?>>
							<label for="s_paused">Seite pausieren <span class="muted">(keine Automatik)</span></label>
						</div>
						<div class="field inline">
							<input type="checkbox" id="s_ssl" name="verify_ssl" value="1" <?= ! empty( $site['verify_ssl'] ) ? 'checked' : '' ?>>
							<label for="s_ssl">TLS-Zertifikat prüfen</label>
						</div>
						<div class="field">
							<label for="s_http_user">HTTP-Basic-Benutzer</label>
							<input type="text" id="s_http_user" name="http_user" value="<?= e( (string) $site['http_user'] ) ?>" autocomplete="off">
						</div>
						<div class="field">
							<label for="s_http_pass">HTTP-Basic-Passwort</label>
							<input type="password" id="s_http_pass" name="http_pass" placeholder="unverändert lassen" autocomplete="new-password">
						</div>
					</div>
					<button class="btn primary" <?= $canWrite ? '' : 'disabled' ?>>Speichern</button>
				</form>
			</div>
		</div>

		<div>
			<?php if ( $managed ) : ?>
			<div class="card">
				<div class="card-head">
					<h3>Agentur-Branding</h3>
					<div class="spacer"></div>
					<?php if ( ! empty( $site['branding_bar'] ) || ! empty( $site['branding_login'] ) ) : ?>
						<span class="badge ok">aktiv</span>
					<?php endif; ?>
				</div>
				<div class="card-body">
					<p class="small muted">
						Logo, Kontaktwege und Farben stehen zentral in den
						<a href="<?= e( url( '/settings#branding' ) ) ?>">Einstellungen</a>.
						Hier wird nur entschieden, was auf dieser Seite erscheint.
					</p>
					<form method="post" action="<?= e( $siteUrl . '/branding' ) ?>">
						<?= csrf_field() ?>
						<div class="field inline">
							<input type="checkbox" id="b_bar" name="bar" value="1" <?= ! empty( $site['branding_bar'] ) ? 'checked' : '' ?>>
							<label for="b_bar">Support-Leiste im Backend</label>
						</div>
						<div class="field inline">
							<input type="checkbox" id="b_login" name="login_bar" value="1" <?= ! empty( $site['branding_login'] ) ? 'checked' : '' ?>>
							<label for="b_login">Leiste über der Anmeldeseite</label>
						</div>

						<div class="form-grid mt">
							<div class="field full">
								<label for="b_logo">Logo des Kunden auf der Anmeldeseite</label>
								<input type="url" id="b_logo" name="login_logo" placeholder="https://kundenseite.de/…/logo.png"
									value="<?= e( (string) ( $brandingState['login_logo'] ?? '' ) ) ?>">
								<div class="hint">
									Ersetzt das WordPress-Logo über dem Anmeldeformular. Leer lassen, um es
									so zu belassen, wie WordPress es zeigt.
								</div>
							</div>
							<div class="field">
								<label for="b_height">Höhe des Kundenlogos</label>
								<input type="number" id="b_height" name="login_logo_height" min="24" max="240" step="2"
									value="<?= e( (string) ( $brandingState['login_logo_height'] ?? 72 ) ) ?>">
								<div class="hint">Pixel.</div>
							</div>
							<div class="field">
								<label for="b_link">Logo verlinkt auf</label>
								<input type="url" id="b_link" name="login_logo_link" placeholder="leer = Startseite der Kundenseite"
									value="<?= e( (string) ( $brandingState['login_logo_link'] ?? '' ) ) ?>">
							</div>
						</div>

						<button class="btn primary" <?= $canWrite ? '' : 'disabled' ?> data-busy="Übertrage…">Branding übernehmen</button>
					</form>
				</div>
			</div>

			<div class="card">
				<div class="card-head"><h3>Verbindung erneuern</h3></div>
				<div class="card-body">
					<p class="small muted">
						Nötig, wenn das Child-Plugin neu installiert wurde oder die Signaturprüfung fehlschlägt.
						Erzeuge auf der Kundenseite einen neuen Code.
					</p>
					<form method="post" action="<?= e( $siteUrl . '/reconnect' ) ?>">
						<?= csrf_field() ?>
						<div class="field">
							<input type="text" name="connect_code" class="mono" placeholder="Verbindungscode" required autocomplete="off">
						</div>
						<button class="btn" <?= $canWrite ? '' : 'disabled' ?> data-busy="Verbinde…">Neu verbinden</button>
					</form>
				</div>
			</div>
			<?php endif; ?>

			<div class="card">
				<div class="card-head"><h3>Seite entfernen</h3></div>
				<div class="card-body">
					<p class="small muted">
						<?php if ( $managed ) : ?>
							Entfernt die Seite samt Verlauf aus dem Panel und meldet das Child-Plugin ab.
							Die WordPress-Installation selbst bleibt unberührt.
						<?php else : ?>
							Entfernt die Seite samt Uptime-Verlauf aus dem Panel. An der Seite selbst
							ändert sich nichts.
						<?php endif; ?>
					</p>
					<form method="post" action="<?= e( $siteUrl . '/delete' ) ?>"
						data-confirm="Seite &quot;<?= e( (string) $site['name'] ) ?>&quot; wirklich entfernen? Alle Verlaufsdaten gehen verloren.">
						<?= csrf_field() ?>
						<button class="btn danger" <?= $canWrite ? '' : 'disabled' ?>>Seite entfernen</button>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>

<!-- ------------------------------------------------------------- Protokoll -->
<div class="tab-panel" data-tab-panel="activity">
	<div class="card">
		<div class="card-head"><h2>Protokoll dieser Seite</h2></div>
		<div class="card-body tight">
			<?php if ( ! $activity ) : ?>
				<div class="empty">Noch keine Einträge.</div>
			<?php else : ?>
				<table class="data">
					<thead><tr><th>Zeitpunkt</th><th>Ereignis</th><th>Meldung</th></tr></thead>
					<tbody>
					<?php foreach ( $activity as $entry ) : ?>
						<tr>
							<td class="nowrap small muted"><?= e( nl_date( $entry['created_at'] ) ) ?></td>
							<td class="nowrap"><?= nl_level_badge( (string) $entry['level'] ) ?> <span class="mono small"><?= e( (string) $entry['action'] ) ?></span></td>
							<td class="small"><?= e( (string) $entry['message'] ) ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>
