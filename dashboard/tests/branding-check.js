const { chromium } = require('playwright');
const path = require('path');

(async () => {
	const browser = await chromium.launch({ executablePath: require('./chromium').pfad() });
	const fails = [];
	const errors = [];
	const check = (l, ok) => { if (!ok) fails.push(l); };

	for (const [name, file] of [['Support-Leiste', 'bar.html'], ['Login-Leiste', 'loginbar.html']]) {
		const page = await browser.newPage({ viewport: { width: 1280, height: 500 } });
		page.on('pageerror', e => errors.push(name + ': ' + e.message));
		await page.goto('file://' + path.join(__dirname, file));

		const sel = file === 'bar.html' ? '#nl-supportbar' : '#nl-loginbar';
		check(name + ' ist sichtbar', await page.locator(sel).isVisible());
		check(name + ': drei bzw. ein Kontaktweg', (await page.locator(sel + ' a').count()) >= 1);
		check(name + ': Symbole gerendert', (await page.locator(sel + ' svg').count()) >= 1);

		// Kontrast der Linktexte gegen den hellen Grund.
		const contrast = await page.evaluate((s) => {
			const lum = (rgb) => {
				const [r, g, b] = rgb.match(/\d+/g).slice(0, 3).map(Number).map(v => {
					const c = v / 255;
					return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
				});
				return 0.2126 * r + 0.7152 * g + 0.0722 * b;
			};
			const el = document.querySelector(s + ' a');
			const a = lum(getComputedStyle(el).color);
			const b = lum(getComputedStyle(document.querySelector(s)).backgroundColor);
			return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
		}, sel);
		check(name + ': Linktext lesbar (' + contrast.toFixed(2) + ':1)', contrast >= 4.5);

		await page.screenshot({ path: path.join(__dirname, file.replace('.html', '-desktop.png')), clip: { x: 0, y: 0, width: 1280, height: 120 } });

		await page.setViewportSize({ width: 390, height: 700 });
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		check(name + ': kein Überlauf auf dem Handy (' + overflow + 'px)', overflow <= 0);
		await page.screenshot({ path: path.join(__dirname, file.replace('.html', '-mobile.png')), fullPage: false });
		await page.close();
	}

	console.log(`10 Prüfungen, ${fails.length} Fehler`);
	fails.forEach(f => console.log('  FEHLT: ' + f));
	errors.forEach(e => console.log('  ' + e));
	await browser.close();
	process.exit(fails.length || errors.length ? 1 : 0);
})();
