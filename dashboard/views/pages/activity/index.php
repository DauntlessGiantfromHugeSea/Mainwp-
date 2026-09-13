<?php
/**
 * @var array<int,array<string,mixed>> $entries
 * @var array<int,array<string,mixed>> $sites
 * @var array<string,string>           $catalog
 * @var array<string,mixed>            $filters
 * @var int                            $page
 * @var int                            $perPage
 */

use NorthLab\Core\View;

View::set( 'pageTitle', 'Protokoll' );

$query = static function ( array $overrides ) use ( $filters, $page ): string {
	$params = array_filter(
		array(
			'site_id' => $filters['site_id'],
			'level'   => $filters['level'],
			'action'  => $filters['action'],
			'q'       => $filters['search'],
			'page'    => $page,
		) + $overrides
	);
	return url( '/activity?' . http_build_query( array_merge( $params, $overrides ) ) );
};
?>

<div class="card">
	<div class="card-body">
		<form method="get" action="<?= e( url( '/activity' ) ) ?>" class="filters">
			<div class="field">
				<label for="q">Suche</label>
				<input type="search" id="q" name="q" value="<?= e( (string) $filters['search'] ) ?>" placeholder="Meldung oder Ereignis">
			</div>
			<div class="field">
				<label for="site_id">Seite</label>
				<select id="site_id" name="site_id" data-auto-submit>
					<option value="">Alle</option>
					<?php foreach ( $sites as $site ) : ?>
						<option value="<?= e( (string) $site['id'] ) ?>" <?= (int) $filters['site_id'] === (int) $site['id'] ? 'selected' : '' ?>>
							<?= e( (string) $site['name'] ) ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="field">
				<label for="action">Ereignis</label>
				<select id="action" name="action" data-auto-submit>
					<option value="">Alle</option>
					<?php foreach ( $catalog as $event => $label ) : ?>
						<option value="<?= e( $event ) ?>" <?= $filters['action'] === $event ? 'selected' : '' ?>><?= e( $label ) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="field">
				<label for="level">Stufe</label>
				<select id="level" name="level" data-auto-submit>
					<option value="">Alle</option>
					<option value="info" <?= 'info' === $filters['level'] ? 'selected' : '' ?>>Info</option>
					<option value="warning" <?= 'warning' === $filters['level'] ? 'selected' : '' ?>>Warnung</option>
					<option value="error" <?= 'error' === $filters['level'] ? 'selected' : '' ?>>Fehler</option>
				</select>
			</div>
			<button class="btn" type="submit">Filtern</button>
			<a class="btn" href="<?= e( url( '/activity' ) ) ?>">Zurücksetzen</a>
		</form>
	</div>
</div>

<div class="card">
	<div class="card-head"><h2>Ereignisse</h2></div>
	<div class="card-body tight">
		<?php if ( ! $entries ) : ?>
			<div class="empty">Keine Einträge für diese Filter.</div>
		<?php else : ?>
			<div class="table-wrap">
				<table class="data">
					<thead><tr><th>Zeitpunkt</th><th>Stufe</th><th>Ereignis</th><th>Seite</th><th>Meldung</th><th>Benutzer</th></tr></thead>
					<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<td class="nowrap small muted"><?= e( nl_date( (string) $entry['created_at'], 'd.m.Y H:i:s' ) ) ?></td>
							<td><?= nl_level_badge( (string) $entry['level'] ) ?></td>
							<td class="mono small"><?= e( (string) $entry['action'] ) ?></td>
							<td class="small">
								<?php if ( ! empty( $entry['site_name'] ) ) : ?>
									<a href="<?= e( url( '/sites/' . $entry['site_id'] ) ) ?>"><?= e( (string) $entry['site_name'] ) ?></a>
								<?php else : ?>
									<span class="muted">—</span>
								<?php endif; ?>
							</td>
							<td class="small"><?= e( (string) $entry['message'] ) ?></td>
							<td class="small muted"><?= e( (string) ( $entry['user_email'] ?? 'System' ) ) ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
	<div class="card-foot btn-row">
		<?php if ( $page > 1 ) : ?>
			<a class="btn sm" href="<?= e( $query( array( 'page' => $page - 1 ) ) ) ?>">← Neuere</a>
		<?php endif; ?>
		<span class="muted small">Seite <?= e( (string) $page ) ?></span>
		<?php if ( count( $entries ) >= $perPage ) : ?>
			<a class="btn sm" href="<?= e( $query( array( 'page' => $page + 1 ) ) ) ?>">Ältere →</a>
		<?php endif; ?>
	</div>
</div>
