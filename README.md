# NorthLab Control Panel

Zentrale Verwaltung aller betreuten WordPress-Seiten — selbst gehostet auf dem eigenen Server,
ohne Cloud-Dienst dazwischen. Funktional an MainWP orientiert, aber als eigenständige
PHP-Anwendung statt als WordPress-Plugin.

Zwei Teile:

| Teil | Was es ist | Wo es läuft |
|---|---|---|
| **`dashboard/`** | Das Control Panel | Auf dem Hauptserver der Agentur, unter einer eigenen Domain |
| **`dashboard/resources/child-plugin/`** | **NorthLab Child** — WordPress-Plugin | Auf jeder betreuten Kundenseite |

Das Panel stellt das Child-Plugin unter *Plugin-Download* als fertige ZIP-Datei bereit.
Ersteller des Plugins ist **NorthLab**.

---

## Funktionsumfang

**Seitenverwaltung**
- Beliebig viele WordPress-Installationen verbinden, nach Kunden und Tags gruppieren
- Vollständiger Statusbericht je Seite: WordPress-, PHP- und MySQL-Version, Speicher, Datenbank- und Upload-Größe, freier Plattenplatz, Cron- und Cache-Zustand
- Inhalts- und Benutzerzahlen, installierte Plugins und Themes mit Aktiv-Status
- Seiten pausieren, Notizen hinterlegen, Verbindung erneuern
- **Seiten ohne Child-Plugin** (Shopify, Wix, Squarespace, Webflow, fremdgehostete Installationen) lassen sich als reine Überwachung aufnehmen: Erreichbarkeitsprüfung, Störungsprotokoll, Kundenzuordnung, Tags, Berichte und Webhooks. Updates, Plugin- und Theme-Listen, Sicherheitsprüfung, Wartung und Backups brauchen das Plugin und entfallen dort

**Als Anwendung installierbar**
- Manifest, Symbole und Service Worker: das Panel lässt sich auf Handy und Desktop installieren — eigenes Symbol, Vollbild ohne Browserleiste, eigener Eintrag im App-Umschalter
- Gecacht wird ausschließlich die Hülle (CSS, Skripte, Symbole, Offline-Seite). Fertige Seiten landen bewusst nie im Cache: sie hängen an der Anmeldung, und auf einem geteilten Gerät wäre das die Übersicht eines fremden Kontos. Ohne Netz erscheint eine Hinweisseite statt eines veralteten Dashboards
- Sicherheitsabstände moderner Telefone werden im Vollbild berücksichtigt

**Push-Meldungen aufs Handy**
- Web Push nach RFC 8291 und RFC 8292, ohne Fremdbibliothek — nur mit dem OpenSSL, das PHP ohnehin mitbringt
- Der Inhalt ist für genau ein Gerät verschlüsselt; der Push-Dienst von Google, Mozilla oder Apple leitet nur weiter und kann nicht mitlesen
- Frei wählbar, welche Ereignisse klingeln — getrennt von den E-Mail-Hinweisen
- Eine Meldung geht nur an Konten, die die betroffene Seite auch im Panel sehen dürfen
- Geräte, die dauerhaft nicht mehr antworten, trägt das Panel selbst aus
- Auf dem iPhone erst nach Installation über „Zum Home-Bildschirm" — eine Vorgabe von Apple

**Benutzer der Kundenseiten**
- WordPress-Benutzer je Seite anlegen, Rolle setzen, löschen — ohne sich dort anzumelden
- **Befristete Konten**: Zugang für 1 Stunde bis 30 Tage, danach löscht die Kundenseite ihn selbst und übergibt vorhandene Inhalte an den ältesten Administrator. Der Aufräumlauf hängt am WP-Cron und läuft zusätzlich bei jeder Panel-Anfrage, damit auch Seiten mit abgeschaltetem WP-Cron zuverlässig aufräumen
- Passwort direkt neu setzen (wird genau einmal angezeigt und nirgends gespeichert) oder WordPress eine Zurücksetz-Mail schicken lassen; offene Sitzungen des Kontos werden dabei beendet
- **Ein-Klick-Anmeldung** ins WP-Backend: die Kundenseite gibt eine Adresse aus, die 90 Sekunden gilt und beim ersten Aufruf verbraucht ist. Auf der Kundenseite abschaltbar

