# Installation

## 1. Voraussetzungen prüfen

| | Minimum | Empfohlen |
|---|---|---|
| PHP | 8.1 | 8.3 |
| PHP-Erweiterungen | `pdo_mysql`, `openssl`, `curl`, `mbstring`, `json` | zusätzlich `sodium`, `zip` |
| Datenbank | MySQL 5.7 / MariaDB 10.3 | MariaDB 11 |
| Sonstiges | Cron-Zugang, eigene (Sub-)Domain mit TLS | |

`sodium` verbessert die Verschlüsselung der privaten Schlüssel, `zip` beschleunigt den
Plugin-Download. Beides ist optional — es gibt für jeden Fall einen Ersatzweg.

Composer wird nicht benötigt.

## 2. Dateien hochladen

Den Inhalt des Ordners `dashboard/` auf den Server legen, zum Beispiel nach
`/var/www/northlab/`. Anschließend so:

```
/var/www/northlab/
  public/      <- hierauf zeigt der Webserver
  src/
  views/
  bin/
  resources/
  storage/
```

Schreibrechte:

```bash
cd /var/www/northlab
chown -R www-data:www-data storage
chmod -R 750 storage
chmod 750 .            # damit der Installer config.php anlegen kann
```

> **Wichtig:** Der Document-Root muss auf `public/` zeigen, nicht auf das Anwendungsverzeichnis.
> Sonst wären `config.php`, `storage/` und der Quellcode über den Browser erreichbar.

## 3. Webserver konfigurieren

### nginx

`dashboard/nginx.conf.example` als Vorlage verwenden. Kern davon:

```nginx
root /var/www/northlab/public;
index index.php;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_read_timeout 600;   # Updates auf vielen Seiten dauern
}
```

### Apache

`public/.htaccess` liegt bei und regelt Rewriting und Sicherheits-Header.
Nötig sind `mod_rewrite` und `AllowOverride All` für das Verzeichnis.

```apache
<VirtualHost *:443>
    ServerName panel.deine-domain.de
    DocumentRoot /var/www/northlab/public

    <Directory /var/www/northlab/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

TLS-Zertifikat einrichten (Let's Encrypt genügt). Das Panel verwaltet Zugangsdaten —
es sollte nicht ohne HTTPS laufen.

## 4. Datenbank anlegen

```sql
CREATE DATABASE northlab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'northlab'@'localhost' IDENTIFIED BY 'ein-langes-zufaelliges-passwort';
GRANT ALL PRIVILEGES ON northlab.* TO 'northlab'@'localhost';
FLUSH PRIVILEGES;
```

Die Tabellen legt der Installer selbst an.

## 5. Installer aufrufen

`https://panel.deine-domain.de/install` im Browser öffnen.

Der Assistent prüft zuerst die Voraussetzungen und fragt dann ab:

- **Datenbank** — Host, Port, Name, Benutzer, Passwort, Tabellenpräfix
- **Panel** — öffentliche URL (ohne Slash am Ende), Agenturname, Zeitzone
- **Administratorkonto** — Name, E-Mail, Passwort (mindestens 10 Zeichen, Buchstaben und Ziffern)

Die Verbindung wird vor dem Schreiben getestet; bei Fehlern wird nichts angelegt.

Danach schreibt der Installer `config.php`, legt das Schema an und erstellt das erste Konto.

### Danach absichern

```bash
chmod 640 /var/www/northlab/config.php
chmod 550 /var/www/northlab          # Schreibrecht auf das Verzeichnis wieder entziehen
```

Der Installer ist ab jetzt nicht mehr erreichbar — sobald `config.php` existiert, führt
`/install` nicht mehr weiter.

> **Führe die Installation direkt nach dem Hochladen durch.** Solange keine `config.php`
> existiert, kann jeder, der die URL kennt, das Panel selbst einrichten. Das gilt für jeden
> Web-Installer, WordPress eingeschlossen.

## 6. Zeitplaner einrichten

Ohne ihn laufen keine Syncs, keine Uptime-Prüfungen, keine Webhooks und keine Berichte.
Das Panel zeigt oben eine Warnung, solange der Cron nicht läuft.

```bash
crontab -u www-data -e
```

```cron
* * * * * /usr/bin/php /var/www/northlab/bin/cron.php >> /var/www/northlab/storage/logs/cron.log 2>&1
```

Prüfen:

