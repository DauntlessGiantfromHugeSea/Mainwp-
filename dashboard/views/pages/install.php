<?php
/**
 * @var array<int,array{label:string,ok:bool,required:bool,hint:string}> $checks
 * @var bool                $blocked
 * @var array<int,string>   $errors
 * @var array<string,string> $input
 * @var array<int,string>   $timezones
 */

use NorthLab\Core\View;

View::set( 'pageTitle', 'Installation' );
View::set( 'wide', true );
View::set( 'agencyName', 'NorthLab' );
?>
<h1>NorthLab einrichten</h1>
<p class="sub">Einmalige Installation des Control Panels</p>

<?php foreach ( $errors as $error ) : ?>
	<div class="flash error"><?= e( $error ) ?></div>
<?php endforeach; ?>

<h2>Systemvoraussetzungen</h2>
<div class="table-wrap" style="margin-bottom:24px">
	<table class="data">
		<tbody>
		<?php foreach ( $checks as $check ) : ?>
			<tr>
				<td class="shrink">
					<?php if ( $check['ok'] ) : ?>
						<span class="badge ok"><span class="dot"></span>OK</span>
					<?php elseif ( $check['required'] ) : ?>
						<span class="badge bad"><span class="dot"></span>Fehlt</span>
					<?php else : ?>
						<span class="badge warn"><span class="dot"></span>Optional</span>
					<?php endif; ?>
				</td>
				<td><strong><?= e( $check['label'] ) ?></strong></td>
				<td class="muted small"><?= e( $check['hint'] ) ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>

<?php if ( $blocked ) : ?>
	<div class="notice bad">
		Es fehlen zwingend erforderliche Voraussetzungen. Bitte zuerst beheben und die Seite neu laden.
	</div>
<?php else : ?>

<form method="post" action="<?= e( url( '/install' ) ) ?>">
	<?= csrf_field() ?>

	<h2>Datenbank</h2>
	<div class="form-grid">
		<div class="field">
			<label for="db_host">Host</label>
			<input type="text" id="db_host" name="db_host" value="<?= e( $input['db_host'] ) ?>" required>
		</div>
		<div class="field">
			<label for="db_port">Port</label>
			<input type="number" id="db_port" name="db_port" value="<?= e( $input['db_port'] ) ?>" required>
		</div>
		<div class="field">
			<label for="db_name">Datenbankname</label>
			<input type="text" id="db_name" name="db_name" value="<?= e( $input['db_name'] ) ?>" required>
		</div>
		<div class="field">
			<label for="db_prefix">Tabellenpräfix</label>
			<input type="text" id="db_prefix" name="db_prefix" value="<?= e( $input['db_prefix'] ) ?>" required>
		</div>
		<div class="field">
			<label for="db_user">Benutzer</label>
			<input type="text" id="db_user" name="db_user" value="<?= e( $input['db_user'] ) ?>" required>
		</div>
		<div class="field">
			<label for="db_pass">Passwort</label>
			<input type="password" id="db_pass" name="db_pass" value="<?= e( $input['db_pass'] ) ?>" autocomplete="off">
		</div>
	</div>

	<h2>Panel</h2>
	<div class="form-grid">
		<div class="field">
			<label for="app_url">Panel-URL</label>
			<input type="url" id="app_url" name="app_url" value="<?= e( $input['app_url'] ) ?>" required>
			<div class="hint">Ohne abschließenden Slash. Wird für Webhook- und Monitoring-URLs verwendet.</div>
		</div>
		<div class="field">
			<label for="agency_name">Agenturname</label>
			<input type="text" id="agency_name" name="agency_name" value="<?= e( $input['agency_name'] ) ?>" required>
		</div>
		<div class="field full">
			<label for="timezone">Zeitzone</label>
			<select id="timezone" name="timezone">
				<?php foreach ( $timezones as $tz ) : ?>
					<option value="<?= e( $tz ) ?>" <?= $tz === $input['timezone'] ? 'selected' : '' ?>><?= e( $tz ) ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>

	<h2>Administratorkonto</h2>
	<div class="form-grid">
		<div class="field">
			<label for="admin_name">Name</label>
			<input type="text" id="admin_name" name="admin_name" value="<?= e( $input['admin_name'] ) ?>">
		</div>
		<div class="field">
			<label for="admin_email">E-Mail-Adresse</label>
			<input type="email" id="admin_email" name="admin_email" value="<?= e( $input['admin_email'] ) ?>" required>
		</div>
		<div class="field">
			<label for="admin_pass">Passwort</label>
			<input type="password" id="admin_pass" name="admin_pass" required autocomplete="new-password">
			<div class="hint">Mindestens 10 Zeichen, Buchstaben und Ziffern.</div>
		</div>
		<div class="field">
			<label for="admin_pass2">Passwort wiederholen</label>
			<input type="password" id="admin_pass2" name="admin_pass2" required autocomplete="new-password">
		</div>
	</div>

	<button class="btn primary" type="submit" data-busy="Wird installiert…" style="width:100%">Installation starten</button>
</form>

<?php endif; ?>
