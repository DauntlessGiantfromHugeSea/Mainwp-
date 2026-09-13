<?php
/**
 * @var array<int,array<string,mixed>> $sites
 * @var array<int,array<string,mixed>> $attention
 * @var array<string,int>              $updateCounts
 * @var array<string,array>            $topUpdates
 * @var array<int,array<string,mixed>> $clients
 * @var array<int,array<string,mixed>> $activity
 * @var array<int,array<string,mixed>> $uptimeFeed
 * @var array<string,int>              $queue
 * @var array<int,array<string,mixed>> $jobs
 * @var array<string,int>              $stats
 * @var string|null                    $lastCron
 */

use NorthLab\Core\Auth;
use NorthLab\Core\View;

View::set( 'pageTitle', 'Dashboard' );
View::set(
	'headerActions',
	Auth::canWrite()
		? '<a class="btn primary" href="' . e( url( '/sites/new' ) ) . '">Seite hinzufügen</a>'
		: ''
);
?>

<div class="grid cols-4" style="margin-bottom:18px">
	<div class="stat">
		<div class="label">Verwaltete Seiten</div>
		<div class="value"><?= e( (string) $stats['total'] ) ?></div>
		<div class="sub"><?= e( (string) $stats['connected'] ) ?> verbunden<?= $stats['errored'] > 0 ? ', ' . e( (string) $stats['errored'] ) . ' mit Fehler' : '' ?></div>
	</div>
	<div class="stat <?= $stats['updates'] > 0 ? 'warn' : 'ok' ?>">
		<div class="label">Offene Updates</div>
		<div class="value"><?= e( (string) $stats['updates'] ) ?></div>
		<div class="sub">
			<?= e( (string) $updateCounts['core'] ) ?> Core ·
			<?= e( (string) $updateCounts['plugin'] ) ?> Plugins ·
			<?= e( (string) $updateCounts['theme'] ) ?> Themes
		</div>
	</div>
	<div class="stat <?= $stats['offline'] > 0 ? 'bad' : 'ok' ?>">
		<div class="label">Nicht erreichbar</div>
		<div class="value"><?= e( (string) $stats['offline'] ) ?></div>
		<div class="sub">Stand: <?= e( nl_ago( $lastCron ) ) ?></div>
	</div>
	<div class="stat <?= nl_score_class( $stats['avg_security'] ) ?>">
		<div class="label">Ø Sicherheitswert</div>
		<div class="value"><?= e( (string) $stats['avg_security'] ) ?></div>
		<div class="sub">von 100 Punkten</div>
	</div>
</div>

<?php if ( 0 === $stats['total'] ) : ?>
	<div class="card">
		<div class="card-body empty">
			<strong>Noch keine Seite verbunden</strong>
			<p>Lade das NorthLab-Child-Plugin herunter, installiere es auf einer Kundenseite und verbinde sie hier.</p>
			<div class="btn-row" style="justify-content:center">
				<a class="btn primary" href="<?= e( url( '/download' ) ) ?>">Plugin herunterladen</a>
				<a class="btn" href="<?= e( url( '/sites/new' ) ) ?>">Seite hinzufügen</a>
			</div>
		</div>
	</div>
<?php else : ?>

