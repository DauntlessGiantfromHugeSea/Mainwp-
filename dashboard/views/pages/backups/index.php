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
 * @var string                         $targetType
 * @var array<int,array<string,string>> $checks
 * @var string                         $publicKey
 * @var array{user:string,host:string,port:int}|null $sftp
 * @var array<int,mixed>               $pendingKeys
 * @var bool                           $hostKnown
 * @var bool                           $panelOn
 * @var string                         $panelAt
 * @var string                         $panelState
 * @var string                         $panelNote
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

$openChecks = 0;
foreach ( $checks as $check ) {
	if ( 'ok' !== $check['state'] ) {
		$openChecks++;
	}
}

$needsSsh = in_array( $targetType, array( 'storagebox', 'sftp' ), true );
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

<div class="card">
	<div class="card-head">
		<h2>Das Panel selbst</h2>
		<div class="spacer"></div>
		<?php if ( ! $panelOn ) : ?>
			<span class="badge warn">nicht im Zeitplan</span>
		<?php elseif ( 'success' === $panelState ) : ?>
			<span class="badge ok"><span class="dot"></span>gesichert</span>
		<?php elseif ( 'failed' === $panelState ) : ?>
			<span class="badge bad"><span class="dot"></span>Fehler</span>
		<?php else : ?>
			<span class="badge warn">noch nie</span>
		<?php endif; ?>
	</div>
	<div class="card-body">
		<p class="small muted mb0">
			Datenbank und <code>config.php</code> des Panels. Darin stehen die privaten Schlüssel zu
			allen verbundenen Seiten — geht das verloren, ist jede Seite neu zu verbinden.
			<?php if ( '' !== $panelAt ) : ?>
				<br>Zuletzt <?= e( nl_ago( $panelAt ) ) ?><?= '' !== $panelNote ? ' — ' . e( $panelNote ) : '' ?>.
			<?php endif; ?>
		</p>
	</div>
	<?php if ( $isAdmin ) : ?>
		<div class="card-foot">
			<form method="post" action="<?= e( url( '/backups/panel' ) ) ?>">
				<?= csrf_field() ?>
				<button class="btn sm" data-busy="läuft…">Panel jetzt sichern</button>
			</form>
		</div>
	<?php endif; ?>
</div>