**Updates des Child-Plugins selbst**
- Das Panel ist die Update-Quelle: die Kundenseite fragt es nach der aktuellen Version und hängt das Ergebnis in denselben Transient, aus dem WordPress seine Update-Liste liest. Das Update erscheint dadurch im Backend der Kundenseite, in der Update-Zentrale des Panels und in den automatischen Updates — ohne zweite Update-Bahn
- Das Manifest ist mit dem privaten Schlüssel der jeweiligen Verbindung signiert; die Kundenseite prüft es mit dem öffentlichen Schlüssel, den sie beim Verbinden bekommen hat
- Im signierten Manifest steht die SHA-256-Summe des Pakets. Das heruntergeladene ZIP wird dagegen geprüft, bevor WordPress es auspackt — ein unterwegs ausgetauschtes Paket fällt auf
- Das Paket muss vom verbundenen Panel kommen, über dasselbe Protokoll wie dessen Adresse; ein signiertes Manifest, das woandershin zeigt, wird verworfen
- Direkt anstoßbar pro Seite oder als Sammelaktion, wenn es nicht bis zum nächsten Prüflauf warten soll
- Auf der Kundenseite abschaltbar

**Agentur-Branding auf den Kundenseiten**
- **Support-Leiste im Backend**: Logo, Ansprache und die drei Kontaktwege (Website, E-Mail, Telefon) oben im Adminbereich, vor allen WordPress-Hinweisen. Sichtbar für alle angemeldeten Benutzer, ab Redakteur oder nur für Administratoren
- **Leiste über der Anmeldeseite**: schmaler Streifen mit Logo und „Betreut von …"
- **Kundenlogo auf der Anmeldeseite** statt des WordPress-Logos, mit Höhe und Ziel-Link — steht pro Seite, weil jeder Kunde ein eigenes hat
- Logo, Kontaktwege, Text und Farben stehen zentral in den Einstellungen; ein- und ausgeschaltet wird pro Seite oder als Sammelaktion
- Telefonnummern werden für die Anzeige und für den Wähl-Link getrennt aufbereitet: `+49 (0)30 …` wird zu `tel:+4930…`, denn die 0 in Klammern entfällt beim internationalen Wählen
- Der Kunde kann das Branding auf seiner Seite abschalten; das Panel meldet dann, dass es dort gesperrt ist

**Wartungsmodus**
- Aus der Ferne ein- und ausschalten, einzeln oder für mehrere Seiten auf einmal
- Zeitlich befristet (15 Minuten bis 24 Stunden) oder bis auf Widerruf; abgelaufene Fenster beendet die Kundenseite selbst
- Gebrandete Seite mit eigener Farbe, Logo, Überschrift und Text — zentral in den Einstellungen hinterlegt
- Antwortet mit `503` und `Retry-After`, damit Suchmaschinen die Seite nicht aus dem Index nehmen
- Angemeldete Redakteure, `wp-admin`, `wp-login.php` und die REST-API bleiben erreichbar
- Wird das Child-Plugin deaktiviert, schaltet es den Wartungsmodus selbst ab — eine Seite kann nicht versehentlich gesperrt bleiben

