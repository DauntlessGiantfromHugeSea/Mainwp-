#!/bin/sh
#
# Neuen Stand einspielen und die Sicherung vorbereiten — in einem Durchgang.
#
# Findet den Git-Klon und die Installation selbst, damit man die Pfade nicht
# kennen muss. Gedacht fuer die Online-Konsole des Servers:
#
#   sudo sh deploy-and-setup.sh
#
# Pfade lassen sich notfalls vorgeben:
#   NL_SRC=/opt/northlab-src NL_TARGET=/var/www/northlab sudo -E sh deploy-and-setup.sh
#
set -eu

rot()   { printf '\033[31m%s\033[0m\n' "$*"; }
gruen() { printf '\033[32m%s\033[0m\n' "$*"; }
info()  { printf '\033[2m%s\033[0m\n' "$*"; }

[ "$( id -u )" -eq 0 ] || { rot "Bitte mit sudo ausfuehren."; exit 1; }

# Wo gesucht wird. Ueberschreibbar, damit sich das Skript pruefen laesst.
#
# /home steht bewusst nicht mehr dabei: dieses Skript laeuft als root und
# fuehrt aus, was es findet. Ein unprivilegierter Benutzer, der sich
# ~/x/.git/ und ~/x/dashboard/bin/update.sh anlegt, haette damit beim
# naechsten "sudo sh deploy-and-setup.sh" beliebigen Code als root gestartet.
WURZELN="${NL_SUCHPFADE:-/opt /srv /var/www /usr/local}"

# Gehoert der Pfad root? Was dieses Skript als root ausfuehrt, muss auch root
# gehoeren — sonst bestimmt der Besitzer, was root tut.
gehoert_root() {
	[ "$( stat -c '%u' "$1" 2>/dev/null || echo 1 )" -eq 0 ]
}

# --- Den Git-Klon finden -----------------------------------------------------
KLON="${NL_SRC:-}"

if [ -z "$KLON" ]; then
	# shellcheck disable=SC2086
	TREFFER=$( find $WURZELN -maxdepth 5 -type d -name .git 2>/dev/null | while read -r g; do
		d=${g%/.git}
		[ -f "$d/dashboard/bin/update.sh" ] && echo "$d"
	done )

	ANZAHL=$( printf '%s\n' "$TREFFER" | grep -c . )

	if [ "$ANZAHL" -gt 1 ]; then
		rot "Mehrere Klone gefunden — welcher es sein soll, entscheidet nicht der Zufall:"
		printf '%s\n' "$TREFFER" | sed 's/^/    /'
		rot "Bitte NL_SRC setzen."
		exit 1
	fi

	KLON=$( printf '%s\n' "$TREFFER" | head -1 )
fi

[ -n "$KLON" ] || { rot "Kein Git-Klon des Panels gefunden. Bitte NL_SRC setzen."; exit 1; }

gehoert_root "$KLON/dashboard/bin/update.sh" || {
	rot "$KLON/dashboard/bin/update.sh gehoert nicht root — das wird hier nicht als root ausgefuehrt."
	exit 1
}

# --- Die Installation finden -------------------------------------------------
ZIEL="${NL_TARGET:-}"

if [ -z "$ZIEL" ]; then
	# shellcheck disable=SC2086
	ZIEL=$( find $WURZELN -maxdepth 5 -type f -name config.php 2>/dev/null | while read -r c; do
		d=${c%/config.php}
		# Der Klon selbst ist nicht die Installation.
		case "$d" in "$KLON"/*) continue ;; esac
		[ -f "$d/public/index.php" ] && [ -f "$d/bin/cron.php" ] && echo "$d"
	done | head -1 )
fi

[ -n "$ZIEL" ] || { rot "Keine fertige Installation gefunden. Bitte NL_TARGET setzen."; exit 1; }

# --- Unter welchem Benutzer laeuft das Panel? --------------------------------
BENUTZER="${NL_USER:-$( stat -c '%U' "$ZIEL/storage" 2>/dev/null || echo www-data )}"

echo
info "Klon:      $KLON"
info "Panel:     $ZIEL"
info "Benutzer:  $BENUTZER"
echo

# --- 1. Neuen Stand einspielen ----------------------------------------------
gruen "== Update =="
NL_SRC="$KLON" NL_TARGET="$ZIEL" NL_USER="$BENUTZER" bash "$KLON/dashboard/bin/update.sh"

# --- 2. Sicherung vorbereiten ------------------------------------------------
echo
gruen "== Sicherung vorbereiten =="
# Das Skript leitet den Panel-Pfad aus seinem eigenen Ort ab, muss also aus
# der Installation laufen. Der vorangegangene Update-Lauf hat es gerade dort
# hingelegt und root uebereignet — nachsehen kostet nichts.
gehoert_root "$ZIEL/bin/setup-backup.sh" || {
	rot "$ZIEL/bin/setup-backup.sh gehoert nicht root — das wird hier nicht als root ausgefuehrt."
	exit 1
}
sh "$ZIEL/bin/setup-backup.sh"
