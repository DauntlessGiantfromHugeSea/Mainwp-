/*
 * Wo der Browser fuer die Playwright-Prueflaeufe liegt.
 *
 * In der Entwicklungsumgebung ist er vorinstalliert und liegt an einem festen
 * Ort. Anderswo soll Playwright seinen eigenen nehmen — dann bleibt der Pfad
 * leer, und launch() entscheidet selbst.
 */
const fs = require('fs');

const ORTE = [
	'/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
	'/opt/pw-browsers/chromium/chrome-linux/chrome',
];

module.exports.pfad = function () {
	if (process.env.NL_CHROMIUM) {
		return process.env.NL_CHROMIUM;
	}

	for (const ort of ORTE) {
		if (fs.existsSync(ort)) {
			return ort;
		}
	}

	return undefined;
};
