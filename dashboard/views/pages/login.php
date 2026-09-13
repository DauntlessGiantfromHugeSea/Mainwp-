<?php
/** @var string $agencyName */
/** @var string $email */

use NorthLab\Core\View;

View::set( 'pageTitle', 'Anmelden' );
?>
<h1><?= e( $agencyName ) ?></h1>
<p class="sub">Control Panel</p>

<form method="post" action="<?= e( url( '/login' ) ) ?>" data-no-lock>
	<?= csrf_field() ?>

	<div class="field">
		<label for="email">E-Mail-Adresse</label>
		<input type="email" id="email" name="email" value="<?= e( $email ) ?>" required autofocus autocomplete="username">
	</div>

	<div class="field">
		<label for="password">Passwort</label>
		<input type="password" id="password" name="password" required autocomplete="current-password">
	</div>

	<button class="btn primary" type="submit" style="width:100%">Anmelden</button>
</form>
