<?php
/**
 * @var array<string,array<string,mixed>> $grouped
 * @var array<int,array<string,mixed>>    $rows
 * @var array<string,int>                 $counts
 * @var array<int,array<string,mixed>>    $clients
 * @var array<string,mixed>               $filters
 * @var array<int,array<string,mixed>>    $sites
 * @var array<int,string>                 $excludes
 */

use NorthLab\Core\Auth;
use NorthLab\Core\View;

View::set( 'pageTitle', 'Updates' );

$canWrite = Auth::canWrite();
$total    = array_sum( $counts );

if ( $canWrite && $total > 0 ) {
	View::set(
		'headerActions',
		'<form method="post" action="' . e( url( '/updates/apply' ) ) . '" '
		. 'data-confirm="Wirklich alle offenen Updates auf allen Seiten einspielen?">'
		. csrf_field()
		. '<input type="hidden" name="mode" value="all">'
		. '<button class="btn primary" data-busy="Läuft…">Alles aktualisieren</button></form>'
	);
}
?>

<div class="grid cols-4" style="margin-bottom:18px">
	<div class="stat <?= $total > 0 ? 'warn' : 'ok' ?>">
		<div class="label">Offene Updates</div>
		<div class="value"><?= e( (string) $total ) ?></div>
		<div class="sub">über <?= e( (string) count( $sites ) ) ?> Seite(n)</div>
	</div>
	<div class="stat"><div class="label">WordPress-Core</div><div class="value"><?= e( (string) $counts['core'] ) ?></div></div>
	<div class="stat"><div class="label">Plugins</div><div class="value"><?= e( (string) $counts['plugin'] ) ?></div></div>
	<div class="stat"><div class="label">Themes</div><div class="value"><?= e( (string) $counts['theme'] ) ?></div></div>
</div>

<?php if ( $excludes ) : ?>
	<div class="notice">
		Von automatischen Updates ausgenommen:
		<?php foreach ( $excludes as $pattern ) : ?><span class="tag mono"><?= e( $pattern ) ?></span><?php endforeach; ?>
	</div>
<?php endif; ?>

<div class="card">
	<div class="card-body">
		<form method="get" action="<?= e( url( '/updates' ) ) ?>" class="filters">
			<div class="field">
				<label for="q">Suche</label>
				<input type="search" id="q" name="q" value="<?= e( (string) $filters['search'] ) ?>" placeholder="Plugin- oder Theme-Name">
			</div>
			<div class="field">
				<label for="type">Typ</label>
				<select id="type" name="type" data-auto-submit>
					<option value="">Alle</option>
					<option value="core" <?= 'core' === $filters['type'] ? 'selected' : '' ?>>WordPress</option>
					<option value="plugin" <?= 'plugin' === $filters['type'] ? 'selected' : '' ?>>Plugins</option>
					<option value="theme" <?= 'theme' === $filters['type'] ? 'selected' : '' ?>>Themes</option>
				</select>
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
			<div class="field inline" style="align-items:center;padding-bottom:6px">
				<input type="checkbox" id="ignored" name="ignored" value="1" <?= $filters['include_ignored'] ? 'checked' : '' ?> data-auto-submit>
				<label for="ignored">ignorierte anzeigen</label>
			</div>
			<button class="btn" type="submit">Filtern</button>
			<a class="btn" href="<?= e( url( '/updates' ) ) ?>">Zurücksetzen</a>
		</form>
	</div>
</div>

<div class="tabs">
	<button data-tab="grouped" class="active">Nach Erweiterung</button>
	<button data-tab="bysite">Nach Seite</button>
</div>

