/*
 * Laufende Anzeige des Sicherungslaufs.
 *
 * Die Sicherung laeuft im Hintergrund, nicht im Browser. Diese Anzeige fragt
 * in Abstaenden nach, woran sie gerade ist — und hoert von selbst auf, wenn
 * nichts mehr laeuft, damit sie keinen Verkehr erzeugt.
 */
(function () {
	'use strict';

	var box = document.getElementById('backup-status');

	if (!box) {
		return;
	}

	var url = box.getAttribute('data-status-url');
	var out = box.querySelector('[data-status-out]');
	var ruhig = 0;

	function text(el, wert) {
		el.textContent = wert;
		return el;
	}

	function zeile(klasse, inhalt) {
		var p = document.createElement('p');
		p.className = klasse;
		p.textContent = inhalt;
		return p;
	}

	function balken(anteil) {
		var aussen = document.createElement('div');
		aussen.className = 'fortschritt';

		var innen = document.createElement('div');
		innen.className = 'fortschritt-fuellung';
		innen.style.width = Math.max(2, Math.min(100, anteil)) + '%';

		aussen.appendChild(innen);
		return aussen;
	}

	function zeichne(d) {
		out.innerHTML = '';

		if (d.laufend) {
			var kopf = document.createElement('p');
			kopf.className = 'small';
			kopf.appendChild(text(document.createElement('strong'), d.laufend.site));
			kopf.appendChild(document.createTextNode(' — ' + (d.laufend.phase || 'läuft')));
			out.appendChild(kopf);

			if (d.laufend.total > 0) {
				out.appendChild(balken((d.laufend.done / d.laufend.total) * 100));
				out.appendChild(
					zeile(
						'small muted',
						d.laufend.done.toLocaleString('de-DE') +
							' von ' +
							d.laufend.total.toLocaleString('de-DE') +
							' Dateien'
					)
				);
			} else {
				out.appendChild(balken(100));
			}
		} else if (!d.enabled) {
			out.appendChild(zeile('small muted', 'Der Zeitplan ist aus. Es läuft nichts und es ist nichts geplant.'));
		} else if (d.offen.length === 0) {
			out.appendChild(zeile('small muted', 'Alles gesichert. Der nächste Lauf beginnt im nächsten Zeitfenster.'));
		}

		if (d.offen.length > 0) {
			var wartet =
				d.wartet > 0
					? 'Die nächste startet in ' + Math.ceil(d.wartet / 60) + ' Minute(n).'
					: 'Die nächste startet gleich.';

			out.appendChild(
				zeile('small muted', d.offen.length + ' Seite(n) warten noch. ' + wartet)
			);

			var liste = document.createElement('ol');
            liste.className = 'small muted';

			d.offen.slice(0, 8).forEach(function (seite) {
				var li = document.createElement('li');
				li.textContent = seite.name + (seite.vorgemerkt ? ' (vorgemerkt)' : '');
				liste.appendChild(li);
			});

			out.appendChild(liste);
		}

		if (!d.cron) {
			out.appendChild(zeile('notice bad', 'Der Zeitplaner läuft nicht — es wird nichts abgearbeitet.'));
		}
	}

	function frage() {
		window
			.fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then(function (res) {
				return res.json();
			})
			.then(function (d) {
				if (!d.ok) {
					return;
				}

				zeichne(d);

				// Solange etwas laeuft, oft nachsehen; sonst immer seltener und
				// irgendwann gar nicht mehr.
				if (d.laufend) {
					ruhig = 0;
					window.setTimeout(frage, 5000);
				} else if (d.offen.length > 0) {
					window.setTimeout(frage, 30000);
				} else if (ruhig < 3) {
					ruhig++;
					window.setTimeout(frage, 60000);
				}
			})
			.catch(function () {
				// Kein Grund, es weiter zu versuchen.
			});
	}

	frage();
})();