<div class="grid side">
	<div>
		<div class="card">
			<div class="card-head">
				<h2>Braucht Aufmerksamkeit</h2>
				<div class="spacer"></div>
				<a class="btn sm" href="<?= e( url( '/sites' ) ) ?>">Alle Seiten</a>
			</div>
			<div class="card-body tight">
				<?php if ( ! $attention ) : ?>
					<div class="empty"><strong>Alles im grünen Bereich</strong>Keine offenen Updates, keine Ausfälle.</div>
				<?php else : ?>
					<div class="table-wrap">
						<table class="data">
							<thead>
							<tr>
								<th>Seite</th>
								<th>Status</th>
								<th class="num">Updates</th>
								<th class="num">Sicherheit</th>
								<th>Letzter Sync</th>
								<th></th>
							</tr>
							</thead>
							<tbody>
							<?php foreach ( $attention as $site ) : ?>
								<tr>
									<td>
										<a href="<?= e( url( '/sites/' . $site['id'] ) ) ?>"><strong><?= e( $site['name'] ) ?></strong></a>
										<div class="muted small"><?= e( nl_host( (string) $site['url'] ) ) ?></div>
									</td>
									<td class="nowrap">
										<?= nl_status_badge( $site ) ?>
										<?php if ( 'down' === $site['uptime_status'] ) : ?>
											<?= nl_uptime_badge( $site ) ?>
										<?php endif; ?>
									</td>
									<td class="num">
										<?php if ( (int) $site['pending_updates'] > 0 ) : ?>
											<span class="badge warn"><?= e( (string) $site['pending_updates'] ) ?></span>
										<?php else : ?>
											<span class="muted">0</span>
										<?php endif; ?>
									</td>
									<td class="num">
										<?php if ( (int) $site['security_score'] > 0 ) : ?>
											<span class="badge <?= e( nl_score_class( (int) $site['security_score'] ) ) ?>"><?= e( (string) $site['security_score'] ) ?></span>
										<?php else : ?>
											<span class="muted">—</span>
										<?php endif; ?>
									</td>
									<td class="muted small nowrap"><?= e( nl_ago( $site['last_sync_at'] ) ) ?></td>
									<td class="shrink">
										<?php if ( Auth::canWrite() && (int) $site['pending_updates'] > 0 ) : ?>
											<form method="post" action="<?= e( url( '/updates/apply' ) ) ?>">
												<?= csrf_field() ?>
												<input type="hidden" name="mode" value="site">
												<input type="hidden" name="site_id" value="<?= e( (string) $site['id'] ) ?>">
												<button class="btn sm primary" data-busy="läuft…">Aktualisieren</button>
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

		<?php if ( $topUpdates ) : ?>
			<div class="card">
				<div class="card-head">
					<h2>Häufigste Updates</h2>
					<div class="spacer"></div>
					<a class="btn sm" href="<?= e( url( '/updates' ) ) ?>">Update-Zentrale</a>
				</div>
				<div class="card-body tight">
					<div class="table-wrap">
						<table class="data">
							<thead>
							<tr><th>Erweiterung</th><th>Typ</th><th>Zielversion</th><th class="num">Seiten</th><th></th></tr>
							</thead>
							<tbody>
							<?php foreach ( $topUpdates as $group ) : ?>
								<tr>
									<td><strong><?= e( $group['name'] ) ?></strong><div class="muted small mono"><?= e( $group['slug'] ) ?></div></td>
									<td><span class="badge info"><?= e( nl_update_type( $group['type'] ) ) ?></span></td>
									<td class="mono"><?= e( $group['new_version'] ) ?></td>
									<td class="num"><?= e( (string) count( $group['sites'] ) ) ?></td>
									<td class="shrink">
										<?php if ( Auth::canWrite() ) : ?>
											<form method="post" action="<?= e( url( '/updates/apply' ) ) ?>"
												data-confirm="<?= e( sprintf( '%s auf %d Seite(n) aktualisieren?', $group['name'], count( $group['sites'] ) ) ) ?>">
												<?= csrf_field() ?>
												<input type="hidden" name="mode" value="group">
												<input type="hidden" name="type" value="<?= e( $group['type'] ) ?>">
												<input type="hidden" name="slug" value="<?= e( $group['slug'] ) ?>">
												<button class="btn sm" data-busy="läuft…">Überall</button>
											</form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<div>
		<div class="card">
			<div class="card-head"><h3>Letzte Ereignisse</h3></div>
			<div class="card-body tight">
				<?php if ( ! $activity ) : ?>
					<div class="empty">Noch keine Einträge.</div>
				<?php else : ?>
					<table class="data">
						<tbody>
						<?php foreach ( $activity as $entry ) : ?>
							<tr>
								<td>
									<div><?= e( $entry['message'] ) ?></div>
									<div class="muted small">
										<?= e( nl_ago( $entry['created_at'] ) ) ?>
										<?php if ( ! empty( $entry['site_name'] ) ) : ?>
											· <?= e( $entry['site_name'] ) ?>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<div class="card-foot"><a class="small" href="<?= e( url( '/activity' ) ) ?>">Vollständiges Protokoll</a></div>
		</div>

		<div class="card">
			<div class="card-head"><h3>Zeitplaner</h3></div>
			<div class="card-body tight">
				<table class="data">
					<tbody>
					<?php foreach ( $jobs as $job ) : ?>
						<tr>
							<td>
								<div><?= e( $job['label'] ) ?></div>
								<div class="muted small"><?= e( $job['last_result'] ?? 'noch nicht gelaufen' ) ?></div>
							</td>
							<td class="right muted small nowrap"><?= e( nl_ago( $job['last_run_at'] ) ) ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="card-foot small muted">
				Webhook-Warteschlange: <?= e( (string) $queue['pending'] ) ?> offen,
				<?= e( (string) $queue['failed'] ) ?> fehlgeschlagen
			</div>
		</div>

		<?php if ( $uptimeFeed ) : ?>
			<div class="card">
				<div class="card-head"><h3>Verfügbarkeit</h3></div>
				<div class="card-body tight">
					<table class="data">
						<tbody>
						<?php foreach ( $uptimeFeed as $event ) : ?>
							<tr>
								<td class="shrink">
									<?php if ( 'up' === $event['status'] ) : ?>
										<span class="badge ok"><span class="dot"></span>Up</span>
									<?php else : ?>
										<span class="badge bad"><span class="dot"></span>Down</span>
									<?php endif; ?>
								</td>
								<td>
									<a href="<?= e( url( '/sites/' . $event['site_id'] ) ) ?>"><?= e( $event['site_name'] ) ?></a>
									<div class="muted small"><?= e( nl_ago( $event['occurred_at'] ) ) ?> · <?= e( $event['source'] ) ?></div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>

<?php endif; ?>