**Updates**
- Update-Zentrale über alle Seiten hinweg, gruppiert nach Erweiterung oder nach Seite
- Einzeln, gruppenweise („WooCommerce auf allen 14 Seiten"), pro Seite oder alles auf einmal
- Core-, Plugin-, Theme- und Übersetzungs-Updates
- Automatische Updates mit Richtlinie (aus / nur Minor+Patch / alles), Ausnahmeliste mit Wildcards und optionalem Wartungsfenster
- Aktive Plugins bleiben nach dem Update aktiv; Ergebnis jedes Einzel-Updates wird protokolliert

**Uptime**
- Eigene Erreichbarkeitsprüfung im einstellbaren Takt
- **Eingehende Monitoring-Webhooks**: jede Seite hat eine eigene Empfangs-URL. Automatisch erkannt werden UptimeRobot, Better Stack, Uptime Kuma, Pingdom, StatusCake, HetrixTools und generisches JSON (`{"status":"up"}`)
- Verfügbarkeit in Prozent, Ausfallzeit, Störungsliste und Tagesverlauf je Seite
- Optionale HMAC-Signaturprüfung eingehender Meldungen

**Anbindung ans eigene Kundenportal**
- **Abruf-API mit Token** (nur lesend): `/api/v1/export?days=30` liefert den kompletten Stand in einem Aufruf — Kunden, Seiten, Verfügbarkeit, Störungen, eingespielte und offene Updates, Sicherheitsbefunde. Genau die Daten, aus denen auch die Panel-Berichte entstehen
- dazu `/api/v1/sites` und `/api/v1/updates`, filterbar nach Kunde, Seite und Typ
- Token als `Authorization: Bearer …` oder `?token=…`

**Ausgehende Webhooks**
- Beliebig viele Endpunkte (Slack, n8n, Make, Zapier, eigene Systeme)
- Siebzehn abonnierbare Ereignisse — Seite offline/online, Sync fehlgeschlagen, Updates verfügbar, Update eingespielt/fehlgeschlagen, Sicherheitsbewertung gefallen, Wartung fertig, Wartungsmodus an/aus, Ein-Klick-Anmeldung benutzt, Sicherung fertig/fehlgeschlagen, Bericht erstellt, Seite verbunden/entfernt, täglicher Gesamtstand
- HMAC-SHA256-signiert, Warteschlange mit fünf Wiederholungen und wachsendem Abstand, vollständiges Zustellungsprotokoll mit manueller Wiedervorlage
- Endpunkte lassen sich auf einen Kunden einschränken

**Kundenberichte**
- Automatisch wöchentlich, monatlich oder quartalsweise je Kunde
- Enthält eingespielte Updates, Verfügbarkeit, Störungen, durchgeführte Wartung, offene Sicherheitspunkte
- Versand per E-Mail (fertiges HTML) **und/oder als signierter Webhook mit allen Kennzahlen als JSON**
- Jederzeit manuell erzeugbar, im Browser ansehbar und als HTML herunterladbar

**Sicherungen**
- Nächtliche Sicherung aller Seiten auf einen entfernten Speicher (Hetzner Storage Box, S3, jedes restic-Ziel)
- Läuft **ohne SSH-Zugang zu den Kundenseiten**: das Child-Plugin liefert Dateiliste und Datenbank über die signierte Verbindung, das Panel hält je Seite einen Spiegel und holt nur Geändertes — verglichen über Grösse und Änderungszeit
- Datenbank-Export läuft auf der Kundenseite in Etappen, damit kein Aufruf ins Zeitlimit gerät
- restic übernimmt Deduplizierung, Verschlüsselung und Aufbewahrung (täglich/wöchentlich/monatlich einstellbar)
- Wiederherstellung über die restic-Kommandozeile; bewusst kein Knopf im Panel

**Wartung und Sicherheit**
- Elf Wartungsaufgaben je Seite oder als Sammelaktion: Revisionen, Auto-Entwürfe, Papierkorb, Spam, abgelaufene Transients, verwaiste Meta-Daten, Tabellen optimieren, Cache leeren, Permalinks neu schreiben
- Vierzehn Sicherheitsprüfungen mit Punktwert 0–100; einige Punkte lassen sich per Klick beheben
- Plugins und Themes aktivieren, deaktivieren, löschen und aus dem wordpress.org-Verzeichnis installieren

**Benutzer und Zugriff**
- Mehrbenutzerfähig mit drei Rollen (Administrator, Mitarbeiter, Nur Lesen)
- **Zwei-Faktor-Anmeldung** (TOTP nach RFC 6238) mit QR-Code, acht Ersatzcodes und Rücksetzung durch einen Administrator
- **Seitenzuordnung je Konto**: ein Mitarbeiter sieht entweder alle Seiten oder nur die ihm zugewiesenen — die Einschränkung greift in Übersicht, Seitenliste, Updates, Uptime, Kunden, Berichten und Protokoll
- Anmeldung mit Sperre nach Fehlversuchen, Sitzungsverwaltung, CSRF-Schutz auf allen Formularen
- Durchsuchbares Aktivitätsprotokoll, einstellbare Aufbewahrungsfristen
- Lese-API für eigene Integrationen (`/api/…`)

---

## Sicherheitsmodell

Beim Verbinden erzeugt das Panel ein **RSA-2048-Schlüsselpaar pro Seite**. Der öffentliche
Schlüssel geht an das Child-Plugin, der private bleibt im Panel — verschlüsselt mit
`app.key` aus der `config.php` (XSalsa20-Poly1305 über libsodium, ersatzweise AES-256-CBC
mit HMAC).

Jede Anfrage an eine Kundenseite trägt eine Signatur über

```
connection_id \n timestamp \n nonce \n METHODE \n route \n sha256(body)
```

Das Child prüft die Signatur, verwirft Anfragen mit mehr als fünf Minuten Zeitversatz und
lehnt bereits gesehene Nonces ab. Es gibt keinen gemeinsamen Schlüssel über mehrere Seiten
hinweg und kein Passwort, das über die Leitung geht.

Der erste Kontakt läuft über einen Einmal-Code, den das Child-Plugin erzeugt: 32 Hex-Zeichen,
nur als Hash gespeichert, 60 Minuten gültig, nach einmaliger Verwendung verbraucht.

Die Zwei-Faktor-Anmeldung arbeitet mit zeitbasierten Einmalpasswörtern (SHA1, 30 Sekunden,
sechs Stellen) — kompatibel mit Aegis, 2FAS, Google Authenticator, 1Password und Bitwarden.
Der QR-Code wird im Browser aus einer mitgelieferten Bibliothek erzeugt, das Geheimnis
verlässt den eigenen Server also nicht. Gespeichert wird es verschlüsselt, die Ersatzcodes
nur als Hash.

Auf der Kundenseite lässt sich je Installation einschränken, was das Panel darf
(Updates, Installationen, Benutzerverwaltung, Wartung, Inhalte), dazu eine IP-Allowlist
mit CIDR-Unterstützung und ein HTTPS-Zwang.

> **Sichere `app.key` zusammen mit der Datenbank.** Geht der Schlüssel verloren, lassen sich
> die privaten Schlüssel nicht mehr entschlüsseln und alle Seiten müssen neu verbunden werden.

---

## Installation

Siehe **[INSTALL.md](INSTALL.md)** für die ausführliche Anleitung. Kurzfassung:

1. Inhalt von `dashboard/` auf den Server laden, Document-Root auf **`dashboard/public/`** zeigen lassen
2. `https://panel.deine-domain.de/install` im Browser öffnen und den Assistenten durchlaufen
3. Cron einrichten: `* * * * * /usr/bin/php /pfad/zu/dashboard/bin/cron.php`
4. Im Panel unter *Plugin-Download* das Child-Plugin holen und auf der ersten Kundenseite installieren

**Voraussetzungen:** PHP 8.1+ mit `pdo_mysql`, `openssl`, `curl`, `mbstring`, `json`
(empfohlen zusätzlich `sodium` und `zip`), MySQL 5.7+ oder MariaDB 10.3+.
Kein Composer, keine externen Abhängigkeiten.

---

## Verzeichnisstruktur

```
dashboard/
  public/                     Document-Root — index.php, CSS, JS
  src/
    Core/                     Config, Datenbank, Migration, Auth, Router, View, Crypto, HTTP, Mail
    Repository/               Datenzugriff je Tabelle
    Service/                  Fachlogik: Sync, Updates, Uptime, Webhooks, Berichte, Zeitplaner
    Controller/               Ein Controller je Bereich
  views/                      PHP-Templates (Layouts, Seiten, Berichtsvorlage)
  bin/cron.php                Zeitplaner-Einstiegspunkt
  resources/child-plugin/     Quelle des WordPress-Plugins, wird auf Abruf gezippt
  storage/                    Logs, Cache, erzeugte ZIPs — muss beschreibbar sein
  config.php                  Wird vom Installer erzeugt, nicht im Repository
```

---

## Zeitplaner

Ein einziger Cron-Eintrag genügt; die Fälligkeit der einzelnen Aufgaben verwaltet die
Anwendung selbst.

| Aufgabe | Takt (Standard) |
|---|---|
| Webhook-Warteschlange | jede Minute |
| Erreichbarkeitsprüfung | alle 5 Minuten |
| Seiten synchronisieren | alle 15 Minuten |
| Automatische Updates | stündlich |
| Fällige Berichte | stündlich |
| Aufräumen | täglich |

```bash
php bin/cron.php            # alle fälligen Aufgaben
php bin/cron.php --list     # Zeitplan anzeigen
php bin/cron.php sync -v    # eine Aufgabe erzwingen
```

Ohne Cron-Zugang gibt es unter *Einstellungen → Automatisierung* eine tokengeschützte URL,
die ein externer Dienst minütlich abrufen kann.

---

## Webhook-Format

Ausgehende Zustellungen sind `POST` mit JSON-Body und diesen Headern:

```
X-NorthLab-Event:      site.offline
X-NorthLab-Delivery:   <eindeutige ID des Versuchs>
X-NorthLab-Timestamp:  <Unix-Zeit>
X-NorthLab-Signature:  sha256=<HMAC>
```

Der HMAC wird mit dem Secret des Endpunkts über `"<timestamp>.<body>"` gebildet:

```php
$erwartet = hash_hmac('sha256', $timestamp . '.' . $rohBody, $secret);
$gueltig  = hash_equals($erwartet, str_replace('sha256=', '', $signaturHeader));
```

Beispiel-Body:

```json
{
  "event": "site.offline",
  "occurred_at": "2026-09-13T14:22:05+00:00",
  "agency": "NorthLab",
  "dashboard_url": "https://panel.northlab.de",
  "message": "\"Kundenseite\" ist nicht erreichbar.",
  "data": { "source": "uptimerobot", "http_code": 503, "reason": "Service Unavailable" },
  "site": { "id": 7, "name": "Kundenseite", "url": "https://kunde.de", "client_id": 2 }
}
```

## Lizenz

GPL-3.0-or-later — dieselbe Lizenz wie WordPress, damit das Child-Plugin weitergegeben werden darf.
