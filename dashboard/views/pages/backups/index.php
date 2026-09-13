<?php
/**
 * @var array<int,array<string,mixed>> $rows
 * @var array<int,array<string,mixed>> $history
 * @var bool                           $enabled
 * @var bool                           $configured
 * @var bool                           $available
 * @var string|null                    $resticInfo
 * @var string                         $repository
 * @var int                            $hour
 * @var int                            $diskFree
 * @var array<string,string>           $settings
 */

use NorthLab\Core\Auth;
use NorthLab\Core\View;

View::set( 'pageTitle', 'Sicherungen' );

$canWrite = Auth::canWrite();
$isAdmin  = Auth::isAdmin();
$get      = static fn( string $key, string $default = '' ): string => $settings[ $key ] ?? $default;

$mirrorTotal = 0;
foreach ( $rows as $row ) {
	$mirrorTotal += (int) $row['mirror'];
}
?>

<?php if ( ! $available ) : ?>
	<div class="notice bad">
		<strong>restic fehlt.</strong> Ohne dieses Werkzeug gibt es keine Sicherung.
		Installation auf dem Panel-Server: <code>apt install restic</code> —
		danach diese Seite neu laden.
	</div>
<?php elseif ( ! $configured ) : ?>
	<div class="notice warn">
		<strong>Noch kein Ziel eingerichtet.</strong> Trage unten Repository und Passwort ein
		und prüfe die Verbindung, bevor du den Zeitplan aktivierst.
	</div>
<?php elseif ( ! $enabled ) : ?>
	<div class="notice warn">
		<strong>Zeitplan ist aus.</strong> Sicherungen laufen derzeit nur, wenn du sie von Hand anstösst.
	</div>
<?php endif; ?>

<div class="grid cols-4" style="margin-bottom:18px">
	<div class="stat <?= $enabled && $configured ? 'ok' : 'warn' ?>">
		<div class="label">Zeitplan</div>
		<div class="value" style="font-size:19px;margin-top:6px">
			<?= $enabled ? 'täglich ' . e( sprintf( '%02d:00', $hour ) ) : 'aus' ?>
		</div>
		<div class="sub"><?= e( $resticInfo ?: 'restic nicht gefunden' ) ?></div>
	</div>
	<div class="stat">
		<div class="label">Gesicherte Seiten</div>
		<div class="value"><?= e( (string) count( $rows ) ) ?></div>
		<div class="sub">Aufbewahrung <?= e( $get( 'backup_keep_daily', '7' ) ) ?>×täglich, <?= e( $get( 'backup_keep_weekly', '4' ) ) ?>×wöchentlich, <?= e( $get( 'backup_keep_monthly', '6' ) ) ?>×monatlich</div>
	</div>
	<div class="stat">
		<div class="label">Spiegel auf diesem Server</div>
		<div class="value" style="font-size:21px"><?= e( size_format_de( $mirrorTotal ) ) ?></div>
		<div class="sub"><?= e( size_format_de( $diskFree ) ) ?> frei</div>
	</div>
	<div class="stat">
		<div class="label">Ziel</div>
		<div class="value" style="font-size:15px;margin-top:8px;word-break:break-all">
			<?= '' !== $repository ? e( preg_replace( '/:[^:@]*@/', ':•••@', $repository ) ) : '<span class="muted">nicht gesetzt</span>' ?>
		</div>
	</div>
</div>

<div class="card">
	<div class="card-head"><h2>Seiten</h2></div>
	<div class="card-body tight">
		<?php if ( ! $rows ) : ?>
			<div class="empty">Keine Seiten verbunden.</div>
		<?php else : ?>
			<div class="table-wrap">
				<table class="data">
					<thead><tr><th>Seite</th><th>Letzte Sicherung</th><th>Ergebnis</th><th class="num">Dateien</th><th class="num">Spiegel</th><th class="shrink"></th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php $site = $row['site']; $last = $row['last']; ?>
						<tr>
							<td>
								<a href="<?= e( url( '/sites/' . $site['id'] ) ) ?>"><strong><?= e( (string) $site['name'] ) ?></strong></a>
								<div class="muted small"><?= e( nl_host( (string) $site['url'] ) ) ?></div>
							</td>
							<td class="small muted nowrap">
								<?= $last ? e( nl_ago( (string) $last['started_at'] ) ) : '<span class="badge warn">noch nie</span>' ?>
							</td>
							<td>
								<?php if ( ! $last ) : ?>
									<span class="muted small">—</span>
								<?php elseif ( 'success' === $last['status'] ) : ?>
									<span class="badge ok"><span class="dot"></span>erfolgreich</span>
								<?php elseif ( 'running' === $last['status'] ) : ?>
									<span class="badge warn">läuft</span>
								<?php else : ?>
									<span class="badge bad"><span class="dot"></span>Fehler</span>
								<?php endif; ?>
							</td>
							<td class="num small"><?= $last ? e( nl_number( (int) $last['files_total'] ) ) : '—' ?></td>
							<td class="num small"><?= e( size_format_de( (int) $row['mirror'] ) ) ?></td>
							<td class="shrink">
								<?php if ( $canWrite ) : ?>
									<form method="post" action="<?= e( url( '/backups/' . $site['id'] . '/run' ) ) ?>"
										data-confirm="Sicherung jetzt starten? Der erste Lauf kann bei grossen Mediatheken lange dauern.">
										<?= csrf_field() ?>
										<button class="btn sm" data-busy="läuft…">Jetzt sichern</button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( $last && 'failed' === $last['status'] && ! empty( $last['message'] ) ) : ?>
							<tr><td colspan="6" class="small" style="background:var(--bad-bg);color:#fca5a8"><?= e( (string) $last['message'] ) ?></td></tr>
						<?php endif; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>

