<?php
/** @var string $agencyName */
/** @var string $agencyLogo */
/** @var string $email */

use NorthLab\Core\View;

View::set( 'pageTitle', 'Bestätigung' );
?>
<?php if ( '' !== $agencyLogo ) : ?>
	<img class="auth-logo" src="<?= e( $agencyLogo ) ?>" alt="<?= e( $agencyName ) ?>">
<?php endif; ?>

<h1>Bestätigung</h1>
<p class="sub">Angemeldet als <?= e( $email ) ?></p>

<form method="post" action="<?= e( url( '/login/2fa' ) ) ?>" data-no-lock>
	<?= csrf_field() ?>

	<div class="field">
		<label for="code">Code aus der Authenticator-App</label>
		<input type="text" id="code" name="code" required autofocus
			inputmode="numeric" autocomplete="one-time-code"
			pattern="[0-9A-Za-z\- ]{6,20}" placeholder="123456"
			style="font-size:22px;letter-spacing:.28em;text-align:center;font-family:ui-monospace,monospace">
	</div>

	<button class="btn primary" type="submit" style="width:100%">Weiter</button>
</form>

<p class="auth-foot">
	Kein Zugriff auf die App? Gib stattdessen einen deiner Ersatzcodes ein — jeder gilt genau einmal.
</p>

<form method="post" action="<?= e( url( '/logout' ) ) ?>" style="margin-top:10px" data-no-lock>
	<?= csrf_field() ?>
	<button class="btn sm" type="submit" style="width:100%">Abbrechen</button>
</form>
