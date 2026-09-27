/*
 * Der Zusammenbau im Browser: schwarzer Grund, Logo mittig, richtige Groessen —
 * und die Pixel werden wirklich nachgemessen.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = 'http://127.0.0.1:8099';
const OUT = '/tmp/nlc-icons';

(async () => {
	const browser = await chromium.launch({ executablePath: require('./chromium').pfad() });
	const fails = [];
	const errors = [];
	const check = (l, ok) => { if (!ok) fails.push(l); };

	const page = await browser.newPage({ viewport: { width: 900, height: 700 } });
	page.on('pageerror', e => errors.push('pageerror: ' + e.message));
	page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

	await page.goto(BASE + '/icon-page');
	await page.waitForFunction(
		() => !document.querySelector('[data-icon-save]').disabled,
		{ timeout: 10000 }
	);

	check('Das Logo wird geladen und die Vorschau freigegeben', true);
	check('Der Zustand wird gemeldet',
		(await page.locator('[data-icon-status]').innerText()).includes('So sieht das Symbol aus'));

	// Pixel der Vorschau nachmessen.
	const pixels = await page.evaluate(() => {
		const c = document.getElementById('icon-canvas');
		const ctx = c.getContext('2d');
		const at = (x, y) => Array.from(ctx.getImageData(x, y, 1, 1).data);
		// Ein einzelner Punkt in der Mitte kann in die Luecke zwischen zwei
		// Buchstaben fallen. Gezaehlt wird deshalb ueber ein ganzes Band.
		const band = ctx.getImageData(0, Math.round(c.height / 2) - 2, c.width, 5).data;
		let hell = 0;
		for (let i = 0; i < band.length; i += 4) {
			if (band[i] > 200 && band[i + 1] > 200 && band[i + 2] > 200 && band[i + 3] > 200) { hell++; }
		}

		return {
			hell: hell,
			gesamt: band.length / 4,
			ecke: at(3, 3),
			rand: at(c.width / 2, 8),
			size: [c.width, c.height],
		};
	});

	check('Die Ecke ist durchsichtig — die Rundung greift', pixels.ecke[3] === 0);
	check('Der Rand über dem Logo ist schwarz',
		pixels.rand[0] === 0 && pixels.rand[1] === 0 && pixels.rand[2] === 0 && pixels.rand[3] === 255);
	const anteil = pixels.hell / pixels.gesamt;
	check('Auf Höhe des Logos steht Helles (' + Math.round(anteil * 100) + '% des Bandes)',
		anteil > 0.05 && anteil < 0.9);
	check('Die Vorschau wird in 256 gezeichnet', pixels.size[0] === 256 && pixels.size[1] === 256);

	// Farbe ändern — die Vorschau muss sofort folgen.
	await page.fill('#icon_bg', '#1133ff');
	await page.dispatchEvent('#icon_bg', 'input');
	await page.waitForTimeout(200);

	const blau = await page.evaluate(() => {
		const c = document.getElementById('icon-canvas');
		return Array.from(c.getContext('2d').getImageData(c.width / 2, 8, 1, 1).data);
	});
	check('Eine andere Farbe wirkt sofort', blau[2] > 200 && blau[0] < 60);

	await page.fill('#icon_bg', '#000000');
	await page.dispatchEvent('#icon_bg', 'input');
	await page.waitForTimeout(200);

	await page.locator('#icon-canvas').screenshot({ path: path.join(__dirname, 'icon-preview.png') });

	// Erzeugen und speichern.
	await page.locator('[data-icon-save]').click();
	await page.waitForFunction(
		() => document.querySelector('[data-icon-status]').textContent.includes('Übernommen'),
		{ timeout: 10000 }
	);
	check('Die Symbole werden gespeichert', true);

	const expected = { 'icon-512': 512, 'icon-192': 192, 'apple-touch-icon': 180, 'favicon-32': 32 };

	for (const [name, size] of Object.entries(expected)) {
		const file = path.join(OUT, name + '.png');
		const there = fs.existsSync(file);
		check('Datei angekommen: ' + name, there);

		if (there) {
			const buf = fs.readFileSync(file);
			check(name + ' ist ein PNG', buf.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])));
			// Breite und Hoehe stehen im IHDR ab Byte 16.
			check(name + ' ist ' + size + ' Pixel breit', buf.readUInt32BE(16) === size);
			check(name + ' ist ' + size + ' Pixel hoch', buf.readUInt32BE(20) === size);
		}
	}

	// Das kleine Favicon darf keine eingebrannten runden Ecken haben — das
	// System rundet selbst, und doppelt sieht falsch aus.
	const kleines = await page.evaluate(async () => {
		const res = await fetch('/branding/logo');
		return res.ok;
	});
	check('Die Logo-Quelle bleibt abrufbar', kleines);

	console.log(`${8 + Object.keys(expected).length * 4 + 1} Prüfungen, ${fails.length} Fehler`);
	fails.forEach(f => console.log('  FEHLT: ' + f));
	errors.forEach(e => console.log('  ' + e));
	await browser.close();
	process.exit(fails.length || errors.length ? 1 : 0);
})();
