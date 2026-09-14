/*
 * Vorschau und Erzeugung des Panel-Symbols.
 *
 * Gezeichnet wird im Browser, nicht auf dem Server: die Bildbibliothek GD
 * gehört nicht zu den Voraussetzungen dieser Installation und kann ohnehin
 * keine SVG-Logos lesen. Ein Browser kann beides.
 */
(function () {
	'use strict';

	var box = document.getElementById('icon-box');

	if (!box) {
		return;
	}

	var canvas = document.getElementById('icon-canvas');
	var statusEl = box.querySelector('[data-icon-status]');
	var saveBtn = box.querySelector('[data-icon-save]');
	var bgInput = document.getElementById('icon_bg');
	var padInput = document.getElementById('icon_padding');

	var SOURCE = box.getAttribute('data-icon-source');
	var SIZES = { 'icon-512': 512, 'icon-192': 192, 'apple-touch-icon': 180, 'favicon-32': 32 };

	if (!canvas || !SOURCE) {
		return;
	}

	var logo = null;

	function say(text, kind) {
		if (statusEl) {
			statusEl.textContent = text;
			statusEl.className = 'notice' + (kind ? ' ' + kind : '');
		}
	}

	function colour() {
		var value = (bgInput && bgInput.value ? bgInput.value : '#000000').trim();
		return /^#[0-9a-fA-F]{6}$/.test(value) ? value : '#000000';
	}

	function padding() {
		var value = parseInt(padInput && padInput.value ? padInput.value : '18', 10);
		return isNaN(value) ? 18 : Math.max(0, Math.min(40, value));
	}

	/** Abgerundetes Quadrat — dieselbe Rundung wie in der SVG-Fassung. */
	function roundedRect(ctx, size) {
		var r = size * (112 / 512);

		ctx.beginPath();
		ctx.moveTo(r, 0);
		ctx.lineTo(size - r, 0);
		ctx.quadraticCurveTo(size, 0, size, r);
		ctx.lineTo(size, size - r);
		ctx.quadraticCurveTo(size, size, size - r, size);
		ctx.lineTo(r, size);
		ctx.quadraticCurveTo(0, size, 0, size - r);
		ctx.lineTo(0, r);
		ctx.quadraticCurveTo(0, 0, r, 0);
		ctx.closePath();
	}

	/**
	 * Zeichnet Grund und Logo in der gewünschten Kantenlänge.
	 *
	 * Das Logo behält sein Seitenverhältnis und wird mittig gesetzt — sonst
	 * wäre ein breites Wortlogo im Quadrat verzerrt.
	 */
	function draw(target, size, rounded) {
		var ctx = target.getContext('2d');

		target.width = size;
		target.height = size;

		ctx.clearRect(0, 0, size, size);
		ctx.save();

		if (rounded) {
			roundedRect(ctx, size);
			ctx.clip();
		}

		ctx.fillStyle = colour();
		ctx.fillRect(0, 0, size, size);

		if (logo && logo.width && logo.height) {
			var room = size * (1 - (2 * padding()) / 100);
			var scale = Math.min(room / logo.width, room / logo.height);
			var w = logo.width * scale;
			var h = logo.height * scale;

			ctx.drawImage(logo, (size - w) / 2, (size - h) / 2, w, h);
		}

		ctx.restore();
	}

	function preview() {
		draw(canvas, 256, true);
	}

	function load() {
		say('Logo wird geladen…');

		var image = new Image();

		// Gleiche Herkunft, weil das Panel die Datei selbst ausliefert — sonst
		// duerfte die gezeichnete Flaeche nicht ausgelesen werden.
		image.onload = function () {
			logo = image;
			preview();
			say('So sieht das Symbol aus. Zum Übernehmen unten speichern.', 'ok');
			if (saveBtn) {
				saveBtn.disabled = false;
			}
		};

		image.onerror = function () {
			say('Das hinterlegte Logo lässt sich nicht darstellen.', 'bad');
		};

		image.src = SOURCE + (SOURCE.indexOf('?') === -1 ? '?' : '&') + 'preview=' + Date.now();
	}

	function save() {
		if (!logo) {
			return;
		}

		saveBtn.disabled = true;
		say('Symbole werden erzeugt…');

		var form = new URLSearchParams();
		var tokenField = document.querySelector('input[name="_token"]');

		form.set('_token', tokenField ? tokenField.value : '');

		var scratch = document.createElement('canvas');

		Object.keys(SIZES).forEach(function (name) {
			// Nur das grosse Symbol bekommt runde Ecken mitgebrannt; die Systeme
			// runden selbst nach, und ein doppelter Radius sieht falsch aus.
			draw(scratch, SIZES[name], name === 'icon-512');
			form.set(name, scratch.toDataURL('image/png'));
		});

		window
			.fetch('/branding/icons', {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
				credentials: 'same-origin',
				body: form.toString(),
			})
			.then(function (res) {
				return res.json().then(function (data) {
					return { ok: res.ok, data: data };
				});
			})
			.then(function (result) {
				if (!result.ok || !result.data.ok) {
					say('Speichern fehlgeschlagen: ' + (result.data.errors || []).join(', '), 'bad');
					saveBtn.disabled = false;
					return;
				}

				say('Übernommen. Der Browser zeigt das neue Symbol nach einem Neuladen.', 'ok');
			})
			.catch(function (error) {
				say('Speichern fehlgeschlagen: ' + error.message, 'bad');
				saveBtn.disabled = false;
			});
	}

	if (bgInput) {
		bgInput.addEventListener('input', preview);
	}
	if (padInput) {
		padInput.addEventListener('input', preview);
	}
	if (saveBtn) {
		saveBtn.addEventListener('click', save);
	}

	load();
})();
