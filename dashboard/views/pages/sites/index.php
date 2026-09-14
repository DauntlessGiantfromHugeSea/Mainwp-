<?php
/**
 * @var array<int,array<string,mixed>> $sites
 * @var array<int,array<string,mixed>> $clients
 * @var array<int,string>              $tags
 * @var array<string,mixed>            $filters
 */

use NorthLab\Core\Auth;
use NorthLab\Core\View;

View::set( 'pageTitle', 'Seiten' );
View::set(
	'headerActions',
	Auth::canWrite() ? '<a class="btn primary" href="' . e( url( '/sites/new' ) ) . '">Seite hinzufügen</a>' : ''
);
?>

<div class="card">
	<div class="card-body">
		<form method="get" action="<?= e( url( '/sites' ) ) ?>" class="filters">
			<div class="field">
				<label for="q">Suche</label>
				<input type="search" id="q" name="q" value="<?= e( (string) $filters['search'] ) ?>" placeholder="Name oder URL">
			</div>
			<div class="field">
				<label for="client_id">Kunde</label>
				<select id="client_id" name="client_id" data-auto-submit>
					<option value="">Alle</option>
					<?php foreach ( $clients as $client ) : ?>
						<option value="<?= e( (string) $client['id'] ) ?>" <?= (int) $filters['client_id'] === (int) $client['id'] ? 'selected' : '' ?>>
							<?= e( $client['name'] ) ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="field">
				<label for="status">Verbindung</label>
				<select id="status" name="status" data-auto-submit>
					<option value="">Alle</option>
					<option value="connected" <?= 'connected' === $filters['status'] ? 'selected' : '' ?>>Verbunden</option>
					<option value="error" <?= 'error' === $filters['status'] ? 'selected' : '' ?>>Fehler</option>
					<option value="pending" <?= 'pending' === $filters['status'] ? 'selected' : '' ?>>Ausstehend</option>
				</select>
			</div>
			<div class="field">
				<label for="type">Art</label>
				<select id="type" name="type" data-auto-submit>
					<option value="">Alle</option>
					<?php foreach ( \NorthLab\Repository\SiteRepository::TYPES as $typeKey => $typeLabel ) : ?>
						<option value="<?= e( $typeKey ) ?>" <?= $typeKey === $filters['site_type'] ? 'selected' : '' ?>>
							<?= e( $typeLabel ) ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="field">
				<label for="uptime">Erreichbarkeit</label>
				<select id="uptime" name="uptime" data-auto-submit>
					<option value="">Alle</option>
					<option value="up" <?= 'up' === $filters['uptime_status'] ? 'selected' : '' ?>>Online</option>
					<option value="down" <?= 'down' === $filters['uptime_status'] ? 'selected' : '' ?>>Offline</option>
				</select>
			</div>
			<?php if ( $tags ) : ?>
				<div class="field">
					<label for="tag">Tag</label>
					<select id="tag" name="tag" data-auto-submit>
						<option value="">Alle</option>
						<?php foreach ( $tags as $tag ) : ?>
							<option value="<?= e( $tag ) ?>" <?= $tag === $filters['tag'] ? 'selected' : '' ?>><?= e( $tag ) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>
			<div class="field inline" style="align-items:center;padding-bottom:6px">
				<input type="checkbox" id="updates" name="updates" value="1" <?= $filters['has_updates'] ? 'checked' : '' ?> data-auto-submit>
				<label for="updates">nur mit Updates</label>
			</div>
			<button class="btn" type="submit">Filtern</button>
			<a class="btn" href="<?= e( url( '/sites' ) ) ?>">Zurücksetzen</a>
		</form>
	</div>
</div>