<?php if ( $isAdmin ) : ?>
	<div class="grid side">
		<div class="card">
			<div class="card-head"><h2>Sicherungsziel</h2></div>
			<div class="card-body">
				<form method="post" action="<?= e( url( '/backups' ) ) ?>">
					<?= csrf_field() ?>
					<input type="hidden" name="section" value="target">

					<div class="field">
						<label for="repo">restic-Repository</label>
						<input type="text" id="repo" name="restic_repository" class="mono"
							value="<?= e( $get( 'restic_repository' ) ) ?>"
							placeholder="sftp:u123456@u123456.your-storagebox.de:/backups/northlab">
						<div class="hint">Für eine Hetzner Storage Box das Format <code>sftp:BENUTZER@HOST:/pfad</code>.</div>
					</div>

					<div class="field">
						<label for="pw">Repository-Passwort</label>
						<input type="password" id="pw" name="restic_password" autocomplete="new-password"
							placeholder="<?= '' !== $get( 'restic_password' ) ? 'gesetzt — leer lassen für unverändert' : 'langes Zufallspasswort' ?>">
						<div class="hint">
							<strong>Damit sind die Sicherungen verschlüsselt.</strong> Geht es verloren, sind alle
							Sicherungen unbrauchbar — gehört in den Passwortmanager, getrennt vom Server.
						</div>
					</div>

					<div class="form-grid">
						<div class="field">
							<label for="key">SSH-Schlüssel</label>
							<input type="text" id="key" name="restic_ssh_key" class="mono"
								value="<?= e( $get( 'restic_ssh_key' ) ) ?>" placeholder="/root/.ssh/id_ed25519">
						</div>
						<div class="field">
							<label for="home">HOME für restic</label>
							<input type="text" id="home" name="restic_home" class="mono" value="<?= e( $get( 'restic_home', '/root' ) ) ?>">
							<div class="hint">Dort liegt <code>.ssh/known_hosts</code>.</div>
						</div>
						<div class="field full">
							<label for="bin">Pfad zu restic</label>
							<input type="text" id="bin" name="restic_binary" class="mono"
								value="<?= e( $get( 'restic_binary' ) ) ?>" placeholder="leer = automatisch suchen">
						</div>
					</div>

					<button class="btn primary">Speichern</button>
				</form>
			</div>
			<div class="card-foot">
				<form method="post" action="<?= e( url( '/backups' ) ) ?>">
					<?= csrf_field() ?>
					<input type="hidden" name="section" value="init">
					<button class="btn" data-busy="Prüfe…">Verbindung prüfen und Repository anlegen</button>
				</form>
			</div>
		</div>

		<div class="card">
			<div class="card-head"><h2>Zeitplan und Umfang</h2></div>
			<div class="card-body">
				<form method="post" action="<?= e( url( '/backups' ) ) ?>">
					<?= csrf_field() ?>
					<input type="hidden" name="section" value="schedule">

					<div class="field inline">
						<input type="checkbox" id="on" name="backup_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
						<label for="on">Nächtliche Sicherung aktiv</label>
					</div>

					<div class="form-grid">
						<div class="field">
							<label for="hour">Uhrzeit</label>
							<input type="number" id="hour" name="backup_hour" min="0" max="23" value="<?= e( (string) $hour ) ?>">
							<div class="hint">Serverzeit. Nachts, wenn wenig los ist.</div>
						</div>
						<div class="field">
							<label for="root">Umfang</label>
							<select id="root" name="backup_root">
								<option value="content" <?= 'root' !== $get( 'backup_root', 'content' ) ? 'selected' : '' ?>>wp-content + Datenbank</option>
								<option value="root" <?= 'root' === $get( 'backup_root' ) ? 'selected' : '' ?>>komplette Installation + Datenbank</option>
							</select>
							<div class="hint">wp-content genügt fast immer — der Rest ist WordPress selbst.</div>
						</div>
						<div class="field">
							<label for="kd">täglich behalten</label>
							<input type="number" id="kd" name="backup_keep_daily" min="1" value="<?= e( $get( 'backup_keep_daily', '7' ) ) ?>">
						</div>
						<div class="field">
							<label for="kw">wöchentlich</label>
							<input type="number" id="kw" name="backup_keep_weekly" min="0" value="<?= e( $get( 'backup_keep_weekly', '4' ) ) ?>">
						</div>
						<div class="field">
							<label for="km">monatlich</label>
							<input type="number" id="km" name="backup_keep_monthly" min="0" value="<?= e( $get( 'backup_keep_monthly', '6' ) ) ?>">
						</div>
						<div class="field">
							<label for="mir">Spiegelverzeichnis</label>
							<input type="text" id="mir" name="backup_mirror_dir" class="mono"
								value="<?= e( $get( 'backup_mirror_dir' ) ) ?>" placeholder="leer = storage/backups">
						</div>
						<div class="field full">
							<label for="ex">Zusätzlich ausschliessen</label>
							<textarea id="ex" name="backup_excludes" placeholder="wp-content/uploads/videos&#10;wp-content/et-cache"><?= e( $get( 'backup_excludes' ) ) ?></textarea>
							<div class="hint">Ein Pfad je Zeile, relativ zur Installation. Caches sind bereits ausgenommen.</div>
						</div>
					</div>

					<button class="btn primary">Speichern</button>
				</form>
			</div>
		</div>
	</div>