```bash
sudo -u www-data php /var/www/northlab/bin/cron.php --list
sudo -u www-data php /var/www/northlab/bin/cron.php webhooks --verbose
```

**Ohne Cron-Zugang** (Shared Hosting): unter *Einstellungen → Automatisierung* steht eine
tokengeschützte URL, die ein externer Dienst minütlich abrufen kann.

## 7. Erste Kundenseite verbinden

1. Im Panel auf **Plugin-Download** gehen und `northlab-child-1.0.0.zip` herunterladen
2. Auf der Kundenseite: *Plugins → Installieren → Plugin hochladen*, ZIP auswählen, aktivieren
3. Dort *Einstellungen → NorthLab* öffnen und **Verbindungscode erzeugen** klicken
   (der Code wird nur einmal angezeigt und ist 60 Minuten gültig)
4. Im Panel: *Seiten → Seite hinzufügen*, URL und Code eintragen

Das Panel erzeugt dabei ein RSA-Schlüsselpaar für diese Seite, übergibt den öffentlichen
Teil an das Plugin und zieht sofort den ersten Statusbericht.

Optional auf der Kundenseite unter *Einstellungen → NorthLab* einschränken, was das Panel
darf, und die IP dieses Servers in die Allowlist eintragen.

## 8. Einrichtung abschließen

Empfohlene Reihenfolge im Panel:

1. **Einstellungen → Allgemein** — Agenturname, Absender-Adresse, Logo, Akzentfarbe
2. **Kunden** — Kunden anlegen und Seiten zuordnen; Berichtsrhythmus und Empfänger setzen
3. **Einstellungen → Automatisierung** — Sync-Takt und Auto-Update-Richtlinie festlegen
4. **Webhooks** — Endpunkte für Slack/n8n/eigene Systeme anlegen und Testzustellung auslösen
5. **Benutzer** — Konten für das Team, Rolle *Mitarbeiter* reicht für die tägliche Arbeit

---

## Betrieb

### Sicherung

Zu sichern sind **die Datenbank** und **`config.php`**. Ohne den Wert `app.key` aus der
`config.php` lassen sich die privaten Schlüssel in der Datenbank nicht mehr entschlüsseln —
dann müssen alle Kundenseiten neu verbunden werden.

```bash
mysqldump -u northlab -p northlab | gzip > northlab-$(date +%F).sql.gz
cp /var/www/northlab/config.php /sicher/northlab-config-$(date +%F).php
```

### Aktualisieren

```bash
cd /var/www/northlab
# Dateien austauschen, config.php und storage/ unangetastet lassen
sudo -u www-data php bin/cron.php --list   # führt fällige Schema-Migrationen aus
```

Schema-Änderungen laufen beim ersten Aufruf automatisch.

### Fehlersuche

| Symptom | Ursache und Abhilfe |
|---|---|
| „Der Zeitplaner läuft nicht" | Cron fehlt oder scheitert — `storage/logs/cron.log` prüfen |
| „Endpunkt nicht gefunden (HTTP 404)" | Child-Plugin nicht aktiv oder REST-API der Kundenseite blockiert (Security-Plugin, Firewall) |
| „Signatur abgelehnt (HTTP 401)" | Serverzeiten weichen um mehr als 5 Minuten ab — NTP prüfen |
| „Child-Plugin kennt diese Verbindung nicht mehr" | Plugin wurde neu installiert — *Einstellungen → Verbindung erneuern* mit neuem Code |
| „Privater Schlüssel konnte nicht entschlüsselt werden" | `app.key` wurde geändert — betroffene Seiten neu verbinden |
| Updates laufen in einen Timeout | `fastcgi_read_timeout` und `max_execution_time` erhöhen; `http.update_timeout` in der `config.php` anpassen |
| Interner Fehler ohne Details | `'debug' => true` in `config.php` setzen, `storage/logs/app.log` lesen, danach wieder abschalten |

### Konfiguration von Hand

`config.php` lässt sich jederzeit direkt bearbeiten; `dashboard/config.example.php`
dokumentiert alle Werte. Interessant im laufenden Betrieb:

```php
'http' => array(
    'timeout'        => 60,    // normale Anfragen an Kundenseiten
    'update_timeout' => 300,   // Updates und Wartung
    'verify_ssl'     => true,  // global; je Seite zusätzlich abschaltbar
),
'mail' => array(
    'transport' => 'smtp',     // 'mail' oder 'smtp'
    'smtp'      => array( 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'user' => '…', 'pass' => '…' ),
),
```
