#!/bin/sh
#
# Alle Prueflaeufe des Panels.
#
#   sh dashboard/tests/run.sh            # alles, was geht
#   sh dashboard/tests/run.sh backup     # nur Laeufe, deren Name "backup" enthaelt
#
# Was nicht laufen kann, wird uebersprungen und am Ende benannt — ein
# uebersprungener Lauf ist kein bestandener.
#
# Voraussetzungen je nach Lauf:
#   php                     alle
#   MariaDB auf 127.0.0.1   paneldump, backup-e2e, backup-setup
#   restic                  backup-e2e, backup-setup
#   openssh-client + sshd   backup-setup
#   node + playwright       die Browser-Laeufe
#
set -u

HIER=$( cd "$( dirname "$0" )" && pwd )
FILTER="${1:-}"

gruen() { printf '\033[32m%s\033[0m\n' "$*"; }
rot()   { printf '\033[31m%s\033[0m\n' "$*"; }
grau()  { printf '\033[2m%s\033[0m\n' "$*"; }

PRUEFUNGEN=0
FEHLER=0
UEBERSPRUNGEN=''

# --- Hilfsserver -------------------------------------------------------------
laeuft() { ss -lnt 2>/dev/null | grep -q "127.0.0.1:$1 " ; }

starte_server() {
	laeuft 8099 || ( cd "$HIER" && setsid php -S 127.0.0.1:8099 servers/panel-router.php > /dev/null 2>&1 < /dev/null & )
	laeuft 8098 || ( cd "$HIER/servers" && setsid php -S 127.0.0.1:8098 push-router.php > /dev/null 2>&1 < /dev/null & )
	sleep 2
}

# --- Einen Lauf ausfuehren ---------------------------------------------------
lauf() {
	name="$1"
	befehl="$2"

	case "$name" in
		*"$FILTER"*) ;;
		*) return 0 ;;
	esac

	ausgabe=$( eval "$befehl" 2>&1 )

	zahlen=$( printf '%s' "$ausgabe" | grep -oE '[0-9]+ Prüfungen, [0-9]+ Fehler' | tail -1 )

	if [ -z "$zahlen" ]; then
		rot "$( printf '%-16s %s' "$name" 'kein Ergebnis' )"
		printf '%s\n' "$ausgabe" | tail -5 | sed 's/^/    /'
		FEHLER=$(( FEHLER + 1 ))
		return 0
	fi

	letzte="$zahlen"
	n=$( printf '%s' "$zahlen" | awk '{print $1}' )
	f=$( printf '%s' "$zahlen" | awk '{print $3}' )

	PRUEFUNGEN=$(( PRUEFUNGEN + n ))
	FEHLER=$(( FEHLER + f ))

	if [ "$f" -eq 0 ]; then
		grau "$( printf '%-16s %s' "$name" "$letzte" )"
	else
		rot "$( printf '%-16s %s' "$name" "$letzte" )"
		printf '%s\n' "$ausgabe" | grep 'FEHLT' | sed 's/^/    /'
	fi
}

ueberspringe() {
	UEBERSPRUNGEN="$UEBERSPRUNGEN
    $1 — $2"
}

# --- Voraussetzungen pruefen -------------------------------------------------
hat_db=0
php -r 'exit( @( new PDO( "mysql:host=127.0.0.1;port=3306", getenv("NL_TEST_DB_USER") ?: "nl", getenv("NL_TEST_DB_PASS") ?: "nlpass" ) ) ? 0 : 1 );' 2>/dev/null && hat_db=1

hat_restic=0
command -v restic > /dev/null 2>&1 && hat_restic=1

hat_sshd=0
laeuft 2223 && hat_sshd=1

hat_node=0
# Die Pruefung muss dort laufen, wo auch die Laeufe laufen: node_modules liegt
# neben den Tests, nicht im Verzeichnis, aus dem run.sh aufgerufen wurde.
if command -v node > /dev/null 2>&1 && ( cd "$HIER" && node -e "require('playwright')" ) > /dev/null 2>&1; then
	hat_node=1
fi

# --- Laeufe ohne alles -------------------------------------------------------
gruen "== Ohne Datenbank =="
for datei in compat childlock child branding connect selfupdate hardening kundenseite zertifikat feature childversion icon render render-backup backup router asset; do
	lauf "$datei" "php '$HIER/$datei.php'"
done

starte_server
lauf "push-send" "php '$HIER/push-send.php'"

# --- Laeufe mit Datenbank ----------------------------------------------------
gruen "== Mit Datenbank =="
if [ "$hat_db" -eq 1 ]; then
	lauf "setting" "php '$HIER/setting.php'"
	lauf "update" "php '$HIER/update.php'"
	lauf "paneldump" "php '$HIER/paneldump.php'"
	lauf "mmode-verify" "php '$HIER/mmode-verify.php'"
	lauf "pdf" "php '$HIER/pdf.php'"

	if [ "$hat_restic" -eq 1 ]; then
		lauf "backup-e2e" "php '$HIER/backup-e2e.php'"

		if [ "$hat_sshd" -eq 1 ]; then
			lauf "backup-setup" "php '$HIER/backup-setup.php'"
		else
			ueberspringe "backup-setup" "kein SSH-Server auf 127.0.0.1:2223 (siehe README)"
		fi
	else
		ueberspringe "backup-e2e, backup-setup" "restic ist nicht installiert"
	fi
else
	ueberspringe "setting, update, paneldump, mmode-verify, pdf, backup-e2e, backup-setup" "keine MariaDB auf 127.0.0.1:3306"
fi

# --- Laeufe im Browser -------------------------------------------------------
gruen "== Im Browser =="
if [ "$hat_node" -eq 1 ]; then
	# Die HTML-Vorlagen fuer die file://-Laeufe erzeugen. Die Seiten binden
	# CSS und JS relativ ein, deshalb liegen Kopien daneben.
	cp "$HIER/../public/assets/css/app.css" "$HIER/app.css"
	cp "$HIER/../public/assets/js/app.js"   "$HIER/app.js"
	php "$HIER/dump.php"                > "$HIER/create.html"
	php "$HIER/branding-dump.php" bar   > "$HIER/bar.html"
	php "$HIER/branding-dump.php" login > "$HIER/loginbar.html"
	# Die Farbe muss zu der passen, die mmode-check erwartet.
	php "$HIER/mmode-dump.php" '#ff3d8b' > "$HIER/mmode.html"
	# Das Banner landet in fremden Themes - die Vorlage traegt darum
	# absichtlich feindseliges CSS.
	php "$HIER/banner-dump.php" logo > "$HIER/banner.html"

	for datei in check mmode-check banner-check branding-check push-check push-ui icon-check pwa-check; do
		lauf "$datei" "cd '$HIER' && node '$HIER/$datei.js'"
	done
else
	ueberspringe "alle Browser-Laeufe" "node mit playwright fehlt (npm i playwright)"
fi

# --- Ergebnis ----------------------------------------------------------------
echo
if [ -n "$UEBERSPRUNGEN" ]; then
	rot "Uebersprungen:$UEBERSPRUNGEN"
	echo
fi

if [ "$FEHLER" -eq 0 ]; then
	gruen "$PRUEFUNGEN Prüfungen, 0 Fehler"
else
	rot "$PRUEFUNGEN Prüfungen, $FEHLER Fehler"
	exit 1
fi
