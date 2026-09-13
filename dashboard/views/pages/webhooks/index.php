<?php
/**
 * @var array<int,array<string,mixed>> $webhooks
 * @var array<string,string>           $catalog
 * @var array<int,array<string,mixed>> $clients
 * @var array<int,array<string,mixed>> $deliveries
 * @var array<string,int>              $queue
 * @var int                            $selected
 */

use NorthLab\Core\Auth;
use NorthLab\Core\View;
use NorthLab\Repository\WebhookRepository;

View::set( 'pageTitle', 'Webhooks' );
$canWrite = Auth::canWrite();
?>

<div class="grid cols-4" style="margin-bottom:18px">
	<div class="stat"><div class="label">Endpunkte</div><div class="value"><?= e( (string) count( $webhooks ) ) ?></div></div>
	<div class="stat <?= $queue['pending'] > 0 ? 'warn' : 'ok' ?>"><div class="label">In Warteschlange</div><div class="value"><?= e( (string) $queue['pending'] ) ?></div></div>
	<div class="stat ok"><div class="label">Zugestellt</div><div class="value"><?= e( (string) $queue['success'] ) ?></div></div>
	<div class="stat <?= $queue['failed'] > 0 ? 'bad' : '' ?>"><div class="label">Aufgegeben</div><div class="value"><?= e( (string) $queue['failed'] ) ?></div></div>
</div>

<div class="notice">
	<strong>Signatur:</strong> Jede Zustellung trägt die Header
	<code>X-NorthLab-Event</code>, <code>X-NorthLab-Timestamp</code> und
	<code>X-NorthLab-Signature: sha256=&lt;HMAC&gt;</code>.
	Der HMAC wird mit dem Secret des Endpunkts über <code>"&lt;timestamp&gt;.&lt;body&gt;"</code> gebildet.
	Fehlgeschlagene Zustellungen werden fünfmal mit wachsendem Abstand wiederholt (1 Min. bis 3 Std.).
</div>

