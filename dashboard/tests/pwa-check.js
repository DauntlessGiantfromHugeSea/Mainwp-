const { chromium } = require('playwright');
const path = require('path');

const BASE = 'http://127.0.0.1:8099';

(async () => {
	const browser = await chromium.launch({ executablePath: require('./chromium').pfad() });
	const fails = [];
	const errors = [];
	const check = (l, ok) => { if (!ok) fails.push(l); };

	const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
	const page = await context.newPage();
	page.on('pageerror', e => errors.push('pageerror: ' + e.message));
	// Waehrend des Offline-Tests meldet der Browser zwangslaeufig einen
	// Netzwerkfehler — das ist der Sinn der Uebung, kein Befund.
	let offlineOnPurpose = false;
	page.on('console', m => {
		if (m.type() === 'error' && !offlineOnPurpose) { errors.push('console: ' + m.text()); }
	});

	// --- Manifest ---
	const manifest = await (await page.request.get(BASE + '/manifest.webmanifest')).json();
	check('Manifest hat einen Namen', typeof manifest.name === 'string' && manifest.name.length > 0);
	check('Kurzname passt auf einen Startbildschirm', manifest.short_name.length <= 12);
	check('Startadresse gesetzt', !!manifest.start_url);
	check('Vollbild ohne Browserleiste', manifest.display === 'standalone');
	check('Hintergrundfarbe gesetzt', /^#[0-9a-f]{6}$/i.test(manifest.background_color));
	check('Statusleistenfarbe ist die Akzentfarbe', manifest.theme_color === '#f9907a');

	const sizes = manifest.icons.map(i => i.sizes);
	check('Symbol 192 vorhanden', sizes.includes('192x192'));
	check('Symbol 512 vorhanden', sizes.includes('512x512'));
	check('Zuschneidbares Symbol vorhanden', manifest.icons.some(i => i.purpose === 'maskable'));
	check('Symbole kommen aus dem eigenen Zweig',
		manifest.icons.some(i => i.src.includes('/branding/icon/')));

	// Alle Symbole müssen wirklich abrufbar sein — ein 404 verhindert die Installation.
	for (const icon of manifest.icons) {
		const res = await page.request.get(icon.src);
		check('Symbol erreichbar: ' + icon.src.split('/').pop() + ' (' + res.status() + ')', res.ok());
		const type = res.headers()['content-type'] || '';
		check('Symbol ist ein PNG: ' + icon.src.split('/').pop(), type.includes('image/png'));
	}

	// --- Service Worker ---
	await page.goto(BASE + '/');
	await page.waitForFunction(() => navigator.serviceWorker.controller !== null || navigator.serviceWorker.ready, { timeout: 10000 });
	const reg = await page.evaluate(async () => {
		const r = await navigator.serviceWorker.ready;
		return { scope: r.scope, active: !!r.active };
	});
	check('Service Worker ist aktiv', reg.active);
	check('Er gilt für die ganze Anwendung', reg.scope === BASE + '/');

	// Hülle muss im Cache liegen.
	const cached = await page.evaluate(async () => {
		const names = await caches.keys();
		const out = [];
		for (const n of names) {
			const keys = await (await caches.open(n)).keys();
			out.push(...keys.map(k => new URL(k.url).pathname));
		}
		return out;
	});
	check('CSS ist vorgehalten', cached.some(p => p.endsWith('/assets/css/app.css')));
	check('JavaScript ist vorgehalten', cached.some(p => p.endsWith('/assets/js/app.js')));
	check('Offline-Seite ist vorgehalten', cached.includes('/offline'));
	check('Keine echte Seite im Cache', !cached.includes('/'));

	// --- Offline ---
	offlineOnPurpose = true;
	await context.setOffline(true);
	const res = await page.goto(BASE + '/sites', { waitUntil: 'domcontentloaded' }).catch(() => null);
	const text = await page.locator('body').innerText().catch(() => '');
	check('Ohne Netz kommt die Hinweisseite', text.includes('Keine Verbindung'));
	check('Und sie erklärt, warum nichts Altes gezeigt wird', text.includes('hängt an'));
	check('Mit einem Knopf zum erneuten Versuch', await page.locator('button').isVisible());

	const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	check('Hinweisseite läuft auf dem Handy nicht über (' + overflow + 'px)', overflow <= 0);
	await page.screenshot({ path: path.join(__dirname, 'pwa-offline.png') });

	await context.setOffline(false);
	offlineOnPurpose = false;

	console.log(`${23} Prüfungen, ${fails.length} Fehler`);
	fails.forEach(f => console.log('  FEHLT: ' + f));
	errors.forEach(e => console.log('  ' + e));
	await browser.close();
	process.exit(fails.length || errors.length ? 1 : 0);
})();
