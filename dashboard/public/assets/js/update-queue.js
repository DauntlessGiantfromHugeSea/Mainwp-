/*
 * Stand der vorgemerkten Updates.
 *
 * Updates laufen im Zeitplaner, nicht im Browser. Diese Anzeige fragt in
 * Abstaenden nach, woran er gerade ist — und hoert von selbst auf, wenn
 * nichts mehr aussteht, damit sie keinen Verkehr erzeugt.
 */
(function () {
	'use strict';

	var box = document.getElementById('update-queue');

	if (!box) {
		return;
	}

	var url = box.getAttribute('data-status-url');
	var out = box.querySelector('[data-status-out]');
	var ruhig = 0;
	var liefNoch = false;

	function zeile(klasse, inhalt) {
		var p = document.createElement('p');
		p.className = klasse;
		p.textContent = inhalt;
		return p;
	}

	function zeichne(d) {
		out.innerHTML = '';

		if (!d.cron) {
			out.appendChild(zeile('notice bad', 'Der Zeitplaner läuft nicht — es wird nichts abgearbeitet.'));
			return;
		}

		if (d.laufend) {
			var kopf = document.createElement('p');
			kopf.className = 'small';

			var name = document.createElement('strong');
			// Immer als Text, nie als HTML: der Name kommt aus der Datenbank.
			name.textContent = d.laufend.name;

			kopf.appendChild(name);
			kopf.appendChild(document.createTextNode(' — wird gerade aktualisiert'));
			out.appendChild(kopf);
		}

		if (d.offen.length > 0) {
			out.appendChild(zeile('small muted', d.offen.length + ' Seite(n) warten.'));

			var liste = document.createElement('ol');
			liste.className = 'small muted';

			d.offen.slice(0, 8).forEach(function (seite) {
				var li = document.createElement('li');
				li.textContent = seite.name;
				liste.appendChild(li);
			});

			out.appendChild(liste);
		} else if (!d.laufend) {
			out.appendChild(zeile('small muted', 'Es ist nichts vorgemerkt.'));
		}

		if (d.letzte.length > 0) {
			var fertig = document.createElement('ul');
			fertig.className = 'small';

			d.letzte.forEach(function (eintrag) {
				var li = document.createElement('li');
				li.className = eintrag.ok ? '' : 'bad';
				li.textContent = eintrag.name + ' — ' + eintrag.note;
				fertig.appendChild(li);
			});

			var kopf2 = zeile('small muted mt', 'Zuletzt erledigt:');
			out.appendChild(kopf2);
			out.appendChild(fertig);
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

				// Ist gerade das Letzte fertig geworden, zeigt die Seite
				// darunter noch die alte Update-Liste. Einmal neu laden, dann
				// stimmt sie wieder.
				if (liefNoch && !d.laufend && d.offen.length === 0) {
					window.location.reload();
					return;
				}

				liefNoch = Boolean(d.laufend) || d.offen.length > 0;

				if (d.laufend) {
					ruhig = 0;
					window.setTimeout(frage, 5000);
				} else if (d.offen.length > 0) {
					window.setTimeout(frage, 10000);
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