<div class="tab-panel active" data-tab-panel="grouped">
	<div class="card">
		<div class="card-head"><h2><?= e( (string) count( $grouped ) ) ?> Erweiterung(en) mit Updates</h2></div>
		<div class="card-body tight">
			<?php if ( ! $grouped ) : ?>
				<div class="empty"><strong>Alles aktuell</strong>Keine offenen Updates.</div>
			<?php else : ?>
				<div class="table-wrap">
					<table class="data">
						<thead>
						<tr><th>Erweiterung</th><th>Typ</th><th>Zielversion</th><th>Betroffene Seiten</th><th class="shrink"></th></tr>
						</thead>
						<tbody>
						<?php foreach ( $grouped as $key => $group ) : ?>
							<tr>
								<td>
									<strong><?= e( $group['name'] ) ?></strong>
									<div class="muted small mono"><?= e( $group['slug'] ) ?></div>
								</td>
								<td><span class="badge info"><?= e( nl_update_type( $group['type'] ) ) ?></span></td>
								<td class="mono"><strong><?= e( $group['new_version'] ) ?></strong></td>
								<td class="small">
									<?php foreach ( array_slice( $group['sites'], 0, 6 ) as $entry ) : ?>
										<a class="tag" href="<?= e( url( '/sites/' . $entry['id'] ) ) ?>#updates">
											<?= e( $entry['name'] ) ?> (<?= e( $entry['current_version'] ) ?>)
										</a>
									<?php endforeach; ?>
									<?php if ( count( $group['sites'] ) > 6 ) : ?>
										<span class="muted">+<?= e( (string) ( count( $group['sites'] ) - 6 ) ) ?> weitere</span>
									<?php endif; ?>
								</td>
								<td class="shrink">
									<?php if ( $canWrite ) : ?>
										<form method="post" action="<?= e( url( '/updates/apply' ) ) ?>"
											data-confirm="<?= e( sprintf( '%s auf %d Seite(n) aktualisieren?', $group['name'], count( $group['sites'] ) ) ) ?>">
											<?= csrf_field() ?>
											<input type="hidden" name="mode" value="group">
											<input type="hidden" name="type" value="<?= e( $group['type'] ) ?>">
											<input type="hidden" name="slug" value="<?= e( $group['slug'] ) ?>">
											<button class="btn sm primary" data-busy="Läuft…">
												Auf <?= e( (string) count( $group['sites'] ) ) ?> Seite(n)
											</button>
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

<div class="tab-panel" data-tab-panel="bysite">
	<form method="post" action="<?= e( url( '/updates/apply' ) ) ?>" data-selection-scope id="update-rows">
		<?= csrf_field() ?>
		<input type="hidden" name="mode" value="selected">

		<div class="card">
			<div class="card-head">
				<h2><?= e( (string) count( $rows ) ) ?> einzelne Updates</h2>
				<div class="spacer"></div>
				<?php if ( $canWrite ) : ?>
					<button class="btn primary" data-selection-disable disabled data-busy="Läuft…">
						Auswahl einspielen (<span data-selection-count>0</span>)
					</button>
				<?php endif; ?>
			</div>
			<div class="card-body tight">
				<?php if ( ! $rows ) : ?>
					<div class="empty">Keine Einträge.</div>
				<?php else : ?>
					<div class="table-wrap">
						<table class="data">
							<thead>
							<tr>
								<?php if ( $canWrite ) : ?>
									<th class="shrink"><input type="checkbox" data-check-all="#update-rows"></th>
								<?php endif; ?>
								<th>Seite</th><th>Erweiterung</th><th>Typ</th><th>Installiert</th><th>Verfügbar</th><th></th>
							</tr>
							</thead>
							<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<?php if ( $canWrite ) : ?>
										<td class="shrink">
											<input type="checkbox" data-item name="items[]"
												value="<?= e( $row['site_id'] . '|' . $row['type'] . '|' . $row['slug'] ) ?>"
												<?= ! empty( $row['is_ignored'] ) ? 'disabled' : '' ?>>
										</td>
									<?php endif; ?>
									<td>
										<a href="<?= e( url( '/sites/' . $row['site_id'] ) ) ?>"><?= e( (string) $row['site_name'] ) ?></a>
										<div class="muted small"><?= e( nl_host( (string) $row['site_url'] ) ) ?></div>
									</td>
									<td>
										<strong><?= e( (string) $row['name'] ) ?></strong>
										<div class="muted small mono"><?= e( (string) $row['slug'] ) ?></div>
									</td>
									<td><span class="badge info"><?= e( nl_update_type( (string) $row['type'] ) ) ?></span></td>
									<td class="mono small"><?= e( (string) $row['current_version'] ) ?></td>
									<td class="mono small"><strong><?= e( (string) $row['new_version'] ) ?></strong></td>
									<td class="shrink">
										<?php if ( ! empty( $row['is_ignored'] ) ) : ?><span class="badge">ignoriert</span><?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</form>
</div>
