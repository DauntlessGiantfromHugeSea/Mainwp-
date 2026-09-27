#!/bin/sh
#
# Sagt, warum die Storage Box das Panel nicht hereinlaesst.
#
#   sudo sh dashboard/bin/check-storagebox.sh u123456 [port]
#
# Prueft der Reihe nach: Namensaufloesung, offene Ports, welche
# Anmeldeverfahren die Box anbietet, und ob sie den Schluessel des Panels
# schon kennt. Am Ende steht, was zu tun ist.
#
# Es wird nichts geaendert und nichts uebertragen.
#
# Die Pruefungen laufen bewusst ohne Wirtsschluessel-Kontrolle
# (StrictHostKeyChecking=no, known_hosts nach /dev/null): hier werden keine
# Daten und keine Passwoerter uebergeben, es wird nur gefragt, was die
# Gegenseite anbietet. Fuer die Sicherung selbst bleibt die Kontrolle an.
#
set -u

BENUTZER="${1:-}"
PORT="${2:-}"

gruen() { printf '\033[32m%s\033[0m\n' "$*"; }
rot()   { printf '\033[31m%s\033[0m\n' "$*"; }
grau()  { printf '\033[2m%s\033[0m\n' "$*"; }
fett()  { printf '\033[1m%s\033[0m\n' "$*"; }

if [ -z "$BENUTZER" ]; then
	rot "Aufruf: sudo sh $0 uXXXXXX [port]"
	exit 1
fi

# Der Wirt heisst wie der Benutzer. Ueberschreibbar, damit sich das Skript
# gegen einen nachgebauten Server pruefen laesst.
WIRT="${NL_HOST:-$BENUTZER.your-storagebox.de}"

echo
fett "Storage Box $WIRT"
echo

# --- Den Schluessel des Panels finden ----------------------------------------
SCHLUESSEL="${NL_KEY:-}"

if [ -z "$SCHLUESSEL" ]; then
	for d in /var/www/northlab /opt/northlab /srv/northlab; do
		if [ -f "$d/storage/restic/.ssh/id_ed25519" ]; then
			SCHLUESSEL="$d/storage/restic/.ssh/id_ed25519"
			break
		fi
	done
fi

if [ -z "$SCHLUESSEL" ]; then
	SCHLUESSEL=$( find /var/www /opt /srv /home -maxdepth 6 -path '*/storage/restic/.ssh/id_ed25519' 2>/dev/null | head -1 )
fi

if [ -n "$SCHLUESSEL" ]; then
	grau "Schluessel des Panels: $SCHLUESSEL"
else
	grau "Schluessel des Panels: noch keiner erzeugt"
fi
echo

# --- 1. Name aufloesen -------------------------------------------------------
if getent hosts "$WIRT" > /dev/null 2>&1; then
	gruen "1. Name loest auf   $( getent hosts "$WIRT" | awk '{print $1}' | head -1 )"
else
	rot "1. Name loest NICHT auf — Benutzername richtig geschrieben?"
	exit 1
fi

# --- 2. Ports ----------------------------------------------------------------
offen() {
	# Ohne nc auskommen: /dev/tcp koennen bash und dash-Nachfolger nicht,
	# also nimmt das hier den Umweg ueber ssh mit kurzem Zeitlimit.
	timeout 8 ssh -o BatchMode=yes -o StrictHostKeyChecking=no \
		-o UserKnownHostsFile=/dev/null -o PreferredAuthentications=none \
		-o ConnectTimeout=6 -p "$1" "nobody@$WIRT" true 2>&1 | grep -qiE 'permission denied|authentication'
}

# Ist ein Port vorgegeben, wird nur der geprueft; sonst beide ueblichen.
PORTLISTE="${PORT:-23 22}"
ERSTER=''
ZEILE='2.'

for p in $PORTLISTE; do
	if offen "$p"; then
		gruen "$( printf '%-3s Port %-13s offen' "$ZEILE" "$p" )"
		[ -z "$ERSTER" ] && ERSTER="$p"
	else
		rot "$( printf '%-3s Port %-13s keine Antwort' "$ZEILE" "$p" )"
	fi
	ZEILE='  '
done

if [ -z "$ERSTER" ]; then
	echo
	rot "Die Box antwortet auf keinem Port."
	rot "Im Hetzner-Konto bei der Storage Box unter \"Einstellungen aendern\":"
	rot "  - Externe Erreichbarkeit einschalten"
	rot "  - SSH-Support einschalten"
	exit 1
