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

sagen()  { printf '%s\n' "$*"; }
rot()    { printf '\033[31m%s\033[0m\n' "$*" >&2; }
fehler() { rot "FEHLER: $*"; exit 1; }

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

# Unvorhersehbarer Name: ein fester Pfad in /tmp liesse sich als root
# ueber einen vorher angelegten Symlink missbrauchen.
APT_LOG=$( mktemp )
trap 'rm -f "$APT_LOG"' EXIT

if [ -n "$fehlt" ]; then
	sagen "Fehlt noch:$fehlt — wird nachinstalliert."

	if command -v apt-get >/dev/null 2>&1; then
		# Eine einzelne kaputte Fremdquelle (ein falscher Schluesselpfad reicht)
		# laesst apt-get update scheitern. Das darf die Installation nicht
		# aufhalten: die Paketlisten der Distribution sind dann trotzdem da.
		if ! DEBIAN_FRONTEND=noninteractive apt-get update -qq > "$APT_LOG" 2>&1; then
			sagen ""
			sagen "Hinweis: apt-get update meldet Fehler. Betroffene Quelle(n):"
			grep -E '^(E|W):' "$APT_LOG" | sed 's/^/    /' | head -6
			sagen "    (wird uebergangen — die Installation laeuft weiter)"
			sagen ""
		fi

		# shellcheck disable=SC2086
		DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends $fehlt || true
	elif command -v dnf >/dev/null 2>&1; then
		# shellcheck disable=SC2086
		dnf install -y $fehlt || true
	elif command -v yum >/dev/null 2>&1; then
		# shellcheck disable=SC2086
		yum install -y $fehlt || true
	else
		fehler "Kein bekannter Paketmanager. Bitte von Hand installieren:$fehlt"
	fi
else
	sagen "restic und openssh-client sind schon da."
fi

if ! command -v restic >/dev/null 2>&1; then
	rot "restic liess sich nicht installieren."
	if [ -s "$APT_LOG" ] && grep -q '^E:' "$APT_LOG"; then
		rot ""
		rot "Wahrscheinliche Ursache: eine defekte Paketquelle. Die Meldung war:"
		grep '^E:' "$APT_LOG" | sed 's/^/    /' | head -4
		rot ""
		rot "Die betreffende Datei liegt in /etc/apt/sources.list.d/. Entweder den"
		rot "Schluesselpfad darin richtigstellen oder die Quelle abschalten, dann"
		rot "dieses Skript erneut aufrufen."
	fi
	exit 1
fi

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
