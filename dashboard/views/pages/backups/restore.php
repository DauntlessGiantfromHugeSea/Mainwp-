<?php
/**
 * In einer Sicherung bloettern.
 *
 * @var array<string,mixed>      $site
 * @var array<int,array<string,mixed>> $snapshots
 * @var string                   $snapshot
 * @var array<string,mixed>      $view
 * @var bool                     $ready
 */

use NorthLab\Core\View;

View::set( 'title', 'Wiederherstellen — ' . (string) $site['name'] );
View::set( 'headerActions', '<a class="btn" href="' . e( url( '/sites/' . $site['id'] ) ) . '">Zurück zur Seite</a>' );

$basis = '/sites/' . (int) $site['id'] . '/restore';
?>

<div class="card">
	<div class="card-head"><h2>Sicherungspunkte</h2></div>
	<div class="card-body tight">
		<?php if ( ! $ready ) : ?>
			<div class="empty">Es ist kein Sicherungsziel eingerichtet — unter Sicherungen → Einrichten.</div>
		<?php elseif ( ! $snapshots ) : ?>
			<div class="empty">Für diese Seite gibt es noch keine Sicherung.</div>
		<?php else : ?>
			<div class="table-wrap">
				<table class="data">
					<thead><tr><th>Zeitpunkt</th><th>Kennung</th><th class="shrink"></th></tr></thead>
					<tbody>
					<?php foreach ( $snapshots as $punkt ) : ?>
						<?php $kurz = (string) ( $punkt['short_id'] ?? '' ); ?>
						<tr<?= $kurz === $snapshot ? ' class="is-active"' : '' ?>>
							<td><?= e( nl_date( (string) ( $punkt['time'] ?? '' ) ) ) ?>
								<span class="muted small">· <?= e( nl_ago( (string) ( $punkt['time'] ?? '' ) ) ) ?></span>
							</td>
							<td class="mono small"><?= e( $kurz ) ?></td>
							<td class="shrink">
								<a class="btn sm <?= $kurz === $snapshot ? '' : 'ghost' ?>"
									href="<?= e( url( $basis . '?snapshot=' . rawurlencode( $kurz ) ) ) ?>">Öffnen</a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>

<?php if ( '' !== $snapshot ) : ?>
	<div class="card">
		<div class="card-head">
			<h2>Inhalt</h2>
			<div class="spacer"></div>
			<?php if ( ! empty( $view['ok'] ) ) : ?>
				<a class="btn sm" href="<?= e( url( $basis . '/download?snapshot=' . rawurlencode( $snapshot )
					. '&path=' . rawurlencode( (string) $view['path'] ) . '&archive=1' ) ) ?>">
					Diesen Ordner als .tar
				</a>
			<?php endif; ?>
		</div>
		<div class="card-body tight">
			<?php if ( empty( $view['ok'] ) ) : ?>
				<div class="notice bad" style="margin:12px 16px"><?= e( (string) ( $view['error'] ?? 'Unbekannter Fehler.' ) ) ?></div>
			<?php else : ?>
				<div class="small muted" style="padding:10px 16px">
					<?php foreach ( (array) $view['crumbs'] as $i => $krume ) : ?>
						<?= $i > 0 ? ' / ' : '' ?>
						<a href="<?= e( url( $basis . '?snapshot=' . rawurlencode( $snapshot )
							. '&path=' . rawurlencode( (string) $krume['path'] ) ) ) ?>"><?= e( (string) $krume['name'] ) ?></a>
					<?php endforeach; ?>
				</div>

				<?php if ( ! $view['entries'] ) : ?>
					<div class="empty">Dieser Ordner ist leer.</div>
				<?php else : ?>
					<div class="table-wrap">
						<table class="data">
							<thead><tr><th>Name</th><th class="shrink">Größe</th><th>Geändert</th><th class="shrink"></th></tr></thead>
							<tbody>
							<?php foreach ( (array) $view['entries'] as $eintrag ) : ?>
								<?php
								$istOrdner = 'dir' === ( $eintrag['type'] ?? '' );
								$relativ   = (string) ( $eintrag['relative'] ?? '' );
								?>
								<tr>
									<td>
										<?php if ( $istOrdner ) : ?>
											<a href="<?= e( url( $basis . '?snapshot=' . rawurlencode( $snapshot )
												. '&path=' . rawurlencode( $relativ ) ) ) ?>">
												<strong><?= e( (string) $eintrag['name'] ) ?></strong>
											</a>
										<?php else : ?>
											<?= e( (string) $eintrag['name'] ) ?>
										<?php endif; ?>
									</td>
									<td class="shrink small muted nowrap">
										<?= $istOrdner ? '—' : e( size_format_de( (int) $eintrag['size'] ) ) ?>
									</td>
									<td class="small muted nowrap"><?= e( nl_date( (string) ( $eintrag['mtime'] ?? '' ) ) ) ?></td>
									<td class="shrink">
										<a class="btn sm ghost" href="<?= e( url( $basis . '/download?snapshot=' . rawurlencode( $snapshot )
											. '&path=' . rawurlencode( $relativ ) . ( $istOrdner ? '&archive=1' : '' ) ) ) ?>">
											<?= $istOrdner ? '.tar' : 'Laden' ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<div class="card-foot small muted">
			Heruntergeladen wird nur — auf der Kundenseite ändert sich dabei nichts.
			Zum Zurückspielen die Datei von Hand einsetzen.
		</div>
	</div>
<?php endif; ?>