<form method="post" action="<?= e( url( '/sites/bulk' ) ) ?>" data-selection-scope
	data-confirm="Sammelaktion für die ausgewählten Seiten wirklich ausführen?">
	<?= csrf_field() ?>

	<div class="card">
		<div class="card-head">
			<h2><?= e( (string) count( $sites ) ) ?> Seite(n)</h2>
			<div class="spacer"></div>
			<?php if ( Auth::canWrite() ) : ?>
				<span class="muted small"><span data-selection-count>0</span> ausgewählt</span>
				<select name="bulk_action" style="width:auto">
					<option value="sync">Synchronisieren</option>
					<option value="update">Alle Updates einspielen</option>
					<option value="child-update">Child-Plugin aktualisieren</option>
					<option value="mmode-on">Wartungsmodus an (1 Stunde)</option>
					<option value="mmode-off">Wartungsmodus aus</option>
					<option value="pause">Pausieren</option>
					<option value="resume">Fortsetzen</option>
					<option value="delete">Entfernen</option>
				</select>
				<input type="hidden" name="minutes" value="60">
				<button class="btn" type="submit" data-selection-disable disabled data-busy="läuft…">Ausführen</button>
			<?php endif; ?>
		</div>

		<div class="card-body tight">
			<?php if ( ! $sites ) : ?>
				<div class="empty">
					<strong>Keine Seiten gefunden</strong>
					Passe die Filter an oder füge eine neue Seite hinzu.
				</div>
			<?php else : ?>
				<div class="table-wrap" id="site-table">
					<table class="data">
						<thead>
						<tr>
							<?php if ( Auth::canWrite() ) : ?>
								<th class="shrink"><input type="checkbox" data-check-all="#site-table"></th>
							<?php endif; ?>
							<th>Seite</th>
							<th>Kunde</th>
							<th>Status</th>
							<th class="num">Updates</th>
							<th class="num">Sicherheit</th>
							<th>WordPress</th>
							<th>PHP</th>
							<th>Letzter Sync</th>
							<th></th>
						</tr>
						</thead>
						<tbody>
						<?php foreach ( $sites as $site ) : ?>
							<tr>
								<?php if ( Auth::canWrite() ) : ?>
									<td class="shrink">
										<input type="checkbox" name="site_ids[]" data-item value="<?= e( (string) $site['id'] ) ?>">
									</td>
								<?php endif; ?>
								<td>
									<a href="<?= e( url( '/sites/' . $site['id'] ) ) ?>"><strong><?= e( $site['name'] ) ?></strong></a>
									<div class="muted small">
										<?= e( nl_host( (string) $site['url'] ) ) ?>
										<?php if ( ! \NorthLab\Repository\SiteRepository::isManaged( $site ) ) : ?>
											· <span class="badge">nur Überwachung</span>
										<?php endif; ?>
									</div>
									<?php foreach ( \NorthLab\Repository\SiteRepository::tags( $site ) as $tag ) : ?>
										<span class="tag"><?= e( $tag ) ?></span>
									<?php endforeach; ?>
								</td>
								<td class="small">
									<?php if ( ! empty( $site['client_name'] ) ) : ?>
										<a href="<?= e( url( '/clients/' . $site['client_id'] ) ) ?>"><?= e( $site['client_name'] ) ?></a>
									<?php else : ?>
										<span class="muted">—</span>
									<?php endif; ?>
								</td>
								<td class="nowrap">
									<?= nl_status_badge( $site ) ?> <?= nl_uptime_badge( $site ) ?>
									<?php if ( ! empty( $site['maintenance_mode'] ) ) : ?>
										<span class="badge warn" title="Besucher sehen die Wartungsseite">Wartung</span>
									<?php endif; ?>
									<?php if ( \NorthLab\Service\ChildPluginService::isOutdated( $site ) ) : ?>
										<span class="badge warn" title="Child-Plugin veraltet — Sammelaktion &quot;Child-Plugin aktualisieren&quot;">Child <?= e( (string) $site['child_version'] ) ?></span>
									<?php endif; ?>
								</td>
								<?php $isManaged = \NorthLab\Repository\SiteRepository::isManaged( $site ); ?>
								<td class="num">
									<?php if ( ! $isManaged ) : ?>
										<span class="muted">—</span>
									<?php elseif ( (int) $site['pending_updates'] > 0 ) : ?>
										<a class="badge warn" href="<?= e( url( '/updates?q=' ) ) ?>"><?= e( (string) $site['pending_updates'] ) ?></a>
									<?php else : ?>
										<span class="muted">0</span>
									<?php endif; ?>
								</td>
								<td class="num">
									<?php if ( $isManaged && (int) $site['security_score'] > 0 ) : ?>
										<span class="badge <?= e( nl_score_class( (int) $site['security_score'] ) ) ?>"><?= e( (string) $site['security_score'] ) ?></span>
									<?php else : ?>
										<span class="muted">—</span>
									<?php endif; ?>
								</td>
								<td class="mono small"><?= e( $site['wp_version'] ?: '—' ) ?></td>
								<td class="mono small"><?= e( $site['php_version'] ?: '—' ) ?></td>
								<td class="muted small nowrap"><?= e( nl_ago( $site['last_sync_at'] ) ) ?></td>
								<td class="shrink">
									<a class="btn sm" href="<?= e( url( '/sites/' . $site['id'] ) ) ?>">Details</a>
								</td>
							</tr>
							<?php if ( ! empty( $site['last_error'] ) ) : ?>
								<tr>
									<td colspan="10" class="small" style="background:var(--bad-bg);color:#932020">
										<?= e( $site['last_error'] ) ?>
									</td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>
</form>
