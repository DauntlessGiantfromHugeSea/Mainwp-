# Prüfläufe

```sh
sh dashboard/tests/run.sh            # alles, was auf diesem Rechner geht
sh dashboard/tests/run.sh backup     # nur Läufe, deren Name "backup" enthält
```

Jeder Lauf schreibt am Ende `N Prüfungen, M Fehler`. Der Starter zählt
zusammen und listet auf, was er überspringen musste — **ein übersprungener
Lauf ist kein bestandener.**

## Was wofür nötig ist

| Voraussetzung | Betrifft |
|---|---|
| `php` | alle |
| MariaDB auf `127.0.0.1:3306`, Benutzer `nl`/`nlpass` | `setting`, `update`, `paneldump`, `backup-e2e`, `backup-setup` |
| `restic` | `backup-e2e`, `backup-setup` |
| SSH-Server auf `127.0.0.1:2223` | `backup-setup` |
| `node` mit `playwright` | alle Browser-Läufe |

Zugangsdaten der Datenbank lassen sich über `NL_TEST_DB_USER` und
`NL_TEST_DB_PASS` setzen, der Browser über `NL_CHROMIUM`, und eine bestimmte
restic-Fassung über `NL_TEST_RESTIC` — damit lässt sich gegen genau die
Version prüfen, die auf dem Zielserver liegt.

## Die Läufe

**Ohne alles.** `compat` hält das Child-Plugin auf PHP 7.4 lauffähig (es
sucht nach Funktionen, die es dort noch nicht gibt). `child`, `branding`,
`connect`, `selfupdate` prüfen das Plugin, `feature` und `childversion` die
Fähigkeitsabfrage, `icon` den Symbolbau, `render` und `render-backup` die
Ansichten, `backup` die restic-Hülle, `router` das Routing, `asset` die
Cache-Kennungen, `push-send` die Verschlüsselung der Push-Meldungen gegen
einen nachgebauten Push-Dienst.

**Mit Datenbank.** `update` lässt `bin/update.sh` gegen einen nachgebauten
Klon und eine nachgebaute Installation laufen und prüft, dass Laufzeitdaten
unter `storage/` das Update überleben — SSH-Schlüssel, Spiegel und Logo.
`setting` prüft, dass der Zwischenspeicher der
Einstellungen nie ein unvollständiges Bild liefert. `paneldump` exportiert die Panel-Datenbank und spielt sie
in eine zweite Datenbank zurück — Apostrophe, Backslashes, Steuerzeichen,
Emoji und NULL müssen unverändert ankommen. `backup-e2e` legt ein echtes
restic-Repository an, sichert, stellt wieder her und prüft den Zeitplan.

**Mit SSH-Server.** `backup-setup` fährt die automatische Einrichtung gegen
einen echten SSH-Server: Schlüssel erzeugen, Wirtsschlüssel holen, Schlüssel
per Passwort ablegen, Repository anlegen. Dazu gehören die Gegenproben —
falsches Passwort, fehlender Schlüssel, untergeschobener Wirtsschlüssel.

Einen passenden Server einrichten:

```sh
sudo useradd -m boxuser && echo 'boxuser:GeheimesTestpasswort' | sudo chpasswd
sudo tee /etc/ssh/sshd_test.conf > /dev/null <<'CONF'
Port 2223
ListenAddress 127.0.0.1
HostKey /etc/ssh/ssh_host_ed25519_key
PasswordAuthentication yes
PubkeyAuthentication yes
UsePAM no
Subsystem sftp internal-sftp
CONF
sudo mkdir -p /run/sshd && sudo /usr/sbin/sshd -f /etc/ssh/sshd_test.conf
```

Das Passwort muss zu dem in `backup-setup.php` passen.

**Im Browser.** Playwright gegen Chromium: `check` prüft das Formular zum
Hinzufügen einer Seite, `mmode-check` die Wartungsseite, `branding-check` die
Leisten auf der Kundenseite, `push-check` und `push-ui` die Push-Meldungen,
`icon-check` das im Browser gezeichnete Symbol, `pwa-check` Manifest, Service
Worker und Offline-Seite.

`playwright` liegt nicht im Repository:

```sh
cd dashboard/tests && npm install playwright http_ece
```

## Hilfsserver

`servers/panel-router.php` liefert Manifest, Service Worker und die
statischen Dateien so aus wie das Panel — ohne Datenbank.
`servers/push-router.php` spielt einen Push-Dienst und prüft dabei, was ihm
auf die Leitung gelegt wird. Beide startet `run.sh` selbst.