<div class="grid side">
	<div>
		<div class="card">
			<div class="card-head"><h2>Endpunkte</h2></div>
			<div class="card-body tight">
				<?php if ( ! $webhooks ) : ?>
					<div class="empty"><strong>Noch kein Webhook</strong>Lege rechts einen an, um Ereignisse an Slack, n8n, Make, Zapier oder ein eigenes System zu schicken.</div>
				<?php else : ?>
					<?php foreach ( $webhooks as $webhook ) : ?>
						<div style="padding:16px 18px;border-bottom:1px solid var(--line)">
							<form method="post" action="<?= e( url( '/webhooks/' . $webhook['id'] ) ) ?>">
								<?= csrf_field() ?>
								<div class="form-grid">
									<div class="field">
										<label>Name</label>
										<input type="text" name="name" value="<?= e( (string) $webhook['name'] ) ?>">
									</div>
									<div class="field">
										<label>Kunde (optional)</label>
										<select name="client_id">
											<option value="">Alle Ereignisse</option>
											<?php foreach ( $clients as $client ) : ?>
												<option value="<?= e( (string) $client['id'] ) ?>" <?= (int) $webhook['client_id'] === (int) $client['id'] ? 'selected' : '' ?>>
													nur <?= e( (string) $client['name'] ) ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="field full">
										<label>Ziel-URL</label>
										<input type="url" name="target_url" value="<?= e( (string) $webhook['target_url'] ) ?>" required>
									</div>
									<div class="field full">
										<label>Secret</label>
										<input type="text" name="secret" class="mono" value="<?= e( (string) $webhook['secret'] ) ?>">
									</div>
								</div>

								<div class="field">
									<label>Ereignisse</label>
									<div class="grid cols-3">
										<?php $subscribed = WebhookRepository::events( $webhook ); ?>
										<?php foreach ( $catalog as $event => $label ) : ?>
											<label class="small" style="display:flex;gap:6px;align-items:flex-start">
												<input type="checkbox" name="events[]" value="<?= e( $event ) ?>" <?= in_array( $event, $subscribed, true ) ? 'checked' : '' ?>>
												<span><?= e( $label ) ?><br><span class="muted mono" style="font-size:11px"><?= e( $event ) ?></span></span>
											</label>
										<?php endforeach; ?>
									</div>
								</div>

								<div class="btn-row">
									<label class="small" style="display:flex;gap:6px;align-items:center">
										<input type="checkbox" name="is_active" value="1" <?= ! empty( $webhook['is_active'] ) ? 'checked' : '' ?>> aktiv
									</label>
									<button class="btn primary sm" <?= $canWrite ? '' : 'disabled' ?>>Speichern</button>
									<span class="spacer" style="flex:1"></span>
									<?php if ( ! empty( $webhook['last_status'] ) ) : ?>
										<span class="badge <?= 'success' === $webhook['last_status'] ? 'ok' : ( 'failed' === $webhook['last_status'] ? 'bad' : 'warn' ) ?>">
											<?= e( (string) $webhook['last_status'] ) ?> · <?= e( nl_ago( $webhook['last_delivery_at'] ) ) ?>
										</span>
									<?php endif; ?>
									<a class="btn sm" href="<?= e( url( '/webhooks?webhook_id=' . $webhook['id'] ) ) ?>">Zustellungen</a>
								</div>
							</form>

							<?php if ( $canWrite ) : ?>
								<div class="btn-row mt">
									<form method="post" action="<?= e( url( '/webhooks/' . $webhook['id'] . '/test' ) ) ?>">
										<?= csrf_field() ?>
										<button class="btn sm" data-busy="Sende…">Testzustellung</button>
									</form>
									<form method="post" action="<?= e( url( '/webhooks/' . $webhook['id'] . '/delete' ) ) ?>"
										data-confirm="Webhook wirklich löschen?">
										<?= csrf_field() ?>
										<button class="btn sm danger">Löschen</button>
									</form>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>

		<div class="card">
			<div class="card-head">
				<h2>Zustellungen</h2>
				<div class="spacer"></div>
				<?php if ( $selected > 0 ) : ?>
					<a class="btn sm" href="<?= e( url( '/webhooks' ) ) ?>">Filter aufheben</a>
				<?php endif; ?>
			</div>
			<div class="card-body tight">
				<?php if ( ! $deliveries ) : ?>
					<div class="empty">Noch keine Zustellungen.</div>
				<?php else : ?>
					<div class="table-wrap">
						<table class="data">
							<thead><tr><th>Zeitpunkt</th><th>Endpunkt</th><th>Ereignis</th><th>Status</th><th class="num">Versuche</th><th>Antwort</th><th></th></tr></thead>
							<tbody>
							<?php foreach ( $deliveries as $delivery ) : ?>
								<tr>
									<td class="nowrap small muted"><?= e( nl_date( (string) $delivery['updated_at'] ) ) ?></td>
									<td class="small"><?= e( (string) ( $delivery['webhook_name'] ?? '—' ) ) ?></td>
									<td class="mono small"><?= e( (string) $delivery['event'] ) ?></td>
									<td>
										<?php $status = (string) $delivery['status']; ?>
										<?php if ( 'success' === $status ) : ?>
											<span class="badge ok">zugestellt</span>
										<?php elseif ( 'failed' === $status ) : ?>
											<span class="badge bad">aufgegeben</span>
										<?php else : ?>
											<span class="badge warn">wartet</span>
										<?php endif; ?>
									</td>
									<td class="num small"><?= e( (string) $delivery['attempts'] ) ?></td>
									<td class="small muted truncate">
										<?= (int) $delivery['response_code'] > 0 ? 'HTTP ' . e( (string) $delivery['response_code'] ) . ' · ' : '' ?>
										<?= e( (string) $delivery['response_body'] ) ?>
									</td>
									<td class="shrink">
										<?php if ( $canWrite && 'success' !== $status ) : ?>
											<form method="post" action="<?= e( url( '/webhooks/deliveries/' . $delivery['id'] . '/retry' ) ) ?>">
												<?= csrf_field() ?>
												<button class="btn sm">Erneut</button>
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

	<?php if ( $canWrite ) : ?>
		<div class="card">
			<div class="card-head"><h3>Endpunkt anlegen</h3></div>
			<div class="card-body">
				<form method="post" action="<?= e( url( '/webhooks' ) ) ?>">
					<?= csrf_field() ?>
					<div class="field">
						<label for="new_name">Name</label>
						<input type="text" id="new_name" name="name" placeholder="z. B. Slack #ops">
					</div>
					<div class="field">
						<label for="new_url">Ziel-URL</label>
						<input type="url" id="new_url" name="target_url" required placeholder="https://hooks.example.com/…">
					</div>
					<div class="field">
						<label for="new_client">Nur für Kunde</label>
						<select id="new_client" name="client_id">
							<option value="">Alle Ereignisse</option>
							<?php foreach ( $clients as $client ) : ?>
								<option value="<?= e( (string) $client['id'] ) ?>"><?= e( (string) $client['name'] ) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="field">
						<label>Ereignisse</label>
						<?php foreach ( $catalog as $event => $label ) : ?>
							<label class="small" style="display:flex;gap:6px;align-items:center;margin-bottom:3px">
								<input type="checkbox" name="events[]" value="<?= e( $event ) ?>"
									<?= in_array( $event, array( 'site.offline', 'site.online', 'update.applied', 'update.failed' ), true ) ? 'checked' : '' ?>>
								<?= e( $label ) ?>
							</label>
						<?php endforeach; ?>
					</div>
					<div class="field inline">
						<input type="checkbox" id="new_active" name="is_active" value="1" checked>
						<label for="new_active">sofort aktiv</label>
					</div>
					<button class="btn primary">Anlegen</button>
					<p class="hint">Ein Secret wird automatisch erzeugt, wenn keins angegeben ist.</p>
				</form>
			</div>
		</div>
	<?php endif; ?>
</div>