fi

PORT="$ERSTER"

echo
grau "Weitere Pruefungen auf Port $PORT"
echo

# --- 3. Welche Anmeldeverfahren bietet die Box? ------------------------------
ANTWORT=$( timeout 10 ssh -o BatchMode=yes -o StrictHostKeyChecking=no \
	-o UserKnownHostsFile=/dev/null -o PreferredAuthentications=none \
	-o ConnectTimeout=8 -p "$PORT" "$BENUTZER@$WIRT" true 2>&1 )

VERFAHREN=$( printf '%s' "$ANTWORT" | grep -oE '\([a-z,-]+\)' | head -1 | tr -d '()' )

if [ -n "$VERFAHREN" ]; then
	gruen "3. Anmeldeverfahren $VERFAHREN"
else
	rot "3. Anmeldeverfahren nicht ermittelbar"
	grau "   $( printf '%s' "$ANTWORT" | tail -2 )"
fi

HAT_PASSWORT=nein
case "$VERFAHREN" in *password*) HAT_PASSWORT=ja ;; esac

# --- 4. Kennt die Box den Schluessel des Panels schon? -----------------------
SCHLUESSEL_OK=nein

if [ -n "$SCHLUESSEL" ] && [ -r "$SCHLUESSEL" ]; then
	if timeout 15 ssh -o BatchMode=yes -o StrictHostKeyChecking=no \
		-o UserKnownHostsFile=/dev/null -o IdentitiesOnly=yes -o PreferredAuthentications=publickey \
		-o ConnectTimeout=8 -i "$SCHLUESSEL" -p "$PORT" "$BENUTZER@$WIRT" true > /dev/null 2>&1
	then
		SCHLUESSEL_OK=ja
	fi
fi

if [ "$SCHLUESSEL_OK" = ja ]; then
	gruen "4. Schluessel       wird angenommen"
else
	rot "4. Schluessel       wird noch nicht angenommen"
fi

# --- Ergebnis ----------------------------------------------------------------
echo
if [ "$SCHLUESSEL_OK" = ja ]; then
	gruen "Alles bereit. Im Panel unter Sicherungen -> Einrichten den Knopf druecken"
	gruen "und das Passwortfeld leer lassen."
	if [ "$PORT" != 23 ]; then
		grau "Dabei oben den Port auf $PORT stellen."
	fi
	exit 0
fi

fett "Was jetzt zu tun ist"
echo

if [ -z "$SCHLUESSEL" ]; then
	echo "  - Im Panel unter Sicherungen -> SSH-Zugang ein Schluesselpaar erzeugen,"
	echo "    danach dieses Skript noch einmal aufrufen."
	echo
fi

if [ "$HAT_PASSWORT" = ja ]; then
	echo "  Die Box nimmt Passwoerter an. Es gibt also zwei Wege:"
	echo
	echo "  a) Im Hetzner-Konto bei der Storage Box das Passwort NEU SETZEN"
	echo "     (es ist ein eigenes, nicht das des Hetzner-Kontos), dann im Panel"
	echo "     unter Einrichten eintippen."
	echo
	if [ -n "$SCHLUESSEL" ] && [ "$PORT" = 23 ]; then
		echo "  b) Oder hier von Hand, mit demselben Passwort:"
		echo
		echo "       cat $SCHLUESSEL.pub | ssh -p23 $BENUTZER@$WIRT install-ssh-key"
		echo
		echo "     Danach im Panel unter Einrichten das Passwortfeld leer lassen."
	elif [ -n "$SCHLUESSEL" ]; then
		echo "  b) Der Weg ueber install-ssh-key braucht Port 23 — der antwortet hier nicht."
	fi
else
	echo "  Die Box nimmt KEINE Passwoerter an ($VERFAHREN)."
	echo "  Der Schluessel muss ueber das Hetzner-Konto hinterlegt werden."
	echo "  Erscheint dort kein Feld dafuer, ist meist SSH-Support noch aus:"
	echo "  Storage Box -> Einstellungen aendern -> SSH-Support."
	if [ -n "$SCHLUESSEL" ]; then
		echo
		echo "  Das ist der Schluessel, der dort hineingehoert:"
		echo
		cat "$SCHLUESSEL.pub" 2>/dev/null | sed 's/^/    /'
	fi
fi

echo
exit 1
