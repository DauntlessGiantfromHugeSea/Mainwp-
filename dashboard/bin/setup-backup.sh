#!/bin/sh
#
# Bereitet den Panel-Server fuer die Sicherung vor.
#
# Erledigt genau das, was ein Webserver-Prozess nicht darf: Pakete nachinstallieren
# und ein Verzeichnis mit den richtigen Rechten anlegen. Alles Weitere macht das
# Panel anschliessend selbst unter "Sicherungen".
#
# Aufruf auf dem Panel-Server:
#   sudo sh dashboard/bin/setup-backup.sh
#
set -eu

sagen() { printf '%s\n' "$*"; }
fehler() { printf 'FEHLER: %s\n' "$*" >&2; exit 1; }

# --- Wo liegt das Panel? -----------------------------------------------------
# Das Skript liegt in dashboard/bin/, die Wurzel ist also zwei Ebenen darueber.
SKRIPT_DIR=$( cd "$( dirname "$0" )" && pwd )
PANEL=$( cd "$SKRIPT_DIR/.." && pwd )

[ -f "$PANEL/public/index.php" ] || fehler "Das sieht nicht nach dem Panel aus: $PANEL"

sagen "Panel gefunden: $PANEL"

# --- Wem gehoert es? ---------------------------------------------------------
# Unter diesem Benutzer laufen Webserver und Zeitplaner. Der Schluessel muss ihm
# gehoeren, sonst kommt nachts niemand daran.
BESITZER=$( stat -c '%U' "$PANEL/storage" 2>/dev/null || stat -f '%Su' "$PANEL/storage" 2>/dev/null || echo '' )

[ -n "$BESITZER" ] || fehler "Der Besitzer von $PANEL/storage liess sich nicht ermitteln."

sagen "Betriebsbenutzer: $BESITZER"

# --- Pakete ------------------------------------------------------------------
fehlt=''
command -v restic >/dev/null 2>&1 || fehlt="$fehlt restic"
command -v ssh-copy-id >/dev/null 2>&1 || fehlt="$fehlt openssh-client"

if [ -n "$fehlt" ]; then
	sagen "Fehlt noch:$fehlt — wird nachinstalliert."

	if command -v apt-get >/dev/null 2>&1; then
		DEBIAN_FRONTEND=noninteractive apt-get update -qq
		# shellcheck disable=SC2086
		DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends $fehlt
	elif command -v dnf >/dev/null 2>&1; then
		# shellcheck disable=SC2086
		dnf install -y $fehlt
	elif command -v yum >/dev/null 2>&1; then
		# shellcheck disable=SC2086
		yum install -y $fehlt
	else
		fehler "Kein bekannter Paketmanager. Bitte von Hand installieren:$fehlt"
	fi
else
	sagen "restic und openssh-client sind schon da."
fi

command -v restic >/dev/null 2>&1 || fehler "restic liess sich nicht installieren."

sagen "restic: $( restic version | head -1 )"

# --- Arbeitsverzeichnis ------------------------------------------------------
ARBEIT="$PANEL/storage/restic"

mkdir -p "$ARBEIT/.ssh" "$ARBEIT/cache"
chown -R "$BESITZER" "$ARBEIT"
chmod 700 "$ARBEIT" "$ARBEIT/.ssh" "$ARBEIT/cache"

sagen "Arbeitsverzeichnis: $ARBEIT (gehoert $BESITZER)"

# Der Spiegel wird gross — er gehoert demselben Benutzer.
mkdir -p "$PANEL/storage/backups"
chown "$BESITZER" "$PANEL/storage/backups"
chmod 750 "$PANEL/storage/backups"

PLATZ=$( df -h "$PANEL/storage" | awk 'NR==2 {print $4}' )

sagen ""
sagen "Fertig. Freier Platz unter storage/: $PLATZ"
sagen ""
sagen "Weiter im Panel unter \"Sicherungen\":"
sagen "  1. Speicherart waehlen und Benutzer der Storage Box eintragen"
sagen "  2. Ein Repository-Passwort setzen (Passwortmanager!)"
sagen "  3. Unter \"Einrichten\" das Passwort der Storage Box eingeben"
sagen ""
sagen "Den Rest macht das Panel selbst."
