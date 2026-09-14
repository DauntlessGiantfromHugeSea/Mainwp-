/*
 * NorthLab Control Panel — Service Worker.
 *
 * Bewusst zurückhaltend: gecacht wird ausschließlich die Hülle (CSS, Skripte,
 * Symbole, Offline-Seite). Fertige Seiten landen nie im Cache — sie hängen an
 * der Anmeldung, und auf einem geteilten Gerät wäre das die Übersicht eines
 * fremden Kontos. Ohne Netz gibt es deshalb eine ehrliche Hinweisseite statt
 * eines veralteten Dashboards.
 *
 * __VERSION__ ersetzt der Server beim Ausliefern, damit ein Update die alten
 * Einträge sicher verdrängt.
 */

const VERSION = '__VERSION__';
const CACHE = 'northlab-shell-' + VERSION;
const OFFLINE = __OFFLINE__;
const OFFLINE_PATH = new URL(OFFLINE, self.location.origin).pathname;

const SHELL = __SHELL__;

self.addEventListener('install', (event) => {
	event.waitUntil(
		caches
			.open(CACHE)
			.then((cache) => cache.addAll(SHELL))
			// Eine einzelne fehlende Datei darf die Installation nicht kippen.
			.catch(() => undefined)
			.then(() => self.skipWaiting())
	);
});

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches
			.keys()
			.then((keys) =>
				Promise.all(
					keys
						.filter((key) => key.startsWith('northlab-shell-') && key !== CACHE)
						.map((key) => caches.delete(key))
				)
			)
			.then(() => self.clients.claim())
	);
});

self.addEventListener('message', (event) => {
	if (event.data === 'skip-waiting') {
		self.skipWaiting();
	}
});

/** Gehört die Adresse zur Hülle, die gecacht werden darf? */
function isShellAsset(url) {
	return (
		url.origin === self.location.origin &&
		(url.pathname.startsWith('/assets/') || url.pathname === OFFLINE_PATH)
	);
}

self.addEventListener('fetch', (event) => {
	const request = event.request;

	if (request.method !== 'GET') {
		return;
	}

	const url = new URL(request.url);

	// Seitenaufrufe immer frisch holen; ohne Netz die Hinweisseite zeigen.
	if (request.mode === 'navigate') {
		event.respondWith(
			fetch(request).catch(() =>
				caches.match(OFFLINE).then((cached) => cached || offlineFallback())
			)
		);
		return;
	}

	if (!isShellAsset(url)) {
		return;
	}

	// Hülle: aus dem Cache antworten, im Hintergrund erneuern.
	event.respondWith(
		caches.match(request).then((cached) => {
			const network = fetch(request)
				.then((response) => {
					if (response && response.ok && response.type === 'basic') {
						const copy = response.clone();
						caches.open(CACHE).then((cache) => cache.put(request, copy));
					}
					return response;
				})
				.catch(() => cached);

			return cached || network;
		})
	);
});

/** Letzte Rettung, falls selbst die Offline-Seite fehlt. */
function offlineFallback() {
	return new Response(
		'<!doctype html><meta charset="utf-8"><title>Offline</title>' +
			'<body style="margin:0;display:grid;place-items:center;height:100vh;background:#08080a;' +
			'color:#a3a3af;font:15px system-ui,sans-serif">Keine Verbindung.</body>',
		{ status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
	);
}

/* ------------------------------------------------------------- Meldungen */

self.addEventListener('push', (event) => {
	let data = {};

	try {
		data = event.data ? event.data.json() : {};
	} catch (e) {
		data = { body: event.data ? event.data.text() : '' };
	}

	const title = data.title || 'NorthLab';
	const options = {
		body: data.body || '',
		icon: '/assets/icons/icon-192.png',
		badge: '/assets/icons/icon-192.png',
		// Gleiches Ereignis zur gleichen Seite ersetzt die vorige Meldung,
		// statt den Sperrbildschirm zuzupflastern.
		tag: data.tag || 'northlab',
		renotify: true,
		timestamp: Date.now(),
		data: { url: data.url || '/' },
	};

	event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
	event.notification.close();

	const target = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin).href;

	event.waitUntil(
		self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
			// Ein offenes Panel-Fenster wiederverwenden, statt ein zweites zu oeffnen.
			for (const client of windows) {
				if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
					client.navigate(target);
					return client.focus();
				}
			}

			return self.clients.openWindow(target);
		})
	);
});
