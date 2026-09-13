<?php
/**
 * @var array<string,mixed>            $client
 * @var array<int,array<string,mixed>> $sites
 * @var array<int,array<string,mixed>> $uptime
 * @var array<int,array<string,mixed>> $reports
 */

use NorthLab\Core\Auth;
use NorthLab\Core\View;
use NorthLab\Repository\ClientRepository;

View::set( 'pageTitle', (string) $client['name'] );
$canWrite = Auth::canWrite();

$pending = 0;
foreach ( $sites as $site ) {
	$pending += (int) $site['pending_updates'];
}
?>

<div class="grid side">
	<div>
		<div class="card">
			<div class="card-head">
				<h2><?= e( (string) count( $sites ) ) ?> Seite(n)</h2>
				<div class="spacer"></div>
				<?php if ( $canWrite ) : ?>
					<form method="post" action="<?= e( url( '/reports' ) ) ?>">
						<?= csrf_field() ?>
						<input type="hidden" name="client_id" value="<?= e( (string) $client['id'] ) ?>">
						<input type="hidden" name="days" value="30">
						<input type="hidden" name="deliver" value="1">
						<button class="btn sm primary" data-busy="Erstelle…">Bericht erstellen und senden</button>
					</form>
				<?php endif; ?>
			</div>
			<div class="card-body tight">
				<?php if ( ! $sites ) : ?>
					<div class="empty">Diesem Kunden ist noch keine Seite zugeordnet.</div>
				<?php else : ?>
					<div class="table-wrap">
						<table class="data">
							<thead><tr><th>Seite</th><th>Status</th><th class="num">Updates</th><th class="num">Uptime 30 T.</th><th class="num">Sicherheit</th></tr></thead>
							<tbody>
							<?php foreach ( $sites as $site ) : ?>
								<?php $availability = $uptime[ (int) $site['id'] ] ?? array( 'percent' => 100.0 ); ?>
								<tr>
									<td>
										<a href="<?= e( url( '/sites/' . $site['id'] ) ) ?>"><strong><?= e( (string) $site['name'] ) ?></strong></a>
										<div class="muted small"><?= e( nl_host( (string) $site['url'] ) ) ?></div>
									</td>
									<td class="nowrap"><?= nl_status_badge( $site ) ?> <?= nl_uptime_badge( $site ) ?></td>
									<td class="num"><?= e( (string) $site['pending_updates'] ) ?></td>
									<td class="num"><?= e( nl_number( (float) $availability['percent'], 2 ) ) ?> %</td>
									<td class="num">
										<?php if ( (int) $site['security_score'] > 0 ) : ?>
											<span class="badge <?= e( nl_score_class( (int) $site['security_score'] ) ) ?>"><?= e( (string) $site['security_score'] ) ?></span>
										<?php else : ?>
											<span class="muted">—</span>
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

		<div class="card">
			<div class="card-head"><h2>Berichte</h2></div>
			<div class="card-body tight">
				<?php if ( ! $reports ) : ?>
					<div class="empty">Noch keine Berichte erstellt.</div>
				<?php else : ?>
					<table class="data">
						<thead><tr><th>Bericht</th><th>Zeitraum</th><th>Erstellt</th><th class="shrink"></th></tr></thead>
						<tbody>
						<?php foreach ( $reports as $report ) : ?>
							<tr>
								<td><a href="<?= e( url( '/reports/' . $report['id'] ) ) ?>" target="_blank" rel="noopener"><?= e( (string) $report['title'] ) ?></a></td>
								<td class="small muted"><?= e( nl_date( (string) $report['period_start'], 'd.m.Y' ) ) ?> – <?= e( nl_date( (string) $report['period_end'], 'd.m.Y' ) ) ?></td>
								<td class="small muted"><?= e( nl_ago( (string) $report['created_at'] ) ) ?></td>
								<td class="shrink"><a class="btn sm" href="<?= e( url( '/reports/' . $report['id'] . '/download' ) ) ?>">HTML</a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div>
		<div class="grid cols-2" style="margin-bottom:18px">
			<div class="stat"><div class="label">Seiten</div><div class="value"><?= e( (string) count( $sites ) ) ?></div></div>
			<div class="stat <?= $pending > 0 ? 'warn' : 'ok' ?>"><div class="label">Offene Updates</div><div class="value"><?= e( (string) $pending ) ?></div></div>
		</div>

		<div class="card">
			<div class="card-head"><h3>Kundendaten</h3></div>
			<div class="card-body">
				<form method="post" action="<?= e( url( '/clients/' . $client['id'] ) ) ?>">
					<?= csrf_field() ?>
					<div class="field">
						<label for="name">Name</label>
						<input type="text" id="name" name="name" value="<?= e( (string) $client['name'] ) ?>" required>
					</div>
					<div class="field">
						<label for="contact_name">Ansprechpartner</label>
						<input type="text" id="contact_name" name="contact_name" value="<?= e( (string) $client['contact_name'] ) ?>">
					</div>
					<div class="field">
						<label for="email">E-Mail</label>
						<input type="email" id="email" name="email" value="<?= e( (string) $client['email'] ) ?>">
					</div>
					<div class="field">
						<label for="report_frequency">Berichtsrhythmus</label>
						<select id="report_frequency" name="report_frequency">
							<?php foreach ( ClientRepository::FREQUENCIES as $value => $label ) : ?>
								<option value="<?= e( $value ) ?>" <?= (string) $client['report_frequency'] === $value ? 'selected' : '' ?>><?= e( $label ) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="field inline">
						<input type="checkbox" id="report_email_enabled" name="report_email_enabled" value="1" <?= ! empty( $client['report_email_enabled'] ) ? 'checked' : '' ?>>
						<label for="report_email_enabled">Berichte per E-Mail senden</label>
					</div>
					<div class="field">
						<label for="report_webhook_url">Bericht-Webhook (URL)</label>
						<input type="url" id="report_webhook_url" name="report_webhook_url" value="<?= e( (string) $client['report_webhook_url'] ) ?>" placeholder="https://…">
						<div class="hint">Bekommt den vollständigen Bericht als JSON, signiert mit dem Secret unten.</div>
					</div>
					<div class="field">
						<label for="report_webhook_secret">Webhook-Secret</label>
						<input type="text" id="report_webhook_secret" name="report_webhook_secret" class="mono" value="<?= e( (string) $client['report_webhook_secret'] ) ?>">
					</div>
					<div class="field">
						<label for="notes">Notizen</label>
						<textarea id="notes" name="notes"><?= e( (string) $client['notes'] ) ?></textarea>
					</div>
					<button class="btn primary" <?= $canWrite ? '' : 'disabled' ?>>Speichern</button>
				</form>
			</div>
			<?php if ( $canWrite ) : ?>
				<div class="card-foot">
					<form method="post" action="<?= e( url( '/clients/' . $client['id'] . '/delete' ) ) ?>"
						data-confirm="Kunde wirklich löschen? Die zugeordneten Seiten bleiben erhalten.">
						<?= csrf_field() ?>
						<button class="btn danger sm">Kunde löschen</button>
					</form>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