<?php endif; ?>

<div class="card">
	<div class="card-head">
		<h2>Wie die Sicherung arbeitet</h2>
		<div class="spacer"></div>
		<button class="btn sm" type="button" data-toggle="#backup-info">Details</button>
	</div>
	<div class="card-body" id="backup-info" hidden>
		<p class="small">
			Da auf den Kundenseiten kein Shell-Zugang besteht, liefert das Child-Plugin die Daten über HTTPS.
			Das Panel hält je Seite einen lokalen Spiegel und holt nur, was sich geändert hat — verglichen wird
			über Grösse und Änderungszeit, wie bei rsync. Der Datenbank-Export läuft auf der Kundenseite in
			Etappen, damit kein Aufruf ins Zeitlimit läuft.
		</p>
		<p class="small">
			Aus dem Spiegel macht restic den Sicherungspunkt: dedupliziert, verschlüsselt, auf den entfernten
			Speicher geschoben. Nach dem ersten Lauf landen dort nur noch die geänderten Blöcke.
		</p>

		<h3 class="mt">Wiederherstellen</h3>
		<p class="small muted">
			Eine Wiederherstellung direkt aus dem Panel gibt es bewusst noch nicht — dabei Dateien über eine
			laufende Seite zu bügeln, ist zu riskant, um es hinter einen Knopf zu legen. Auf dem Panel-Server:
		</p>
		<pre class="code-block">export RESTIC_REPOSITORY='<?= e( $repository ?: 'sftp:…' ) ?>'
export RESTIC_PASSWORD='…'

restic snapshots                       # welche Sicherungspunkte gibt es
restic restore &lt;ID&gt; --target /tmp/wiederherstellung
restic dump &lt;ID&gt; /_datenbank/datenbank.sql.gz &gt; db.sql.gz</pre>
		<p class="small muted mb0">
			Danach Dateien per FTP zurückspielen und den Dump einspielen:
			<code>gunzip &lt; db.sql.gz | mysql -u … -p datenbank</code>
		</p>
	</div>
</div>

<div class="card">
	<div class="card-head"><h2>Verlauf</h2></div>
	<div class="card-body tight">
		<?php if ( ! $history ) : ?>
			<div class="empty">Noch keine Sicherung gelaufen.</div>
		<?php else : ?>
			<div class="table-wrap">
				<table class="data">
					<thead><tr><th>Beginn</th><th>Seite</th><th>Status</th><th class="num">Dateien</th><th class="num">Übertragen</th><th class="num">Datenbank</th><th>Meldung</th></tr></thead>
					<tbody>
					<?php foreach ( $history as $entry ) : ?>
						<tr>
							<td class="nowrap small muted"><?= e( nl_date( (string) $entry['started_at'] ) ) ?></td>
							<td class="small"><?= e( (string) $entry['site_name'] ) ?></td>
							<td>
								<?php if ( 'success' === $entry['status'] ) : ?>
									<span class="badge ok">erfolgreich</span>
								<?php elseif ( 'running' === $entry['status'] ) : ?>
									<span class="badge warn">läuft</span>
								<?php else : ?>
									<span class="badge bad">Fehler</span>
								<?php endif; ?>
							</td>
							<td class="num small"><?= e( nl_number( (int) $entry['files_total'] ) ) ?></td>
							<td class="num small"><?= e( size_format_de( (int) $entry['bytes'] ) ) ?></td>
							<td class="num small"><?= e( size_format_de( (int) $entry['db_bytes'] ) ) ?></td>
							<td class="small muted truncate"><?= e( (string) $entry['message'] ) ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>
