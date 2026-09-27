const { chromium } = require('playwright');
const path = require('path');

(async () => {
	const browser = await chromium.launch({ executablePath: require('./chromium').pfad() });
	const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
	const errors = [];
	page.on('pageerror', e => errors.push('pageerror: ' + e.message));
	page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

	await page.goto('file://' + path.join(__dirname, 'create.html'));

	const fails = [];
	const check = (label, ok) => { if (!ok) fails.push(label); };

	const codeVisible = () => page.locator('#connect_code').isVisible();
	const codeRequired = () => page.locator('#connect_code').evaluate(el => el.hasAttribute('required'));
	const monitorCard = () => page.locator('[data-when="monitor"].card').isVisible();
	const wpCard = () => page.locator('[data-when="wordpress"].card').isVisible();
	const submitText = () => page.locator('button[type="submit"]').innerText();

	check('WordPress: Verbindungscode sichtbar', await codeVisible());
	check('WordPress: Verbindungscode ist Pflicht', await codeRequired());
	check('WordPress: Anleitung sichtbar', await wpCard());
	check('WordPress: Monitor-Karte versteckt', !(await monitorCard()));
	check('WordPress: Beschriftung "Verbinden"', (await submitText()).trim() === 'Verbinden');

	const selected = () => page.locator('.type-option.selected input').getAttribute('value');
	check('WordPress: Karte ist hervorgehoben', (await selected()) === 'wordpress');
	check('Es ist genau eine Karte hervorgehoben', (await page.locator('.type-option.selected').count()) === 1);

	await page.locator('input[value="monitor"]').check();

	check('Monitor: Verbindungscode versteckt', !(await codeVisible()));
	check('Monitor: Verbindungscode nicht mehr Pflicht', !(await codeRequired()));
	check('Monitor: Erklaerkarte sichtbar', await monitorCard());
	check('Monitor: WordPress-Anleitung versteckt', !(await wpCard()));
	check('Monitor: Beschriftung "Seite aufnehmen"', (await submitText()).trim() === 'Seite aufnehmen');
	check('Monitor: Update-Richtlinie versteckt', !(await page.locator('#auto_update_policy').isVisible()));
	check('Monitor: Hervorhebung wandert mit', (await selected()) === 'monitor');
	check('Monitor: weiterhin genau eine Hervorhebung', (await page.locator('.type-option.selected').count()) === 1);

	// Ein verstecktes Pflichtfeld wuerde das Absenden lautlos blockieren.
	await page.locator('#url').fill('https://shop.kunde.de');
	check('Monitor: Formular ist absendbar', await page.locator('[data-site-form]').evaluate(f => f.checkValidity()));

	await page.locator('input[value="wordpress"]').check();
	check('Zurueck zu WordPress: Code wieder Pflicht', await codeRequired());
	check('Zurueck zu WordPress: Formular blockiert ohne Code', !(await page.locator('[data-site-form]').evaluate(f => f.checkValidity())));

	await page.setViewportSize({ width: 390, height: 844 });
	const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	check('Kein horizontaler Ueberlauf auf dem Handy (' + overflow + 'px)', overflow <= 0);
	await page.screenshot({ path: path.join(__dirname, 'mobile.png'), fullPage: true });

	await page.setViewportSize({ width: 1280, height: 900 });
	await page.locator('input[value="monitor"]').check();
	await page.screenshot({ path: path.join(__dirname, 'desktop.png'), fullPage: true });

	// Ohne JavaScript (oder mit veralteter app.js aus dem Cache) darf das
	// Formular trotzdem absendbar bleiben - der Server prueft den Code selbst.
	const noJs = await browser.newContext({ javaScriptEnabled: false });
	const plain = await noJs.newPage();
	await plain.goto('file://' + path.join(__dirname, 'create.html'));
	await plain.locator('#url').fill('https://shop.kunde.de');
	await plain.locator('input[value="monitor"]').check();
	check('Ohne JS: Verbindungscode ist kein Pflichtfeld',
		!(await plain.locator('#connect_code').evaluate(el => el.hasAttribute('required'))));
	check('Ohne JS: Monitor-Formular ist absendbar',
		await plain.locator('[data-site-form]').evaluate(f => f.checkValidity()));
	check('Ohne JS: Typ wird trotzdem uebertragen',
		(await plain.locator('input[value="monitor"]').isChecked()));
	await noJs.close();

	console.log(`${15 + 4 + 3} Prüfungen, ${fails.length} Fehler`);
	fails.forEach(f => console.log('  FEHLT: ' + f));
	errors.forEach(e => console.log('  JS: ' + e));
	await browser.close();
	process.exit(fails.length || errors.length ? 1 : 0);
})();
