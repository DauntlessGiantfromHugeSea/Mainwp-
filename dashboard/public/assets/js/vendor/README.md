# Fremdcode

## qrcode.js, qrcode-utf8.js

QR Code Generator for JavaScript — Kazuhiko Arase, MIT-Lizenz.
Unverändert übernommen aus dem npm-Paket `qrcode-generator` 2.0.4
(`dist/qrcode.js` und `dist/qrcode_UTF8.js`).

Quelle: https://github.com/kazuhikoarase/qrcode-generator

Wird ausschließlich für die Anzeige des 2FA-QR-Codes verwendet. Der Code liegt
mit im Repository, damit die Anmeldeseite ohne externes CDN auskommt und die
Content-Security-Policy `script-src 'self'` eingehalten wird — das TOTP-Geheimnis
verlässt den eigenen Server nicht.
