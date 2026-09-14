<?php
/**
 * @var array<string,mixed>            $site
 * @var array<int,array<string,mixed>> $users
 * @var string                         $loadError
 * @var array<string,string>           $roles
 * @var array<int,string>              $durations
 * @var string|null                    $secret
 * @var array<string,mixed>            $old
 */

use NorthLab\Core\Auth;
use NorthLab\Core\View;
use NorthLab\Repository\SiteRepository;

View::set( 'pageTitle', 'Benutzer — ' . (string) $site['name'] );

$canWrite = Auth::canWrite();
$siteUrl  = url( '/sites/' . $site['id'] );
$formUrl  = $siteUrl . '/users';

View::set( 'headerActions', '<a class="btn" href="' . e( $siteUrl ) . '">Zurück zur Seite</a>' );

$credentials = null;
if ( is_string( $secret ) && '' !== $secret ) {
	$decoded     = json_decode( $secret, true );
	$credentials = is_array( $decoded ) ? $decoded : null;
}
?>

<?php if ( ! SiteRepository::isManaged( $site ) ) : ?>
	<div class="notice warn">
		Diese Seite wird nur überwacht. Ohne Child-Plugin kann das Panel dort keine Benutzer verwalten.
	</div>
	<?php return; ?>
<?php endif; ?>

<?php if ( $credentials ) : ?>
	<div class="card" style="border-color:var(--brand-line)">
		<div class="card-head"><h2>Zugangsdaten — nur jetzt sichtbar</h2></div>
		<div class="card-body">
			<p class="small muted">
				Das Passwort steht nirgends sonst. Es wird weder gespeichert noch protokolliert und ist
				nach dem nächsten Seitenaufruf weg. Jetzt kopieren und sicher weitergeben.
			</p>
			<div class="form-grid">
				<div class="field">
					<label for="cred-login">Anmeldename</label>
					<div class="copy-row">
						<input type="text" id="cred-login" class="mono" readonly value="<?= e( (string) ( $credentials['login'] ?? '' ) ) ?>">
						<button class="btn sm" type="button" data-copy="#cred-login">Kopieren</button>
					</div>
				</div>
				<div class="field">
					<label for="cred-pass">Passwort</label>
					<div class="copy-row">
						<input type="text" id="cred-pass" class="mono" readonly value="<?= e( (string) ( $credentials['password'] ?? '' ) ) ?>">
						<button class="btn sm" type="button" data-copy="#cred-pass">Kopieren</button>
					</div>
				</div>
			</div>
			<?php if ( ! empty( $credentials['expires_at'] ) ) : ?>
				<p class="small mt">
					Dieses Konto wird am
					<strong><?= e( nl_date( gmdate( 'Y-m-d H:i:s', (int) $credentials['expires_at'] ) ) ) ?></strong>
					automatisch gelöscht.
				</p>
			<?php endif; ?>
		</div>
	</div>
<?php endif; ?>

<?php if ( '' !== $loadError ) : ?>
	<div class="notice bad"><strong>Benutzer konnten nicht geladen werden:</strong> <?= e( $loadError ) ?></div>
<?php endif; ?>

