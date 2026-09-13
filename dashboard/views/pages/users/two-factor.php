<?php
/**
 * @var array<string,mixed> $user
 * @var bool                $enabled
 * @var string              $secret
 * @var string              $secretGrouped
 * @var string              $otpauth
 * @var int                 $recoveryLeft
 * @var array<int,string>   $freshCodes
 */

use NorthLab\Core\View;

View::set( 'pageTitle', 'Zwei-Faktor-Anmeldung' );
?>

<?php if ( $freshCodes ) : ?>
	<div class="card" style="border-color:var(--brand-line)">
		<div class="card-head"><h2>Ersatzcodes</h2></div>
		<div class="card-body">
			<p class="small">
				<strong>Jetzt sichern.</strong> Diese Codes werden nur dieses eine Mal angezeigt.
				Jeder gilt genau einmal und ersetzt den Code aus der App, falls du keinen Zugriff mehr darauf hast.
			</p>
			<pre class="code-block"><?php foreach ( $freshCodes as $code ) : ?><?= e( $code ) ?>&#10;<?php endforeach; ?></pre>
			<p class="hint mb0">Speichere sie im Passwortmanager oder drucke sie aus.</p>
		</div>
	</div>
<?php endif; ?>

<div class="grid side">
	<div class="card">
		<div class="card-head">
			<h2>Zwei-Faktor-Anmeldung</h2>
			<div class="spacer"></div>
			<?php if ( $enabled ) : ?>
				<span class="badge ok"><span class="dot"></span>Aktiv</span>
			<?php else : ?>
				<span class="badge warn"><span class="dot"></span>Nicht eingerichtet</span>
			<?php endif; ?>
		</div>

		<div class="card-body">
			<?php if ( $enabled ) : ?>
				<p class="small">
					Bei jeder Anmeldung fragt das Panel nach dem Passwort und zusätzlich nach dem
					sechsstelligen Code aus deiner Authenticator-App.
				</p>
				<dl class="meta">
					<dt>Eingerichtet</dt><dd><?= e( nl_date( $user['totp_confirmed_at'] ) ) ?></dd>
					<dt>Ersatzcodes übrig</dt>
					<dd>
						<?php if ( $recoveryLeft > 2 ) : ?>
							<span class="badge ok"><?= e( (string) $recoveryLeft ) ?></span>
						<?php else : ?>
							<span class="badge warn"><?= e( (string) $recoveryLeft ) ?> — neue erzeugen</span>
						<?php endif; ?>
					</dd>
				</dl>

				<div class="btn-row mt">
					<form method="post" action="<?= e( url( '/profile/2fa' ) ) ?>"
						data-confirm="Neue Ersatzcodes erzeugen? Die bisherigen verlieren sofort ihre Gültigkeit.">
						<?= csrf_field() ?>
						<input type="hidden" name="action" value="recovery">
						<button class="btn">Neue Ersatzcodes</button>
					</form>
				</div>

				<hr style="border:0;border-top:1px solid var(--line);margin:22px 0">

				<h3>Abschalten</h3>
				<p class="small muted">Danach genügt wieder das Passwort allein.</p>
				<form method="post" action="<?= e( url( '/profile/2fa' ) ) ?>"
					data-confirm="Zwei-Faktor-Anmeldung wirklich abschalten?">
					<?= csrf_field() ?>
					<input type="hidden" name="action" value="disable">
					<div class="field" style="max-width:320px">
						<label for="pw">Zur Bestätigung dein Passwort</label>
						<input type="password" id="pw" name="password" required autocomplete="current-password">
					</div>
					<button class="btn danger">Abschalten</button>
				</form>

			<?php else : ?>
				<p class="small">
					Ein zweiter Faktor schützt das Panel auch dann, wenn dein Passwort einmal
					in falsche Hände gerät. Du brauchst eine Authenticator-App —
					Aegis, 2FAS, Google Authenticator, 1Password oder Bitwarden tun es alle.
				</p>

				<h3 class="mt">1. Code scannen</h3>
				<div id="qrcode" data-otpauth="<?= e( $otpauth ) ?>"
					style="background:#fff;padding:14px;border-radius:12px;display:inline-block;min-width:180px;min-height:180px"></div>
				<p class="hint">Kein Scanner zur Hand? Trage das Geheimnis von Hand ein:</p>
				<div class="copy-row" style="max-width:420px">
					<input type="text" id="totp-secret" readonly class="mono" value="<?= e( $secretGrouped ) ?>">
					<button class="btn sm" type="button" data-copy="#totp-secret">Kopieren</button>
				</div>
				<p class="hint">Typ: zeitbasiert (TOTP) · 6 Stellen · 30 Sekunden · SHA1</p>

				<h3 class="mt">2. Bestätigen</h3>
				<form method="post" action="<?= e( url( '/profile/2fa' ) ) ?>">
					<?= csrf_field() ?>
					<input type="hidden" name="action" value="confirm">
					<div class="field" style="max-width:240px">
						<label for="code">Code aus der App</label>
						<input type="text" id="code" name="code" required inputmode="numeric"
							autocomplete="one-time-code" placeholder="123456"
							style="font-size:20px;letter-spacing:.22em;text-align:center;font-family:ui-monospace,monospace">
					</div>
					<div class="btn-row">
						<button class="btn primary">Aktivieren</button>
						<button class="btn" name="action" value="restart" formnovalidate>Neues Geheimnis</button>
					</div>
				</form>
			<?php endif; ?>
		</div>
	</div>

	<div>
		<div class="card">
			<div class="card-head"><h3>Wenn du den Zugang verlierst</h3></div>
			<div class="card-body small">
				<p>Bei der Anmeldung kannst du statt des App-Codes einen <strong>Ersatzcode</strong> eingeben.
					Jeder gilt einmal.</p>
				<p class="mb0">Sind auch die weg, setzt ein Administrator unter <em>Benutzer</em> die
					Zwei-Faktor-Anmeldung für dein Konto zurück. Danach richtest du sie neu ein.</p>
			</div>
		</div>

		<div class="card">
			<div class="card-head"><h3>Uhrzeit</h3></div>
			<div class="card-body small muted mb0">
				<p class="mb0">Die Codes hängen an der Uhr. Läuft die Zeit deines Telefons mehr als eine
					halbe Minute daneben, passt kein Code. In den meisten Apps gibt es dafür einen
					Punkt „Zeitabgleich".</p>
			</div>
		</div>
	</div>
</div>

<script src="<?= e( url( '/assets/js/vendor/qrcode.js' ) ) ?>"></script>
<script src="<?= e( url( '/assets/js/vendor/qrcode-utf8.js' ) ) ?>"></script>
<script src="<?= e( url( '/assets/js/two-factor.js' ) ) ?>" defer></script>
