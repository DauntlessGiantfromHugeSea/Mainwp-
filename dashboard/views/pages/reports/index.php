<?php
/**
 * @var array<int,array<string,mixed>> $reports
 * @var array<int,array<string,mixed>> $clients
 */

use NorthLab\Core\Auth;
use NorthLab\Core\View;
use NorthLab\Repository\ClientRepository;

View::set( 'pageTitle', 'Berichte' );
$canWrite = Auth::canWrite();
?>

<div class="grid side">
	<div class="card">
		<div class="card-head"><h2>Erstellte Berichte</h2></div>
		<?php if ( ! ( $pdfReady ?? false ) ) : ?>
			<div class="notice warn" style="margin:0 16px 12px">
				<strong>PDF ist auf diesem Server nicht möglich.</strong>
				<?= e( $pdfHint ?? '' ) ?>
			</div>
		<?php endif; ?>
		<div class="card-body tight">
			<?php if ( ! $reports ) : ?>
				<div class="empty"><strong>Noch keine Berichte</strong>Erstelle rechts einen Bericht oder hinterlege je Kunde einen Rhythmus.</div>
			<?php else : ?>
				<div class="table-wrap">
					<table class="data">
						<thead><tr><th>Bericht</th><th>Kunde</th><th>Zeitraum</th><th>Erstellt</th><th class="shrink"></th></tr></thead>
						<tbody>
						<?php foreach ( $reports as $report ) : ?>
							<tr>
								<td><a href="<?= e( url( '/reports/' . $report['id'] ) ) ?>" target="_blank" rel="noopener"><strong><?= e( (string) $report['title'] ) ?></strong></a></td>
								<td class="small">
									<?php if ( ! empty( $report['site_name'] ) ) : ?>
										<?= e( (string) $report['site_name'] ) ?> <span class="badge sm">eine Seite</span>
									<?php else : ?>
										<?= e( (string) ( $report['client_name'] ?? 'Alle Seiten' ) ) ?>
									<?php endif; ?>
								</td>
								<td class="small muted nowrap">
									<?= e( nl_date( (string) $report['period_start'], 'd.m.Y' ) ) ?> – <?= e( nl_date( (string) $report['period_end'], 'd.m.Y' ) ) ?>
								</td>
								<td class="small muted nowrap"><?= e( nl_date( (string) $report['created_at'] ) ) ?></td>
								<td class="shrink">
									<div class="btn-row">
										<a class="btn sm" href="<?= e( url( '/reports/' . $report['id'] ) ) ?>" target="_blank" rel="noopener">Ansehen</a>
										<?php if ( $pdfReady ?? false ) : ?>
											<a class="btn sm" href="<?= e( url( '/reports/' . $report['id'] . '/pdf' ) ) ?>">PDF</a>
										<?php endif; ?>
										<a class="btn sm" href="<?= e( url( '/reports/' . $report['id'] . '/download' ) ) ?>">HTML</a>
										<?php if ( $canWrite ) : ?>
											<form method="post" action="<?= e( url( '/reports/' . $report['id'] . '/delete' ) ) ?>" data-confirm="Bericht löschen?">
												<?= csrf_field() ?>
												<button class="btn sm danger">Löschen</button>
											</form>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<div>
		<?php if ( $canWrite ) : ?>
			<div class="card">
				<div class="card-head"><h3>Bericht erstellen</h3></div>
				<div class="card-body">
					<form method="post" action="<?= e( url( '/reports' ) ) ?>">
						<?= csrf_field() ?>
						<div class="field">
							<label for="client_id">Kunde</label>
							<select id="client_id" name="client_id">
								<option value="">Alle Seiten (interner Überblick)</option>
								<?php foreach ( $clients as $client ) : ?>
									<option value="<?= e( (string) $client['id'] ) ?>"><?= e( (string) $client['name'] ) ?> (<?= e( (string) $client['site_count'] ) ?>)</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="field">
							<label for="site_id">Einzelne Seite</label>
							<select id="site_id" name="site_id">
								<option value="">— ganzer Kunde —</option>
								<?php foreach ( ( $sites ?? array() ) as $s ) : ?>
									<option value="<?= e( (string) $s['id'] ) ?>"><?= e( (string) $s['name'] ) ?></option>
								<?php endforeach; ?>
							</select>
							<div class="hint">
								Ist hier eine Seite gewählt, gilt nur sie — der Kunde darüber wird dann
								nicht beachtet, und es geht keine E-Mail raus.
							</div>
						</div>
						<div class="field">
							<label for="days">Zeitraum</label>
							<select id="days" name="days">
								<option value="7">letzte 7 Tage</option>
								<option value="30" selected>letzte 30 Tage</option>
								<option value="90">letztes Quartal</option>
								<option value="365">letztes Jahr</option>
							</select>
						</div>
						<div class="field inline">
							<input type="checkbox" id="deliver" name="deliver" value="1">
							<label for="deliver">An den Kunden senden <span class="muted">(E-Mail und/oder Webhook)</span></label>
						</div>
						<button class="btn primary" data-busy="Erstelle…">Bericht erstellen</button>
					</form>
				</div>
			</div>
		<?php endif; ?>

		<div class="card">
			<div class="card-head"><h3>Automatischer Versand</h3></div>
			<div class="card-body tight">
				<table class="data">
					<thead><tr><th>Kunde</th><th>Rhythmus</th><th>Zuletzt</th></tr></thead>
					<tbody>
					<?php foreach ( $clients as $client ) : ?>
						<tr>
							<td><a href="<?= e( url( '/clients/' . $client['id'] ) ) ?>"><?= e( (string) $client['name'] ) ?></a></td>
							<td class="small">
								<?php if ( 'off' === $client['report_frequency'] ) : ?>
									<span class="muted">aus</span>
								<?php else : ?>
									<span class="badge info"><?= e( ClientRepository::FREQUENCIES[ $client['report_frequency'] ] ?? '' ) ?></span>
								<?php endif; ?>
							</td>
							<td class="small muted"><?= e( nl_ago( $client['last_report_at'] ) ) ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $clients ) : ?>
						<tr><td colspan="3" class="muted small" style="padding:16px">Noch keine Kunden angelegt.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
			<div class="card-foot small muted">
				Berichte werden vom Zeitplaner erstellt und ausgeliefert — als E-Mail an den Kunden
				und, falls hinterlegt, als signierter Webhook mit allen Kennzahlen als JSON.
			</div>
		</div>
	</div>
</div>
