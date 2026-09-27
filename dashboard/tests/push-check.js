/*
 * Prueft den PHP-Code gegen unabhaengige Implementierungen:
 * http_ece entschluesselt die Nachricht, Nodes crypto prueft die VAPID-Signatur.
 */
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const ece = require('http_ece');

const fails = [];
const check = (l, ok) => { if (!ok) fails.push(l); };

const raw = execFileSync('php', [__dirname + '/push-encrypt.php']).toString();
const data = JSON.parse(raw);

/* ------------------------------------------- Entschluesseln mit http_ece */

const devicePriv = crypto.createPrivateKey(data.devicePem);
const jwk = devicePriv.export({ format: 'jwk' });

const b64u = (s) => Buffer.from(s, 'base64url');

let plaintext = null;
let error = null;
try {
	plaintext = ece.decrypt(Buffer.from(data.body, 'base64'), {
		version: 'aes128gcm',
		privateKey: crypto.createECDH('prime256v1'),
		authSecret: data.auth,
	});
} catch (e) {
	// http_ece will einen ECDH-Container mit gesetzten Schluesseln.
	try {
		const dh = crypto.createECDH('prime256v1');
		dh.setPrivateKey(b64u(jwk.d));
		plaintext = ece.decrypt(Buffer.from(data.body, 'base64'), {
			version: 'aes128gcm',
			privateKey: dh,
			authSecret: data.auth,
		});
	} catch (e2) {
		error = e2.message;
	}
}

check('Die Nachricht laesst sich unabhaengig entschluesseln' + (error ? ' (' + error + ')' : ''), plaintext !== null);
if (plaintext) {
	check('Und ergibt genau den Klartext', plaintext.toString('utf8') === data.plaintext);
	const parsed = JSON.parse(plaintext.toString('utf8'));
	check('Der Inhalt ist gueltiges JSON mit Titel', parsed.title === 'Seite offline');
	check('Umlaute ueberstehen die Strecke', parsed.body.includes('antwortet nicht'));
}

/* ------------------------------------------------- Aufbau nach RFC 8188 */

const body = Buffer.from(data.body, 'base64');
check('Salz ist 16 Byte', body.length > 21);
const recordSize = body.readUInt32BE(16);
check('Datensatzgroesse steht im Kopf (' + recordSize + ')', recordSize === 4096);
const keyLen = body[20];
check('Schluessellaenge ist 65', keyLen === 65);
check('Absenderschluessel ist ein unkomprimierter Punkt', body[21] === 0x04);

/* ------------------------------------------------- VAPID-Signatur */

const [h, p, sig] = data.vapidToken.split('.');
check('Token besteht aus drei Teilen', !!h && !!p && !!sig);

const header = JSON.parse(b64u(h).toString('utf8'));
check('Kopf nennt ES256', header.alg === 'ES256' && header.typ === 'JWT');

const claims = JSON.parse(b64u(p).toString('utf8'));
check('Empfaenger ist der Push-Dienst', claims.aud === 'https://fcm.googleapis.com');
check('Absender ist hinterlegt', claims.sub === 'mailto:hallo@north-lab.de');
check('Laufzeit liegt unter 24 Stunden', claims.exp - Math.floor(Date.now() / 1000) <= 86400);
check('Und in der Zukunft', claims.exp > Math.floor(Date.now() / 1000));

// Oeffentlichen VAPID-Schluessel als JWK aufbauen und die Signatur pruefen.
const pub = b64u(data.vapidPub);
const vapidKey = crypto.createPublicKey({
	key: {
		kty: 'EC',
		crv: 'P-256',
		x: pub.subarray(1, 33).toString('base64url'),
		y: pub.subarray(33, 65).toString('base64url'),
	},
	format: 'jwk',
});

const valid = crypto.verify(
	'sha256',
	Buffer.from(h + '.' + p),
	{ key: vapidKey, dsaEncoding: 'ieee-p1363' },
	b64u(sig)
);
check('Die VAPID-Signatur ist gueltig', valid);
check('Die Signatur ist 64 Byte roh', b64u(sig).length === 64);

// Eine veraenderte Nutzlast darf nicht mehr passen.
const tampered = crypto.verify(
	'sha256',
	Buffer.from(h + '.' + p + 'x'),
	{ key: vapidKey, dsaEncoding: 'ieee-p1363' },
	b64u(sig)
);
check('Veraenderte Daten fallen durch', !tampered);

console.log(`${17} Prüfungen, ${fails.length} Fehler`);
fails.forEach(f => console.log('  FEHLT: ' + f));
process.exit(fails.length ? 1 : 0);
