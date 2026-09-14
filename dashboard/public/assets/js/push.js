/*
 * Push-Meldungen an- und abmelden.
 *
 * Der Browser vergibt das Abonnement, nicht das Panel: Erlaubnis holen, beim
 * Push-Dienst anmelden, die Adresse und die beiden Schlüssel ans Panel geben.
 */
(function () {
	'use strict';

	var box = document.getElementById('push-box');

	if (!box) {
		return;
	}

	var statusEl = box.querySelector('[data-push-status]');
	var enableEl = box.querySelector('[data-push-enable]');
	var disableEl = box.querySelector('[data-push-disable]');
	var testForm = document.getElementById('push-test');

	function say(text, kind) {
		if (!statusEl) {
			return;
		}
		statusEl.textContent = text;
		statusEl.className = 'notice' + (kind ? ' ' + kind : '');
	}

	function show(enabled) {
		if (enableEl) {
			enableEl.hidden = enabled;
		}
		if (disableEl) {
			disableEl.hidden = !enabled;
		}
		if (testForm) {
			testForm.hidden = !enabled;
		}
	}

	/** Base64url in das Byte-Array, das pushManager erwartet. */
	function toBytes(base64url) {
		var padded = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4))
			.replace(/-/g, '+')
			.replace(/_/g, '/');
		var raw = window.atob(padded);
		var out = new Uint8Array(raw.length);

		for (var i = 0; i < raw.length; i++) {
			out[i] = raw.charCodeAt(i);
		}

		return out;
	}

	function keyOf(subscription, name) {
		var key = subscription.getKey(name);

		if (!key) {
			return '';
		}

		var bytes = new Uint8Array(key);
		var binary = '';

		for (var i = 0; i < bytes.length; i++) {
			binary += String.fromCharCode(bytes[i]);
		}

		return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	function token() {
		var field = document.querySelector('input[name="_token"]');
		return field ? field.value : '';
	}

	function post(url, body) {
		// Formularkodiert, nicht JSON: das Panel liest den Rumpf aus $_POST,
		// und daran haengt auch die CSRF-Pruefung.
		var form = new URLSearchParams();
		form.set('_token', token());

		Object.keys(body).forEach(function (key) {
			form.set(key, body[key]);
		});

		return window.fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
			credentials: 'same-origin',
			body: form.toString(),
		});
	}

	var supported =
		'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

	if (!supported) {
		say(
			'Dieser Browser kann keine Push-Meldungen anzeigen. Auf dem iPhone geht es erst, ' +
				'wenn das Panel über „Zum Home-Bildschirm“ installiert wurde.',
			'warn'
		);
		show(false);
		if (enableEl) {
			enableEl.hidden = true;
		}
		return;
	}

	navigator.serviceWorker.ready
		.then(function (registration) {
			return registration.pushManager.getSubscription().then(function (existing) {
				show(!!existing);

				if (existing) {
					say('Dieses Gerät bekommt Meldungen.', 'ok');
				} else if (Notification.permission === 'denied') {
					say(
						'Meldungen sind für diese Adresse im Browser gesperrt. Das lässt sich nur ' +
							'dort wieder freigeben — in den Website-Einstellungen neben der Adresszeile.',
						'bad'
					);
					if (enableEl) {
						enableEl.disabled = true;
					}
				} else {
					say('Dieses Gerät bekommt noch keine Meldungen.');
				}

				return registration;
			});
		})
		.then(function (registration) {
			if (enableEl) {
				enableEl.addEventListener('click', function () {
					enable(registration);
				});
			}
			if (disableEl) {
				disableEl.addEventListener('click', function () {
					disable(registration);
				});
			}
		})
		.catch(function () {
			say('Der Service Worker ist nicht bereit. Lade die Seite einmal neu.', 'warn');
		});

	function enable(registration) {
		enableEl.disabled = true;
		say('Frage nach Erlaubnis…');

		Notification.requestPermission()
			.then(function (permission) {
				if ('granted' !== permission) {
					say('Ohne Erlaubnis des Browsers gibt es keine Meldungen.', 'warn');
					enableEl.disabled = false;
					return null;
				}

				return window
					.fetch('/push/key', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
					.then(function (res) {
						return res.json();
					});
			})
			.then(function (info) {
				if (!info) {
					return null;
				}
				if (!info.ok || !info.key) {
					say('Im Panel fehlt noch der Schlüssel für Push. Er lässt sich unten erzeugen.', 'warn');
					enableEl.disabled = false;
					return null;
				}

				return registration.pushManager.subscribe({
					userVisibleOnly: true,
					applicationServerKey: toBytes(info.key),
				});
			})
			.then(function (subscription) {
				if (!subscription) {
					return null;
				}

				return post('/push/subscribe', {
					endpoint: subscription.endpoint,
					p256dh: keyOf(subscription, 'p256dh'),
					auth: keyOf(subscription, 'auth'),
					label: navigator.userAgent.slice(0, 180),
				});
			})
			.then(function (res) {
				if (!res) {
					return;
				}
				if (!res.ok) {
					say('Das Panel hat die Anmeldung abgelehnt.', 'bad');
					enableEl.disabled = false;
					return;
				}

				show(true);
				say('Dieses Gerät bekommt jetzt Meldungen.', 'ok');
			})
			.catch(function (error) {
				say('Anmeldung fehlgeschlagen: ' + error.message, 'bad');
				enableEl.disabled = false;
			});
	}

	function disable(registration) {
		disableEl.disabled = true;

		registration.pushManager
			.getSubscription()
			.then(function (subscription) {
				if (!subscription) {
					return null;
				}

				var endpoint = subscription.endpoint;

				// Erst beim Browser abmelden, dann im Panel austragen — andersherum
				// bliebe bei einem Fehler ein Geraet zurueck, das nichts mehr annimmt.
				return subscription.unsubscribe().then(function () {
					return post('/push/unsubscribe', { endpoint: endpoint });
				});
			})
			.then(function () {
				show(false);
				say('Dieses Gerät bekommt keine Meldungen mehr.');
				disableEl.disabled = false;
			})
			.catch(function (error) {
				say('Abmelden fehlgeschlagen: ' + error.message, 'bad');
				disableEl.disabled = false;
			});
	}
})();
