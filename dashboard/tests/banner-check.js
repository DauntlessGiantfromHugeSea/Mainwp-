const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

// Ein winziges Logo, ohne das Netz zu brauchen.
const LOGO = Buffer.from(
	'<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" rx="8" fill="#ff3d8b"/></svg>',
	'utf8'
);

(async () => {
	const browser = await chromium.launch({ executablePath: require('./chromium').pfad() });
	const errors = [];
	const fails = [];
	let n = 0;
	const check = (label, ok) => { n++; if (!ok) fails.push(label); };

	const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
	page.on('pageerror', e => errors.push('pageerror: ' + e.message));
	page.on('requestfailed', r => errors.push('request failed: ' + r.url()));

	// Das Logo kommt von aussen — hier oertlich beantwortet, damit der Lauf
	// ohne Netz auskommt.
	await page.route('**/api/assets/**', route =>
		route.fulfill({ status: 200, contentType: 'image/svg+xml', body: LOGO }));

	const datei = 'file://' + path.join(__dirname, 'banner.html');
	await page.goto(datei);

	const banner = page.locator('#nlc-backup-banner');
	check('Das Banner ist sichtbar', await banner.isVisible());

	// Unten rechts, vollstaendig im Bild.
	const box = await banner.boundingBox();
	const vp = page.viewportSize();
	check('Es klebt am rechten Rand', box !== null && Math.abs((vp.width - (box.x + box.width)) - 16) <= 1);
	check('Und am unteren Rand', box !== null && Math.abs((vp.height - (box.y + box.height)) - 16) <= 1);
	check('Es ragt nicht aus dem Bild', box !== null && box.x >= 0 && box.y >= 0);
	check('Es bleibt schmal', box !== null && box.width <= 330);
	// Hoehe mitpruefen: ein durchschlagendes "line-height: 3" aus dem Theme
	// macht den Hinweis sonst zum Block, der aus dem Bild ragt.
	check('Es bleibt flach (' + (box ? Math.round(box.height) : '?') + 'px)', box !== null && box.height <= 140);

	// Das Theme hat auch etwas unten rechts liegen. Der Hinweis muss darueber
	// liegen, sonst sieht ihn niemand.
	const obenauf = await page.evaluate(() => {
		const b = document.getElementById('nlc-backup-banner');
		const r = b.getBoundingClientRect();
		const el = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
		return b.contains(el);
	});
	check('Es liegt über dem übrigen Seiteninhalt', obenauf);

	// Das Theme setzt p auf 34px, rot, Versalien, Georgia. Nichts davon darf
	// durchschlagen - sonst sieht der Hinweis auf jeder Seite anders aus.
	const text = await page.locator('#nlc-backup-banner .nlc-bb-text').evaluate(el => {
		const s = getComputedStyle(el);
		return { size: s.fontSize, color: s.color, transform: s.textTransform, family: s.fontFamily, align: s.textAlign };
	});
	check('Die Schriftgröße des Themes schlägt nicht durch (' + text.size + ')', text.size === '13px');
	check('Die Farbe des Themes auch nicht (' + text.color + ')', text.color !== 'rgb(204, 0, 0)');
	check('Keine Versalien (' + text.transform + ')', text.transform === 'none');
	check('Nicht die Serifenschrift des Themes', !/Georgia/i.test(text.family));
	check('Links ausgerichtet, nicht zentriert', text.align === 'left');

	// Das Theme setzt img auf 100% Breite mit grünem Rahmen.
	const bild = await page.locator('#nlc-backup-banner img').evaluate(el => {
		const s = getComputedStyle(el);
		return { w: el.getBoundingClientRect().width, border: s.borderTopWidth };
	});
	check('Das Logo wird nicht auf volle Breite gezogen (' + Math.round(bild.w) + 'px)', bild.w <= 24);
	check('Und bekommt keinen Rahmen vom Theme', bild.border === '0px');

	// Das Theme setzt button auf 48px mit gelbem Grund.
	const knopf = await page.locator('#nlc-backup-banner .nlc-bb-close').evaluate(el => {
		const s = getComputedStyle(el);
		return { size: s.fontSize, bg: s.backgroundColor };
	});
	check('Der Schließer erbt nicht die Themegröße (' + knopf.size + ')', knopf.size === '17px');
	check('Und nicht den gelben Grund', knopf.bg !== 'rgb(255, 255, 0)');

	// Lesbarkeit auf dem dunklen Kasten.
	const kontrast = await page.evaluate(() => {
		const lum = (rgb) => {
			const [r, g, b] = rgb.match(/\d+/g).slice(0, 3).map(Number).map(v => {
				const c = v / 255;
				return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
			});
			return 0.2126 * r + 0.7152 * g + 0.0722 * b;
		};
		const el = document.querySelector('#nlc-backup-banner .nlc-bb-text');
		const a = lum(getComputedStyle(el).color);
		const b = lum(getComputedStyle(document.getElementById('nlc-backup-banner')).backgroundColor);
		return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
	});
	check('Der Text ist gut lesbar (' + kontrast.toFixed(2) + ':1)', kontrast >= 4.5);

	check('Es meldet sich als Statusmeldung an', await banner.getAttribute('role') === 'status');

	await page.screenshot({ path: path.join(__dirname, 'banner-desktop.png') });

	// --- Wegklicken ---------------------------------------------------------
	// Kurze Frist und abgefangen: liegt der Knopf durch ein kaputtes Layout
	// ausserhalb des Bildes, soll der Lauf das melden und weitermachen —
	// nicht dreissig Sekunden warten und dann mit einem Stapel abstuerzen.
	let geklickt = true;
	try {
		await page.locator('#nlc-backup-banner .nlc-bb-close').click({ timeout: 3000 });
	} catch (e) {
		geklickt = false;
		errors.push('Der Schließer ließ sich nicht anklicken: ' + String(e.message).split('\n')[0]);
	}
	check('Der Schließer ist anklickbar', geklickt);
	check('Weggeklickt ist es weg', !geklickt || await page.locator('#nlc-backup-banner').count() === 0);

	await page.reload();
	check('Und bleibt nach dem Neuladen weg', await page.locator('#nlc-backup-banner').count() === 0);

	// Eine neue Sicherung traegt einen anderen Schluessel — der Hinweis muss
	// dann wieder erscheinen, sonst sieht der Kunde ihn genau einmal im Leben.
	await page.evaluate(() => {
		const b = document.body;
		b.insertAdjacentHTML('beforeend', '<div id="nlc-backup-banner" data-key="nlc-backup-1800000000">neu</div>');
	});
	const neuerSchluessel = await page.evaluate(() => {
		try { return sessionStorage.getItem('nlc-backup-1800000000') === null; } catch (e) { return true; }
	});
	check('Eine neue Sicherung ist nicht vorab weggeklickt', neuerSchluessel);

	// --- Handy --------------------------------------------------------------
	const handy = await browser.newPage({ viewport: { width: 390, height: 844 } });
	await handy.route('**/api/assets/**', route =>
		route.fulfill({ status: 200, contentType: 'image/svg+xml', body: LOGO }));
	await handy.goto(datei);

	const ueberlauf = await handy.evaluate(() =>
		document.documentElement.scrollWidth - document.documentElement.clientWidth);
	check('Kein seitliches Scrollen auf dem Handy (' + ueberlauf + 'px)', ueberlauf <= 0);

	const mbox = await handy.locator('#nlc-backup-banner').boundingBox();
	check('Auf dem Handy bleibt es im Bild', mbox !== null && mbox.x >= 0 && (mbox.x + mbox.width) <= 390);
	await handy.screenshot({ path: path.join(__dirname, 'banner-mobile.png') });

	await handy.setViewportSize({ width: 320, height: 640 });
	const schmal = await handy.evaluate(() =>
		document.documentElement.scrollWidth - document.documentElement.clientWidth);
	check('Auch bei 320px nicht (' + schmal + 'px)', schmal <= 0);

	console.log(`${n} Prüfungen, ${fails.length + errors.length} Fehler`);
	fails.forEach(f => console.log('  FEHLT: ' + f));
	errors.forEach(e => console.log('  ' + e));
	await browser.close();
	process.exit(fails.length || errors.length ? 1 : 0);
})();
