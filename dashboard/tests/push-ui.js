/*
 * Der Weg im Browser: Erlaubnis, Abonnement, Meldung ans Panel — und die
 * Zustellung einer echten Push-Nachricht an den Service Worker.
 */
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8099';

(async () => {
	const browser = await chromium.launch({ executablePath: require('./chromium').pfad() });
	const fails = [];
	const errors = [];
	const check = (l, ok) => { if (!ok) fails.push(l); };

	const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
	await context.grantPermissions(['notifications'], { origin: BASE });

	// Ohne echten Push-Dienst gibt es kein Abonnement — also den Manager
	// ersetzen. Geprueft wird die Logik des Panels, nicht die des Browsers.
	const stub = () => {
		const fake = {
			endpoint: 'https://fcm.example/send/abc123',
			getKey(name) {
				const size = name === 'auth' ? 16 : 65;
				const bytes = new Uint8Array(size);
				for (let i = 0; i < size; i++) { bytes[i] = (i * 7 + (name === 'auth' ? 3 : 1)) % 256; }
				if (name !== 'auth') { bytes[0] = 4; }
				return bytes.buffer;
			},
			unsubscribe: () => Promise.resolve(true),
		};

		let current = null;

		Object.defineProperty(navigator.serviceWorker, 'ready', {
			configurable: true,
			get() {
				return Promise.resolve({
					pushManager: {
						getSubscription: () => Promise.resolve(current),
						subscribe: () => { current = fake; return Promise.resolve(fake); },
					},
					showNotification: () => Promise.resolve(),
				});
			},
		});
	};

	const page = await context.newPage();
	await page.addInitScript(stub);
	page.on('pageerror', e => errors.push('pageerror: ' + e.message));
	page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

	await page.goto(BASE + '/push-page');
	await page.waitForSelector('[data-push-enable]:not([hidden])', { timeout: 8000 });

	check('Ohne Abonnement erscheint der Einschalter', await page.locator('[data-push-enable]').isVisible());
	check('Der Ausschalter bleibt verborgen', !(await page.locator('[data-push-disable]').isVisible()));
	check('Die Probemeldung ist noch nicht möglich', !(await page.locator('#push-test').isVisible()));
	check('Der Zustand wird benannt',
		(await page.locator('[data-push-status]').innerText()).includes('noch keine Meldungen'));

	await page.locator('[data-push-enable]').click();
	await page.waitForFunction(
		() => document.querySelector('[data-push-status]').textContent.includes('bekommt jetzt'),
		{ timeout: 8000 }
	);

	check('Nach dem Einschalten meldet die Oberfläche Erfolg', true);
	check('Der Ausschalter erscheint', await page.locator('[data-push-disable]').isVisible());
	check('Die Probemeldung wird möglich', await page.locator('#push-test').isVisible());
	check('Der Einschalter verschwindet', !(await page.locator('[data-push-enable]').isVisible()));

	// Was beim Panel ankam.
	const sent = await (await page.request.get(BASE + '/push/last-subscribe')).json();
	check('Der Endpunkt wird übertragen', sent.endpoint === 'https://fcm.example/send/abc123');
	check('Das CSRF-Merkmal liegt bei', sent._token === 'testtoken');
	check('Der Geräteschlüssel ist base64url', /^[A-Za-z0-9_-]+$/.test(sent.p256dh || ''));
	check('Das Geheimnis ist base64url', /^[A-Za-z0-9_-]+$/.test(sent.auth || ''));
	check('Der Geräteschlüssel ist 65 Byte', Buffer.from(sent.p256dh || '', 'base64url').length === 65);
	check('Das Geheimnis ist 16 Byte', Buffer.from(sent.auth || '', 'base64url').length === 16);
	check('Es wird kein Klartext-JSON geschickt', typeof sent.endpoint === 'string');
	check('Eine Gerätebezeichnung liegt bei', (sent.label || '').length > 0);

	// --- Echte Zustellung an den Service Worker über das DevTools-Protokoll ---
	const plain = await context.newPage();
	const cdp = await context.newCDPSession(plain);
	const registrations = [];
	cdp.on('ServiceWorker.workerRegistrationUpdated', (e) => registrations.push(...e.registrations));
	await cdp.send('ServiceWorker.enable');
	await plain.goto(BASE + '/');
	await plain.waitForFunction(() => navigator.serviceWorker.controller !== null, { timeout: 10000 }).catch(() => {});
	await plain.waitForTimeout(500);

	const reg = registrations.find(r => r.scopeURL === BASE + '/' && !r.isDeleted);
	check('Der Service Worker ist registriert', !!reg);

	if (reg) {
		let delivered = false;
		try {
			await cdp.send('ServiceWorker.deliverPushMessage', {
				origin: BASE,
				registrationId: reg.registrationId,
				data: JSON.stringify({ title: 'Seite offline', body: 'kundenseite.de antwortet nicht.', url: '/sites/7', tag: 'site.offline:7' }),
			});
			delivered = true;
		} catch (e) {
			errors.push('deliverPushMessage: ' + e.message);
		}
		check('Eine Push-Nachricht lässt sich zustellen', delivered);

		if (delivered) {
			await plain.waitForTimeout(800);
			const shown = await plain.evaluate(async () => {
				const r = await navigator.serviceWorker.ready;
				const list = await r.getNotifications();
				return list.map(n => ({ title: n.title, body: n.body, tag: n.tag, url: n.data && n.data.url }));
			});

			check('Der Service Worker zeigt eine Meldung', shown.length === 1);
			if (shown.length) {
				check('Mit dem übergebenen Titel', shown[0].title === 'Seite offline');
				check('Mit dem übergebenen Text', shown[0].body.includes('antwortet nicht'));
				check('Mit der Kennung zum Zusammenfassen', shown[0].tag === 'site.offline:7');
				check('Und dem Ziel für den Klick', shown[0].url === '/sites/7');
			}
		}
	}

	console.log(`${25} Prüfungen, ${fails.length} Fehler`);
	fails.forEach(f => console.log('  FEHLT: ' + f));
	errors.forEach(e => console.log('  ' + e));
	await browser.close();
	process.exit(fails.length || errors.length ? 1 : 0);
})();
