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
?>

<?php if ( ! empty( $site['last_error'] ) ) : ?>
	<div class="notice bad"><strong>Letzter Fehler:</strong> <?= e( (string) $site['last_error'] ) ?></div>
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
							<dt>Child-Plugin</dt><dd><?= e( $site['child_version'] ?: '—' ) ?></dd>
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
<div class="tab-panel" data-tab-panel="plugins">
	<div class="card">
		<div class="card-head">
			<h2><?= e( (string) count( $plugins ) ) ?> Plugin(s)</h2>
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
								<td class="shrink">
									<?php if ( $canWrite ) : ?>
										<form method="post" action="<?= e( $siteUrl . '/extensions' ) ?>"
											data-confirm="Aktion wirklich ausführen?">
											<?= csrf_field() ?>
											<input type="hidden" name="kind" value="plugin">
											<input type="hidden" name="target" value="<?= e( (string) ( $plugin['file'] ?? '' ) ) ?>">
											<div class="btn-row">
												<?php if ( ! empty( $plugin['active'] ) ) : ?>
													<button class="btn sm" name="extension_action" value="deactivate">Deaktivieren</button>
												<?php else : ?>
													<button class="btn sm" name="extension_action" value="activate">Aktivieren</button>
													<button class="btn sm danger" name="extension_action" value="delete">Löschen</button>
												<?php endif; ?>
											</div>
										</form>
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
								<td class="shrink">
									<?php if ( $canWrite && empty( $theme['active'] ) ) : ?>
										<form method="post" action="<?= e( $siteUrl . '/extensions' ) ?>" data-confirm="Aktion wirklich ausführen?">
											<?= csrf_field() ?>
											<input type="hidden" name="kind" value="theme">
											<input type="hidden" name="target" value="<?= e( (string) ( $theme['stylesheet'] ?? '' ) ) ?>">
											<div class="btn-row">
												<button class="btn sm" name="extension_action" value="activate">Aktivieren</button>
												<button class="btn sm danger" name="extension_action" value="delete">Löschen</button>
											</div>
										</form>
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
							<thead><tr><th>Prüfung</th><th>Ergebnis</th><th>Hinweis</th><th class="shrink">Beheben</th></tr></thead>
							<tbody>
							<?php foreach ( $checks as $check ) : ?>
								<tr>
									<td><strong><?= e( (string) ( $check['label'] ?? '' ) ) ?></strong></td>
									<td class="nowrap">
										<?php $status = (string) ( $check['status'] ?? 'ok' ); ?>
										<?php if ( 'ok' === $status ) : ?>
											<span class="badge ok"><span class="dot"></span>Bestanden</span>
										<?php elseif ( 'warn' === $status ) : ?>
											<span class="badge warn"><span class="dot"></span>Hinweis</span>
										<?php else : ?>
											<span class="badge bad"><span class="dot"></span>Problem</span>
										<?php endif; ?>
									</td>
									<td class="small muted"><?= e( (string) ( $check['detail'] ?? '' ) ) ?></td>
									<td class="shrink">
										<?php if ( $canWrite && ! empty( $check['fixable'] ) && 'ok' !== $status ) : ?>
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
						<div class="card-foot">
							<button class="btn primary" data-busy="Wende an…">Ausgewählte Punkte beheben</button>
						</div>
					<?php endif; ?>
				</form>
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
