/* Zeichnet den QR-Code für die 2FA-Einrichtung lokal — die otpauth-URI
   enthält das Geheimnis und darf den Server nicht verlassen. */

(function () {
	'use strict';

	var target = document.getElementById('qrcode');

	if (!target || typeof window.qrcode !== 'function') {
		return;
	}

	var uri = target.getAttribute('data-otpauth');
	if (!uri) {
		return;
	}

	// Typnummer 0 lässt die Bibliothek die kleinste passende Version wählen,
	// Fehlerkorrektur M ist der übliche Kompromiss für Authenticator-Codes.
	var qr = window.qrcode(0, 'M');
	qr.addData(uri);
	qr.make();

	target.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });

	var svg = target.querySelector('svg');
	if (svg) {
		svg.setAttribute('width', '180');
		svg.setAttribute('height', '180');
		svg.setAttribute('role', 'img');
		svg.setAttribute('aria-label', 'QR-Code zur Einrichtung der Zwei-Faktor-Anmeldung');
	}
})();