<div class="grid side">
	<div class="card">
		<div class="card-head">
			<h2><?= e( (string) count( $users ) ) ?> Benutzer auf dieser Seite</h2>
			<div class="spacer"></div>
			<?php if ( $canWrite ) : ?>
				<form method="post" action="<?= e( $siteUrl . '/login' ) ?>">
					<?= csrf_field() ?>
					<button class="btn sm primary" data-busy="Öffne…">Als Administrator anmelden</button>
				</form>
			<?php endif; ?>
		</div>
		<div class="card-body tight">
			<?php if ( ! $users ) : ?>
				<div class="empty">Keine Benutzer geladen.</div>
			<?php else : ?>
				<div class="table-wrap">
					<table class="data">
						<thead>
						<tr>
							<th>Benutzer</th>
							<th>Rolle</th>
							<th>Befristung</th>
							<th>Zuletzt aktiv</th>
							<th></th>
						</tr>
						</thead>
						<tbody>
						<?php foreach ( $users as $user ) : ?>
							<?php
							$userId  = (int) ( $user['id'] ?? 0 );
							$expires = (int) ( $user['expires_at'] ?? 0 );
							?>
							<tr>
								<td>
									<strong><?= e( (string) ( $user['login'] ?? '' ) ) ?></strong>
									<div class="muted small"><?= e( (string) ( $user['email'] ?? '' ) ) ?></div>
								</td>
								<td class="small">
									<?php foreach ( (array) ( $user['roles'] ?? array() ) as $role ) : ?>
										<span class="badge"><?= e( $roles[ $role ] ?? $role ) ?></span>
									<?php endforeach; ?>
								</td>
								<td class="small nowrap">
									<?php if ( $expires > 0 ) : ?>
										<span class="badge warn">bis <?= e( nl_date( gmdate( 'Y-m-d H:i:s', $expires ), 'd.m. H:i' ) ) ?></span>
									<?php elseif ( ! empty( $user['from_panel'] ) ) : ?>
										<span class="muted">unbefristet</span>
									<?php else : ?>
										<span class="muted">—</span>
									<?php endif; ?>
								</td>
								<td class="muted small nowrap"><?= e( nl_ago( $user['last_login'] ?? null ) ) ?></td>
								<td class="shrink nowrap">
									<?php if ( $canWrite ) : ?>
										<form method="post" action="<?= e( $siteUrl . '/login' ) ?>" style="display:inline">
											<?= csrf_field() ?>
											<input type="hidden" name="user_id" value="<?= e( (string) $userId ) ?>">
											<button class="btn sm" data-busy="…">Anmelden</button>
										</form>
										<form method="post" action="<?= e( $formUrl ) ?>" style="display:inline"
											data-confirm="Neues Passwort für &quot;<?= e( (string) ( $user['login'] ?? '' ) ) ?>&quot; setzen? Alle offenen Sitzungen dieses Kontos werden beendet.">
											<?= csrf_field() ?>
											<input type="hidden" name="user_action" value="set-password">
											<input type="hidden" name="user_id" value="<?= e( (string) $userId ) ?>">
											<button class="btn sm" data-busy="…">Passwort</button>
										</form>
										<form method="post" action="<?= e( $formUrl ) ?>" style="display:inline">
											<?= csrf_field() ?>
											<input type="hidden" name="user_action" value="reset-mail">
											<input type="hidden" name="user_id" value="<?= e( (string) $userId ) ?>">
											<button class="btn sm" data-busy="…">Mail</button>
										</form>
										<form method="post" action="<?= e( $formUrl ) ?>" style="display:inline"
											data-confirm="Benutzer &quot;<?= e( (string) ( $user['login'] ?? '' ) ) ?>&quot; löschen? Vorhandene Inhalte gehen an den ältesten Administrator über.">
											<?= csrf_field() ?>
											<input type="hidden" name="user_action" value="delete">
											<input type="hidden" name="user_id" value="<?= e( (string) $userId ) ?>">
											<button class="btn sm danger" data-busy="…">Löschen</button>
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

	<div>
		<div class="card">
			<div class="card-head"><h3>Benutzer anlegen</h3></div>
			<div class="card-body">
				<form method="post" action="<?= e( $formUrl ) ?>">
					<?= csrf_field() ?>
					<input type="hidden" name="user_action" value="create">

					<div class="field">
						<label for="login">Anmeldename</label>
						<input type="text" id="login" name="login" value="<?= e( nl_old( $old, 'login' ) ) ?>" required
							placeholder="northlab-support" autocomplete="off">
					</div>
					<div class="field">
						<label for="email">E-Mail</label>
						<input type="email" id="email" name="email" value="<?= e( nl_old( $old, 'email' ) ) ?>" required
							placeholder="support@north-lab.de">
					</div>
					<div class="field">
						<label for="role">Rolle</label>
						<select id="role" name="role">
							<?php foreach ( $roles as $value => $label ) : ?>
								<option value="<?= e( $value ) ?>" <?= 'administrator' === $value ? 'selected' : '' ?>>
									<?= e( $label ) ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="field">
						<label for="expires_minutes">Befristung</label>
						<select id="expires_minutes" name="expires_minutes">
							<option value="0">Unbefristet</option>
							<?php foreach ( $durations as $minutes => $label ) : ?>
								<option value="<?= e( (string) $minutes ) ?>"><?= e( $label ) ?></option>
							<?php endforeach; ?>
						</select>
						<div class="hint">
							Ein befristetes Konto wird auf der Kundenseite automatisch gelöscht, sobald die Zeit
							abgelaufen ist. Inhalte gehen an den ältesten Administrator über.
						</div>
					</div>

					<button class="btn primary" <?= $canWrite ? '' : 'disabled' ?> data-busy="Lege an…">Anlegen</button>
				</form>
			</div>
		</div>

		<div class="card">
			<div class="card-head"><h3>Zur Ein-Klick-Anmeldung</h3></div>
			<div class="card-body small">
				<p>
					Das Panel lässt sich von der Kundenseite eine Adresse geben, die genau einmal und nur
					90 Sekunden lang gültig ist. Der Browser folgt ihr sofort und landet im WP-Admin.
				</p>
				<p class="muted">
					Die Adresse steht kurz in der URL und damit im Zugriffsprotokoll des Kundenservers.
					Weil sie beim ersten Aufruf verbraucht wird und danach abläuft, nützt sie dort niemandem
					mehr. Wer das gar nicht will, schaltet die Funktion auf der Kundenseite unter
					<em>Einstellungen → NorthLab</em> ab.
				</p>
			</div>
		</div>
	</div>
</div>
