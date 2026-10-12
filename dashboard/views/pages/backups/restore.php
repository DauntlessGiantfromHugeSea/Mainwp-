<?php
/**
 * In einer Sicherung bloettern.
 *
 * @var array<string,mixed>      $site
 * @var array<int,array<string,mixed>> $snapshots
 * @var string                   $snapshot
 * @var array<string,mixed>      $view
 * @var bool                     $ready
 * @var array<string,string>     $scopes
 * @var array<string,mixed>|null $offen
 * @var string                   $code
 * @var bool                     $canWrite
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

<?php
// Der eben ausgegebene Code. Er steht genau hier einmal und sonst nirgends
// — in der Tabelle liegt nur sein Hash.
if ( '' !== $code ) :
	?>
	<div class="card">
		<div class="card-body">
			<p class="small muted" style="margin-top:0">
				Diesen Code dem Kunden durchgeben. Er gilt <strong>eine Stunde</strong> und genau
				<strong>einmal</strong>. Danach ist er auch hier nicht mehr zu sehen —
				wer ihn verliert, fordert einen neuen an.
			</p>
			<div class="copy-row" style="max-width:320px">
				<input type="text" id="restore-code" readonly class="mono" value="<?= e( $code ) ?>"
					data-select-all style="font-size:26px;letter-spacing:.3em;text-align:center">
				<button class="btn sm" type="button" data-copy="#restore-code">Kopieren</button>
			</div>
		</div>
	</div>
<?php endif; ?>

<?php if ( null !== $offen ) : ?>
	<div class="card">
		<div class="card-head">
			<h2>Offenes Vorhaben</h2>
			<div class="spacer"></div>
			<?php if ( 'laeuft' === $offen['status'] ) : ?>
				<span class="badge warn"><span class="dot"></span>läuft</span>
			<?php elseif ( 'vorgemerkt' === $offen['status'] ) : ?>
				<span class="badge warn">freigegeben</span>
			<?php else : ?>
				<span class="badge">wartet auf den Code</span>
			<?php endif; ?>
		</div>
		<div class="card-body">
			<p class="small">
				<strong><?= e( $scopes[ (string) $offen['scope'] ] ?? (string) $offen['scope'] ) ?></strong>
				aus dem Stand vom
				<?= e( $offen['snapshot_at'] ? nl_date( (string) $offen['snapshot_at'] ) : substr( (string) $offen['snapshot'], 0, 8 ) ) ?>.
			</p>
			<?php if ( 'laeuft' === $offen['status'] && '' !== (string) $offen['phase'] ) : ?>
				<p class="small muted"><?= e( (string) $offen['phase'] ) ?></p>
			<?php endif; ?>
			<?php if ( $canWrite && 'laeuft' !== $offen['status'] ) : ?>
				<form method="post" action="<?= e( url( $basis . '/cancel' ) ) ?>"
					data-confirm="Freigabe zurücknehmen? Der Code gilt dann nicht mehr.">
					<?= csrf_field() ?>
					<button class="btn ghost sm">Freigabe zurücknehmen</button>
				</form>
			<?php endif; ?>
		</div>
	</div>
<?php endif; ?>

<?php if ( '' !== $snapshot && $canWrite && null === $offen ) : ?>
	<div class="card">
		<div class="card-head"><h2>Diesen Stand zurückspielen</h2></div>
		<div class="card-body">
			<p class="small muted" style="margin-top:0">
				Überschreibt die laufende Website. Vorher wird der jetzige Stand gesichert —
				immer, auch wenn es eilt. Während des Zurückspielens ist die Seite im
				Wartungsmodus.
			</p>

			<div class="field" style="max-width:320px">
				<label for="scope">Was soll zurück?</label>
				<select id="scope" name="scope" form="restore-release">
					<?php foreach ( $scopes as $wert => $label ) : ?>
						<option value="<?= e( $wert ) ?>"<?= 'both' === $wert ? ' selected' : '' ?>><?= e( $label ) ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<form method="post" id="restore-release" action="<?= e( url( $basis . '/release' ) ) ?>">
				<?= csrf_field() ?>
				<input type="hidden" name="snapshot" value="<?= e( $snapshot ) ?>">
				<div class="btn-row">
					<button class="btn" data-busy="…">Code für den Kunden anfordern</button>
					<button class="btn danger" formaction="<?= e( url( $basis . '/start' ) ) ?>"
						data-confirm="Diesen Stand jetzt zurückspielen? Die laufende Website wird überschrieben."
						data-busy="…">Jetzt selbst zurückspielen</button>
				</div>
			</form>

			<p class="hint">
				Der Kunde kann mit dem Code nur genau das auslösen, was hier steht — nicht einen
				anderen Stand und nichts Weiteres.
			</p>
		</div>
	</div>
<?php endif; ?>

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
