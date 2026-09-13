<?php
/**
 * @var array<int,array<string,mixed>> $users
 * @var array<int,array<int,int>>      $assignment
 * @var array<int,array<string,mixed>> $allSites
 * @var array<string,string>           $roles
 * @var array<int,array<string,mixed>> $sessions
 * @var array<string,mixed>            $currentUser
 */

use NorthLab\Core\View;

View::set( 'pageTitle', 'Benutzer' );
?>

<div class="grid side">
	<div>
		<div class="card">
			<div class="card-head"><h2><?= e( (string) count( $users ) ) ?> Konto/Konten</h2></div>
			<div class="card-body tight">
				<?php foreach ( $users as $user ) : ?>
					<div style="padding:14px 18px;border-bottom:1px solid var(--line)">
						<form method="post" action="<?= e( url( '/users/' . $user['id'] ) ) ?>">
							<?= csrf_field() ?>
							<div class="form-grid">
								<div class="field">
									<label>Name</label>
									<input type="text" name="name" value="<?= e( (string) $user['name'] ) ?>">
								</div>
								<div class="field">
									<label>E-Mail</label>
									<input type="email" name="email" value="<?= e( (string) $user['email'] ) ?>" required>
								</div>
								<div class="field">
									<label>Rolle</label>
									<select name="role">
										<?php foreach ( $roles as $value => $label ) : ?>
											<option value="<?= e( $value ) ?>" <?= (string) $user['role'] === $value ? 'selected' : '' ?>><?= e( $label ) ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="field">
									<label>Neues Passwort</label>
									<input type="password" name="password" placeholder="unverändert lassen" autocomplete="new-password">
								</div>
							</div>

							<?php $assigned = $assignment[ (int) $user['id'] ] ?? array(); ?>
							<?php $restricted = 'assigned' === ( $user['site_access'] ?? 'all' ); ?>

							<?php if ( 'admin' === $user['role'] ) : ?>
								<p class="hint">Administratoren sehen immer alle Seiten.</p>
								<input type="hidden" name="site_access" value="all">
							<?php else : ?>
								<div class="field">
									<label>Sichtbare Seiten</label>
									<label class="small" style="display:flex;gap:6px;align-items:center;margin-bottom:4px">
										<input type="radio" name="site_access" value="all" <?= $restricted ? '' : 'checked' ?>>
										alle Seiten
									</label>
									<label class="small" style="display:flex;gap:6px;align-items:center">
										<input type="radio" name="site_access" value="assigned" <?= $restricted ? 'checked' : '' ?>>
										nur zugeordnete
										<?php if ( $restricted ) : ?>
											<span class="badge info"><?= e( (string) count( $assigned ) ) ?></span>
										<?php endif; ?>
									</label>

									<?php if ( $allSites ) : ?>
										<div style="max-height:190px;overflow:auto;border:1px solid var(--line);border-radius:var(--radius-sm);padding:10px;margin-top:8px">
											<div class="grid cols-2" style="gap:2px 14px">
												<?php foreach ( $allSites as $site ) : ?>
													<label class="small" style="display:flex;gap:6px;align-items:flex-start">
														<input type="checkbox" name="site_ids[]" value="<?= e( (string) $site['id'] ) ?>"
															<?= in_array( (int) $site['id'], $assigned, true ) ? 'checked' : '' ?>>
														<span><?= e( (string) $site['name'] ) ?><br>
															<span class="muted" style="font-size:11px"><?= e( nl_host( (string) $site['url'] ) ) ?></span></span>
													</label>
												<?php endforeach; ?>
											</div>
										</div>
										<div class="hint">Wirkt erst mit der Auswahl „nur zugeordnete“.</div>
									<?php else : ?>
										<div class="hint">Noch keine Seiten im Panel.</div>
									<?php endif; ?>
								</div>
							<?php endif; ?>

							<div class="btn-row">
								<label class="small" style="display:flex;gap:6px;align-items:center">
									<input type="checkbox" name="is_active" value="1" <?= ! empty( $user['is_active'] ) ? 'checked' : '' ?>> aktiv
								</label>
								<button class="btn primary sm">Speichern</button>
								<span style="flex:1"></span>
								<?php if ( ! empty( $user['totp_enabled'] ) ) : ?>
									<span class="badge ok"><span class="dot"></span>2FA aktiv</span>
								<?php else : ?>
									<span class="badge warn">ohne 2FA</span>
								<?php endif; ?>
								<span class="muted small">
									Anmeldung <?= e( nl_ago( $user['last_login_at'] ) ) ?>
									<?php if ( ! empty( $user['locked_until'] ) && strtotime( (string) $user['locked_until'] . ' UTC' ) > time() ) : ?>
										· <span class="badge bad">gesperrt</span>
									<?php endif; ?>
								</span>
								<?php if ( (int) $user['id'] !== (int) $currentUser['id'] ) : ?>
									<button class="btn sm danger" type="submit"
										formaction="<?= e( url( '/users/' . $user['id'] . '/delete' ) ) ?>"
										formnovalidate
										onclick="return confirm('Konto <?= e( (string) $user['email'] ) ?> wirklich löschen?')">Löschen</button>
								<?php endif; ?>
							</div>

							<?php if ( ! empty( $user['totp_enabled'] ) ) : ?>
								<div class="btn-row mt">
									<button class="btn sm" type="submit"
										formaction="<?= e( url( '/users/' . $user['id'] . '/reset-2fa' ) ) ?>"
										formnovalidate
										onclick="return confirm('Zwei-Faktor-Anmeldung für <?= e( (string) $user['email'] ) ?> zurücksetzen? Das Konto muss sie danach neu einrichten.')">
										2FA zurücksetzen
									</button>
								</div>
							<?php endif; ?>
						</form>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="card">
			<div class="card-head"><h2>Aktive Sitzungen</h2></div>
			<div class="card-body tight">
				<?php if ( ! $sessions ) : ?>
					<div class="empty">Keine aktiven Sitzungen.</div>
				<?php else : ?>
					<table class="data">
						<thead><tr><th>Konto</th><th>IP</th><th>Angemeldet</th><th>Läuft ab</th><th>Browser</th></tr></thead>
						<tbody>
						<?php foreach ( $sessions as $session ) : ?>
							<tr>
								<td class="small"><?= e( (string) $session['email'] ) ?></td>
								<td class="mono small"><?= e( (string) $session['ip'] ) ?></td>
								<td class="small muted"><?= e( nl_ago( (string) $session['created_at'] ) ) ?></td>
								<td class="small muted"><?= e( nl_date( (string) $session['expires_at'], 'd.m. H:i' ) ) ?></td>
								<td class="small muted truncate"><?= e( (string) $session['user_agent'] ) ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<div class="card-foot small muted">Ein Passwortwechsel beendet alle Sitzungen des betroffenen Kontos.</div>
		</div>
	</div>

	<div>
		<div class="card">
			<div class="card-head"><h3>Benutzer anlegen</h3></div>
			<div class="card-body">
				<form method="post" action="<?= e( url( '/users' ) ) ?>">
					<?= csrf_field() ?>
					<div class="field">
						<label for="new_name">Name</label>
						<input type="text" id="new_name" name="name">
					</div>
					<div class="field">
						<label for="new_email">E-Mail</label>
						<input type="email" id="new_email" name="email" required>
					</div>
					<div class="field">
						<label for="new_role">Rolle</label>
						<select id="new_role" name="role">
							<?php foreach ( $roles as $value => $label ) : ?>
								<option value="<?= e( $value ) ?>" <?= 'member' === $value ? 'selected' : '' ?>><?= e( $label ) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="field">
						<label for="new_password">Passwort</label>
						<input type="password" id="new_password" name="password" required autocomplete="new-password">
						<div class="hint">Mindestens 10 Zeichen, Buchstaben und Ziffern.</div>
					</div>
					<button class="btn primary">Anlegen</button>
				</form>
			</div>
		</div>

		<div class="card">
			<div class="card-head"><h3>Rollen</h3></div>
			<div class="card-body small">
				<p><strong>Administrator</strong> — alles, inklusive Einstellungen und Benutzerverwaltung.</p>
				<p><strong>Mitarbeiter</strong> — Seiten verbinden, Updates einspielen, Wartung, Berichte, Webhooks.</p>
				<p class="mb0"><strong>Nur Lesen</strong> — sieht alles, kann nichts auslösen.</p>
			</div>
		</div>

		<div class="card">
			<div class="card-head"><h3>Eigenes Profil</h3></div>
			<div class="card-body">
				<form method="post" action="<?= e( url( '/profile' ) ) ?>">
					<?= csrf_field() ?>
					<div class="field">
						<label for="p_name">Name</label>
						<input type="text" id="p_name" name="name" value="<?= e( (string) $currentUser['name'] ) ?>">
					</div>
					<div class="field">
						<label for="p_email">E-Mail</label>
						<input type="email" id="p_email" name="email" value="<?= e( (string) $currentUser['email'] ) ?>" required>
					</div>
					<div class="field">
						<label for="p_current">Aktuelles Passwort</label>
						<input type="password" id="p_current" name="current_password" autocomplete="current-password">
					</div>
					<div class="field">
						<label for="p_new">Neues Passwort</label>
						<input type="password" id="p_new" name="password" placeholder="leer lassen = unverändert" autocomplete="new-password">
					</div>
					<button class="btn">Profil speichern</button>
				</form>
			</div>
			<div class="card-foot">
				<a class="btn sm" href="<?= e( url( '/profile/2fa' ) ) ?>">Zwei-Faktor-Anmeldung verwalten</a>
			</div>
		</div>
	</div>
</div>
