const { chromium } = require('playwright');
const path = require('path');

(async () => {
	const browser = await chromium.launch({ executablePath: require('./chromium').pfad() });
	const errors = [];
	const fails = [];
	const check = (label, ok) => { if (!ok) fails.push(label); };

	const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
	page.on('pageerror', e => errors.push('pageerror: ' + e.message));
	page.on('requestfailed', r => errors.push('request failed: ' + r.url()));

	await page.goto('file://' + path.join(__dirname, 'mmode.html'));

	check('Überschrift ist sichtbar', await page.locator('h1').isVisible());
	check('Überschrift lautet "Wartungsmodus"', (await page.locator('h1').innerText()).trim() === 'Wartungsmodus');
	check('Die Marke färbt die Überschrift',
		(await page.locator('h1').evaluate(el => getComputedStyle(el).color)) === 'rgb(255, 61, 139)');
	check('Der Punkt pulsiert in der Marke',
		(await page.locator('.pulse').evaluate(el => getComputedStyle(el).backgroundColor)) === 'rgb(255, 61, 139)');
	check('Keine Uhrzeit auf der Seite', !(await page.locator('body').innerText()).match(/\d{1,2}:\d{2}/));
	check('Kein leerer Platzhalter, wo die Zeit stand', (await page.locator('.until').count()) === 0);

	// Kontrast der Überschrift gegen den Kartengrund (WCAG).
	const contrast = await page.evaluate(() => {
		const lum = (rgb) => {
			const [r, g, b] = rgb.match(/\d+/g).slice(0, 3).map(Number).map(v => {
				const c = v / 255;
				return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
			});
			return 0.2126 * r + 0.7152 * g + 0.0722 * b;
		};
		// Die Karte ist halbtransparent über sehr dunklem Grund; für die
		// Rechnung zählt der dunkle Untergrund.
		const a = lum(getComputedStyle(document.querySelector('h1')).color);
		const b = lum('rgb(8, 8, 10)');
		return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
	});
	check('Überschrift hat ausreichenden Kontrast (' + contrast.toFixed(2) + ':1)', contrast >= 4.5);

	const body = await page.locator('p').first().evaluate(el => getComputedStyle(el).color);
	const bodyContrast = await page.evaluate((c) => {
		const lum = (rgb) => {
			const [r, g, b] = rgb.match(/\d+/g).slice(0, 3).map(Number).map(v => {
				const x = v / 255;
				return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
			});
			return 0.2126 * r + 0.7152 * g + 0.0722 * b;
		};
		const a = lum(c), b = lum('rgb(8, 8, 10)');
		return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
	}, body);
	check('Fließtext hat ausreichenden Kontrast (' + bodyContrast.toFixed(2) + ':1)', bodyContrast >= 4.5);

	await page.screenshot({ path: path.join(__dirname, 'mmode-desktop.png') });

	// Handy
	await page.setViewportSize({ width: 390, height: 844 });
	const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	check('Kein horizontaler Überlauf auf dem Handy (' + overflow + 'px)', overflow <= 0);
	await page.screenshot({ path: path.join(__dirname, 'mmode-mobile.png') });

	// Sehr schmal
	await page.setViewportSize({ width: 320, height: 640 });
	const narrow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	check('Auch bei 320px kein Überlauf (' + narrow + 'px)', narrow <= 0);

	console.log(`${10} Prüfungen, ${fails.length} Fehler`);
	fails.forEach(f => console.log('  FEHLT: ' + f));
	errors.forEach(e => console.log('  ' + e));
	await browser.close();
	process.exit(fails.length || errors.length ? 1 : 0);
})();
