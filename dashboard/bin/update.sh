#!/usr/bin/env bash
#
# NorthLab Control Panel — Update einspielen.
#
# Holt den aktuellen Stand aus dem Git-Arbeitsverzeichnis und spiegelt ihn in
# das Installationsverzeichnis. config.php, Logs, Cache und erzeugte Pakete
# bleiben unangetastet.
#
# Aufruf (als root):
#   /opt/northlab-src/dashboard/bin/update.sh
#
# Abweichende Pfade lassen sich über Umgebungsvariablen setzen:
#   NL_SRC=/pfad/zum/klon NL_TARGET=/var/www/northlab NL_USER=www-data update.sh

set -euo pipefail

NL_SRC="${NL_SRC:-/opt/northlab-src}"
NL_TARGET="${NL_TARGET:-/var/www/northlab}"
NL_USER="${NL_USER:-www-data}"

rot()  { printf '\033[31m%s\033[0m\n' "$*"; }
gruen(){ printf '\033[32m%s\033[0m\n' "$*"; }
info() { printf '\033[2m%s\033[0m\n' "$*"; }

if [ "$(id -u)" -ne 0 ]; then
	rot "Bitte als root ausführen (die Dateirechte lassen sich sonst nicht setzen)."
	exit 1
fi

for pfad in "$NL_SRC/.git" "$NL_TARGET"; do
	if [ ! -e "$pfad" ]; then
		rot "Nicht gefunden: $pfad"
		rot "Pfade notfalls über NL_SRC und NL_TARGET setzen."
		exit 1
	fi
done

if [ ! -f "$NL_TARGET/config.php" ]; then
	rot "In $NL_TARGET liegt keine config.php — sieht nicht nach einer fertigen Installation aus."
	exit 1
fi

echo
info "Quelle:  $NL_SRC"
info "Ziel:    $NL_TARGET"
echo

# --- 1. Neuen Stand holen ---------------------------------------------------

VORHER="$(git -C "$NL_SRC" rev-parse --short HEAD)"

echo "→ Änderungen holen"
git -C "$NL_SRC" pull --ff-only

NACHHER="$(git -C "$NL_SRC" rev-parse --short HEAD)"

if [ "$VORHER" = "$NACHHER" ]; then
	info "  Bereits auf dem neuesten Stand ($NACHHER). Dateien werden trotzdem abgeglichen."
else
	gruen "  $VORHER → $NACHHER"
	git -C "$NL_SRC" --no-pager log --oneline "$VORHER..$NACHHER" | sed 's/^/    /'
fi

# --- 2. Dateien spiegeln ----------------------------------------------------

echo "→ Dateien abgleichen"

# Das Zielverzeichnis ist im Normalbetrieb schreibgeschützt.
chmod 750 "$NL_TARGET"

# storage/ bleibt vom --delete vollstaendig ausgenommen. Dort liegen
# Laufzeitdaten, die es im Quellbaum nicht gibt: der SSH-Schluessel der
# Sicherung, die known_hosts, die Spiegel der Kundenseiten und das
# hochgeladene Logo. Eine Ausnahmeliste einzelner Unterordner ist die falsche
# Form — sie vergisst jeden Ordner, der spaeter dazukommt.
rsync -a --delete \
	--exclude 'config.php' \
	--exclude '/storage/***' \
	"$NL_SRC/dashboard/" "$NL_TARGET/"

# Das Geruest unter storage/ (.htaccess, index.php, .gitkeep) trotzdem
# nachziehen — aber ohne --delete, damit nichts verschwindet.
rsync -a "$NL_SRC/dashboard/storage/" "$NL_TARGET/storage/"

# --- 3. Rechte wiederherstellen --------------------------------------------

echo "→ Rechte setzen"
chown -R "$NL_USER:$NL_USER" "$NL_TARGET"
chmod -R 750 "$NL_TARGET/storage"
chmod 640 "$NL_TARGET/config.php"
chmod 550 "$NL_TARGET"

# ssh verweigert einen privaten Schluessel, den auch die Gruppe lesen darf:
# "Permissions 0750 are too open. This private key will be ignored."
# Das pauschale 750 oben trifft ihn mit, also hier wieder einschraenken.
if [ -d "$NL_TARGET/storage/restic/.ssh" ]; then
	chmod 700 "$NL_TARGET/storage/restic" "$NL_TARGET/storage/restic/.ssh"
	find "$NL_TARGET/storage/restic/.ssh" -type f ! -name '*.pub' -exec chmod 600 {} +
	find "$NL_TARGET/storage/restic/.ssh" -type f -name '*.pub' -exec chmod 644 {} +
fi

# --- 4. Datenbankschema nachziehen -----------------------------------------

echo "→ Schema prüfen"
sudo -u "$NL_USER" php "$NL_TARGET/bin/cron.php" --list > /dev/null
gruen "  Schema aktuell"

# --- 5. Kurzer Funktionstest ------------------------------------------------

echo "→ Anwendung antwortet?"
if PANEL_URL="$(sudo -u "$NL_USER" php -r '
	$c = require "'"$NL_TARGET"'/config.php";
	echo rtrim($c["app"]["url"] ?? "", "/");
')" && [ -n "$PANEL_URL" ]; then
	# curl schreibt bei einem Verbindungsfehler selbst 000 und liefert einen
	# Fehlercode. Ohne das || true stuende danach 000000 da.
	CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$PANEL_URL/login" || true)"
	CODE="${CODE:-000}"
	if [ "$CODE" = "200" ]; then
		gruen "  $PANEL_URL/login → HTTP 200"
	else
		rot "  $PANEL_URL/login → HTTP $CODE"
		rot "  Logs prüfen: tail -30 $NL_TARGET/storage/logs/app.log"
		exit 1
	fi
else
	info "  Panel-URL nicht ermittelbar, Test übersprungen."
fi

echo
gruen "Update abgeschlossen."