<?php if ( $isAdmin ) : ?>
	<div class="grid side">
		<div>
			<div class="card">
				<div class="card-head"><h2>Sicherungsziel</h2></div>
				<div class="card-body">
					<form method="post" action="<?= e( url( '/backups' ) ) ?>">
						<?= csrf_field() ?>
						<input type="hidden" name="section" value="target">

						<div class="type-choice">
							<label class="type-option">
								<input type="radio" name="backup_target_type" value="storagebox"
									data-choice="target" <?= 'storagebox' === $targetType ? 'checked' : '' ?>>
								<span>
									<strong>Hetzner Storage Box</strong>
									<span class="muted small">SFTP über Port 23, Anmeldung mit SSH-Schlüssel</span>
								</span>
							</label>
							<label class="type-option">
								<input type="radio" name="backup_target_type" value="s3"
									data-choice="target" <?= 's3' === $targetType ? 'checked' : '' ?>>
								<span>
									<strong>Objektspeicher (S3)</strong>
									<span class="muted small">Hetzner Object Storage oder ein anderer S3-Dienst</span>
								</span>
							</label>
							<label class="type-option">
								<input type="radio" name="backup_target_type" value="sftp"
									data-choice="target" <?= 'sftp' === $targetType ? 'checked' : '' ?>>
								<span>
									<strong>Eigener SFTP-Server</strong>
									<span class="muted small">Adresse von Hand eintragen</span>
								</span>
							</label>
							<label class="type-option">
								<input type="radio" name="backup_target_type" value="local"
									data-choice="target" <?= 'local' === $targetType ? 'checked' : '' ?>>
								<span>
									<strong>Verzeichnis auf diesem Server</strong>
									<span class="muted small">Nur sinnvoll, wenn dort eine eingehängte Freigabe liegt</span>
								</span>
							</label>
						</div>

						<div data-choice-when="target:storagebox" <?= 'storagebox' === $targetType ? '' : 'hidden' ?>>
							<div class="form-grid">
								<div class="field">
									<label for="sbuser">Benutzer der Storage Box</label>
									<input type="text" id="sbuser" name="sb_user" class="mono"
										value="<?= e( $get( 'sb_user' ) ) ?>" placeholder="u123456">
									<div class="hint">Steht im Hetzner-Konto. Der Wirt heisst genauso: <code>u123456.your-storagebox.de</code>.</div>
								</div>
								<div class="field">
									<label for="sbpath">Unterordner</label>
									<input type="text" id="sbpath" name="sb_path" class="mono"
										value="<?= e( $get( 'sb_path', 'northlab' ) ) ?>" placeholder="northlab">
									<div class="hint">Relativ zum Anmeldeverzeichnis. Wird beim Anlegen erzeugt.</div>
								</div>
							</div>
						</div>

						<div data-choice-when="target:s3" <?= 's3' === $targetType ? '' : 'hidden' ?>>
							<div class="form-grid">
								<div class="field">
									<label for="s3e">Endpunkt</label>
									<input type="text" id="s3e" name="s3_endpoint" class="mono" list="s3-endpoints"
										value="<?= e( $get( 's3_endpoint', 'https://fsn1.your-objectstorage.com' ) ) ?>">
									<datalist id="s3-endpoints">
										<?php foreach ( \NorthLab\Service\Restic::S3_ENDPOINTS as $endpoint => $label ) : ?>
											<option value="<?= e( $endpoint ) ?>"><?= e( $label ) ?></option>
										<?php endforeach; ?>
									</datalist>
									<div class="hint">Muss zum Standort des Buckets passen — fsn1, nbg1 oder hel1.</div>
								</div>
								<div class="field">
									<label for="s3b">Bucket</label>
									<input type="text" id="s3b" name="s3_bucket" class="mono" value="<?= e( $get( 's3_bucket' ) ) ?>">
								</div>
								<div class="field">
									<label for="s3p">Unterordner im Bucket</label>
									<input type="text" id="s3p" name="s3_prefix" class="mono"
										value="<?= e( $get( 's3_prefix' ) ) ?>" placeholder="northlab">
								</div>
								<div class="field">
									<label for="s3a">Access Key</label>
									<input type="text" id="s3a" name="s3_access_key" class="mono" autocomplete="off"
										value="<?= e( $get( 's3_access_key' ) ) ?>">
								</div>
								<div class="field">
									<label for="s3s">Secret Key</label>
									<input type="password" id="s3s" name="s3_secret_key" autocomplete="new-password"
										placeholder="<?= '' !== $get( 's3_secret_key' ) ? 'gesetzt — leer lassen für unverändert' : '' ?>">
									<div class="hint">Wird verschlüsselt gespeichert.</div>
								</div>
							</div>
						</div>

						<div data-choice-when="target:sftp" <?= 'sftp' === $targetType ? '' : 'hidden' ?>>
							<div class="field">
								<label for="repo">restic-Repository</label>
								<input type="text" id="repo" name="sftp_repository" class="mono"
									value="<?= e( $get( 'sftp_repository' ) ) ?>"
									placeholder="sftp://benutzer@wirt:22//pfad/zum/repository">
								<div class="hint">
									Mit Port: <code>sftp://benutzer@wirt:PORT//absoluter/pfad</code> — der doppelte
									Schrägstrich trennt Verbindung und Pfad. Ein einfacher steht für einen Pfad
									relativ zum Anmeldeverzeichnis.
								</div>
							</div>
						</div>

						<div data-choice-when="target:local" <?= 'local' === $targetType ? '' : 'hidden' ?>>
							<div class="field">
								<label for="repolocal">Verzeichnis</label>
								<input type="text" id="repolocal" name="local_path" class="mono"
									value="<?= e( $get( 'local_path' ) ) ?>"
									placeholder="/mnt/storagebox/northlab">
								<div class="hint">
									Eine Sicherung auf dieselbe Platte ist keine Sicherung. Nur nehmen, wenn dort
									ein entfernter Speicher eingehängt ist.
								</div>
							</div>
						</div>

						<div class="field">
							<label for="pw">Repository-Passwort</label>
							<input type="password" id="pw" name="restic_password" autocomplete="new-password"
								placeholder="<?= '' !== $get( 'restic_password' ) ? 'gesetzt — leer lassen für unverändert' : 'langes Zufallspasswort' ?>">
							<div class="hint">
								<strong>Damit sind die Sicherungen verschlüsselt.</strong> Geht es verloren, sind alle
								Sicherungen unbrauchbar — gehört in den Passwortmanager, getrennt vom Server.
								Das Panel verwahrt es verschlüsselt.
							</div>
						</div>

						<div class="field">
							<label for="bin">Pfad zu restic</label>
							<input type="text" id="bin" name="restic_binary" class="mono"
								value="<?= e( $get( 'restic_binary' ) ) ?>" placeholder="leer = automatisch suchen">
						</div>

						<button class="btn primary">Ziel speichern</button>
					</form>
				</div>
			</div>

			<?php if ( $needsSsh ) : ?>
				<div class="card">
					<div class="card-head"><h2>SSH-Zugang</h2></div>
					<div class="card-body">
						<p class="small muted">
							Das Panel meldet sich mit einem eigenen Schlüssel am Speicher an — ohne Passwort,
							damit die nächtliche Sicherung ohne Zutun läuft. Schlüssel, <code>known_hosts</code>
							und die ssh-Konfiguration liegen in <code><?= e( \NorthLab\Service\Restic::sshDir() ) ?></code>,
							also unter demselben Benutzer, unter dem auch der Webserver arbeitet.
						</p>

						<?php if ( '' === $publicKey ) : ?>
							<form method="post" action="<?= e( url( '/backups' ) ) ?>">
								<?= csrf_field() ?>
								<input type="hidden" name="section" value="sshkey">
								<button class="btn primary" data-busy="Erzeuge…">Schlüsselpaar erzeugen</button>
							</form>
						<?php else : ?>
							<div class="field">
								<label for="pub">Öffentlicher Schlüssel</label>
								<textarea id="pub" class="mono" rows="3" readonly onclick="this.select()"><?= e( $publicKey ) ?></textarea>
								<div class="hint">Dieser Teil darf heraus. Der private bleibt auf dem Server.</div>
							</div>

							<?php if ( 'storagebox' === $targetType && null !== $sftp ) : ?>
								<p class="small">So kommt er auf die Storage Box — auf dem Panel-Server ausführen,
									das Passwort der Storage Box wird abgefragt:</p>
								<pre class="code-block">ssh-copy-id -s -p <?= e( (string) $sftp['port'] ) ?> -i <?= e( \NorthLab\Service\Restic::keyPath() ) ?>.pub <?= e( $sftp['user'] . '@' . $sftp['host'] ) ?></pre>
								<p class="small muted">
									Alternativ lässt er sich im Hetzner-Konto bei der Storage Box hinterlegen —
									dort in der Standard-OpenSSH-Form, also genau die Zeile von oben.
								</p>
							<?php endif; ?>

							<form method="post" action="<?= e( url( '/backups' ) ) ?>" class="mt"
								data-confirm="Neues Schlüsselpaar erzeugen? Der alte Schlüssel gilt dann nicht mehr und muss auf dem Speicher ersetzt werden.">
								<?= csrf_field() ?>
								<input type="hidden" name="section" value="sshkey">
								<button class="btn sm">Neu erzeugen</button>
							</form>
						<?php endif; ?>
					</div>

					<?php if ( null !== $sftp ) : ?>
						<div class="card-body" style="border-top:1px solid var(--line)">
							<h3>Wirtsschlüssel</h3>
							<p class="small muted">
								Damit niemand sich unbemerkt als <?= e( $sftp['host'] ) ?> ausgeben kann, prüft ssh
								bei jeder Verbindung den Schlüssel der Gegenseite. Er muss einmal übernommen werden —
								und vorher verglichen.
							</p>

							<?php if ( $pendingKeys ) : ?>
								<div class="notice warn">
									<strong>Abgerufen, noch nicht übernommen.</strong>
									Vergleiche den Fingerabdruck mit dem, den Hetzner für die Storage Box anzeigt.
								</div>
								<table class="data">
									<thead><tr><th>Art</th><th>Fingerabdruck</th></tr></thead>
									<tbody>
									<?php foreach ( $pendingKeys as $key ) : ?>
										<tr>
											<td class="small mono"><?= e( (string) ( $key['type'] ?? '' ) ) ?></td>
											<td class="small mono"><?= e( (string) ( $key['fingerprint'] ?? '' ) ) ?></td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
								<form method="post" action="<?= e( url( '/backups' ) ) ?>" class="mt"
									data-confirm="Fingerabdruck geprüft und übernehmen?">
									<?= csrf_field() ?>
									<input type="hidden" name="section" value="hostkey_trust">
									<button class="btn primary">Fingerabdruck stimmt — übernehmen</button>
								</form>
							<?php else : ?>
								<p class="small">
									<?php if ( $hostKnown ) : ?>
										<span class="badge ok"><span class="dot"></span>bekannt</span>
									<?php else : ?>
										<span class="badge bad"><span class="dot"></span>noch nicht hinterlegt</span>
									<?php endif; ?>
								</p>
								<form method="post" action="<?= e( url( '/backups' ) ) ?>">
									<?= csrf_field() ?>
									<input type="hidden" name="section" value="hostkey">
									<button class="btn" data-busy="Frage an…">Wirtsschlüssel abrufen</button>
								</form>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<div>
			<div class="card">
				<div class="card-head">
					<h2>Bereitschaft</h2>
					<div class="spacer"></div>
					<?php if ( 0 === $openChecks ) : ?>
						<span class="badge ok"><span class="dot"></span>vollständig</span>
					<?php else : ?>
						<span class="badge bad"><span class="dot"></span><?= e( (string) $openChecks ) ?> offen</span>
					<?php endif; ?>
				</div>
				<div class="card-body tight">
					<table class="data">
						<tbody>
						<?php foreach ( $checks as $check ) : ?>
							<tr>
								<td class="shrink">
									<?php if ( 'ok' === $check['state'] ) : ?>
										<span class="badge ok"><span class="dot"></span></span>
									<?php else : ?>
										<span class="badge bad"><span class="dot"></span></span>
									<?php endif; ?>
								</td>
								<td>
									<strong class="small"><?= e( $check['label'] ) ?></strong>
									<div class="muted small"><?= e( $check['detail'] ) ?></div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="card-foot">
					<form method="post" action="<?= e( url( '/backups' ) ) ?>">
						<?= csrf_field() ?>
						<input type="hidden" name="section" value="init">
						<button class="btn primary" data-busy="Prüfe…">Verbindung prüfen und Repository anlegen</button>
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

						<div class="field inline">
							<input type="checkbox" id="pnl" name="backup_panel" value="1"
								<?= '1' === $get( 'backup_panel', '1' ) ? 'checked' : '' ?>>
							<label for="pnl">Das Panel selbst mitsichern</label>
						</div>
						<div class="hint" style="margin:-6px 0 14px">
							Datenbank und <code>config.php</code>. Darin stecken die Schlüssel zu allen verbundenen
							Seiten — ohne diese Sicherung wäre nach einem Serverausfall jede Seite neu zu verbinden.
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
		<pre class="code-block">export HOME='<?= e( \NorthLab\Service\Restic::workDir() ) ?>'
export RESTIC_REPOSITORY='<?= e( $repository ?: 'sftp:…' ) ?>'
export RESTIC_PASSWORD='…'
<?php if ( 's3' === $targetType ) : ?>
export AWS_ACCESS_KEY_ID='…'
export AWS_SECRET_ACCESS_KEY='…'
<?php endif; ?>

restic snapshots                       # welche Sicherungspunkte gibt es
restic restore &lt;ID&gt; --target /tmp/wiederherstellung
restic dump &lt;ID&gt; /_datenbank/datenbank.sql.gz &gt; db.sql.gz</pre>
		<p class="small muted">
			Das <code>HOME</code> ist wichtig: dort liegen der SSH-Schlüssel und die known_hosts, mit denen
			sich das Panel am Speicher anmeldet. Ohne das fragt restic nach einem Passwort, das es nicht gibt.
		</p>
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
