<?php
/** @var array<int,array<string,mixed>> $clients */

use NorthLab\Core\Auth;
use NorthLab\Core\View;
use NorthLab\Repository\ClientRepository;

View::set( 'pageTitle', 'Kunden' );
$canWrite = Auth::canWrite();
?>

<div class="grid side">
	<div class="card">
		<div class="card-head"><h2><?= e( (string) count( $clients ) ) ?> Kunde(n)</h2></div>
		<div class="card-body tight">
			<?php if ( ! $clients ) : ?>
				<div class="empty"><strong>Noch keine Kunden</strong>Lege rechts einen an, um Seiten zu gruppieren und Berichte zu versenden.</div>
			<?php else : ?>
				<div class="table-wrap">
					<table class="data">
						<thead><tr><th>Kunde</th><th>Kontakt</th><th class="num">Seiten</th><th class="num">Updates</th><th>Berichte</th><th>Letzter Bericht</th></tr></thead>
						<tbody>
						<?php foreach ( $clients as $client ) : ?>
							<tr>
								<td>
									<a href="<?= e( url( '/clients/' . $client['id'] ) ) ?>"><strong><?= e( (string) $client['name'] ) ?></strong></a>
									<?php if ( ! empty( $client['contact_name'] ) ) : ?>
										<div class="muted small"><?= e( (string) $client['contact_name'] ) ?></div>
									<?php endif; ?>
								</td>
								<td class="small"><?= e( $client['email'] ?: '—' ) ?></td>
								<td class="num"><?= e( (string) $client['site_count'] ) ?></td>
								<td class="num">
									<?php if ( (int) $client['pending_updates'] > 0 ) : ?>
										<span class="badge warn"><?= e( (string) $client['pending_updates'] ) ?></span>
									<?php else : ?>
										<span class="muted">0</span>
									<?php endif; ?>
								</td>
								<td class="small"><?= e( ClientRepository::FREQUENCIES[ $client['report_frequency'] ] ?? '—' ) ?></td>
								<td class="small muted"><?= e( nl_ago( $client['last_report_at'] ) ) ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $canWrite ) : ?>
		<div class="card">
			<div class="card-head"><h3>Kunde anlegen</h3></div>
			<div class="card-body">
				<form method="post" action="<?= e( url( '/clients' ) ) ?>">
					<?= csrf_field() ?>
					<div class="field">
						<label for="name">Name</label>
						<input type="text" id="name" name="name" required>
					</div>
					<div class="field">
						<label for="contact_name">Ansprechpartner</label>
						<input type="text" id="contact_name" name="contact_name">
					</div>
					<div class="field">
						<label for="email">E-Mail für Berichte</label>
						<input type="email" id="email" name="email">
					</div>
					<div class="field">
						<label for="report_frequency">Berichtsrhythmus</label>
						<select id="report_frequency" name="report_frequency">
							<?php foreach ( ClientRepository::FREQUENCIES as $value => $label ) : ?>
								<option value="<?= e( $value ) ?>" <?= 'monthly' === $value ? 'selected' : '' ?>><?= e( $label ) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="field inline">
						<input type="checkbox" id="report_email_enabled" name="report_email_enabled" value="1" checked>
						<label for="report_email_enabled">Berichte per E-Mail senden</label>
					</div>
					<button class="btn primary">Anlegen</button>
				</form>
			</div>
		</div>
	<?php endif; ?>
</div>
