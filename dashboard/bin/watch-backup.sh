#!/bin/sh
#
# Misst mit, was eine laufende Sicherung mit dem Server macht.
#
#   sudo sh dashboard/bin/watch-backup.sh [minuten]
#
# Gibt alle zehn Sekunden eine Zeile aus: Last, freier Speicher, ob restic
# gerade arbeitet, und wie lange das Panel fuer eine Seite braucht. Am Ende
# steht eine Zusammenfassung.
#
# Es wird nichts geaendert. Abbrechen mit Strg+C.
#
set -u

MINUTEN="${1:-10}"
ENDE=$(( $( date +%s ) + MINUTEN * 60 ))

gruen() { printf '\033[32m%s\033[0m\n' "$*"; }
rot()   { printf '\033[31m%s\033[0m\n' "$*"; }
grau()  { printf '\033[2m%s\033[0m\n' "$*"; }
fett()  { printf '\033[1m%s\033[0m\n' "$*"; }

# --- Panel finden, um seine Antwortzeit zu messen ---------------------------
ZIEL="${NL_TARGET:-}"

if [ -z "$ZIEL" ]; then
	for d in /var/www/northlab /opt/northlab /srv/northlab; do
		[ -f "$d/config.php" ] && { ZIEL="$d"; break; }
	done
fi

WIRT=''
if [ -n "$ZIEL" ] && [ -f "$ZIEL/config.php" ]; then
	WIRT=$( php -r '
		$c = require $argv[1];
		$u = (string) ( $c["app"]["url"] ?? "" );
		echo parse_url( $u, PHP_URL_HOST ) ?: "";
	' "$ZIEL/config.php" 2>/dev/null )
fi

KERNE=$( nproc 2>/dev/null || echo 1 )

fett "Beobachtung fuer $MINUTEN Minute(n)"
grau "Panel: ${ZIEL:-nicht gefunden}   Wirt: ${WIRT:-unbekannt}   Kerne: $KERNE"
grau "Eine Last von $KERNE,00 heisst: der Server ist voll ausgelastet."
echo
printf '%-8s  %-6s  %-9s  %-8s  %s\n' 'Zeit' 'Last' 'frei' 'Panel' 'restic'
printf '%s\n' '--------------------------------------------------------------'

LAST_MAX=0
ZEIT_MAX=0
PROBEN=0
STUMM=0

while [ "$( date +%s )" -lt "$ENDE" ]; do
	LAST=$( cut -d' ' -f1 /proc/loadavg )
	FREI=$( free -m 2>/dev/null | awk '/^Mem:/ {print $7 "M"}' )
	[ -z "$FREI" ] && FREI='?'

	if pgrep -x restic > /dev/null 2>&1; then
		RESTIC=$( ps -o pcpu= -C restic 2>/dev/null | awk '{s+=$1} END {printf "%.0f%% CPU", s}' )
		[ -z "$RESTIC" ] && RESTIC='laeuft'
	else
		RESTIC='—'
	fi

	if [ -n "$WIRT" ]; then
		# Der Statuscode muss mit: eine abgelehnte Verbindung liefert ebenfalls
		# eine Zeit, und zwar eine sehr kleine — das saehe wie eine blitzschnelle
		# Antwort aus.
		MESSUNG=$( curl -s -o /dev/null -w '%{http_code} %{time_total}' --max-time 20 \
			-H "Host: $WIRT" "http://127.0.0.1/login" 2>/dev/null )
		CODE=$( printf '%s' "$MESSUNG" | cut -d' ' -f1 )
		ANTWORT=$( printf '%s' "$MESSUNG" | cut -d' ' -f2 )

		case "${CODE:-000}" in
			2??|3??) ANTWORT="${ANTWORT}s" ;;
			*)       ANTWORT='keine'; STUMM=$(( STUMM + 1 )) ;;
		esac
	else
		ANTWORT='—'
	fi

	printf '%-8s  %-6s  %-9s  %-8s  %s\n' "$( date +%H:%M:%S )" "$LAST" "$FREI" "$ANTWORT" "$RESTIC"

	PROBEN=$(( PROBEN + 1 ))

	# Hoechstwerte mitfuehren — awk, weil die Shell nicht mit Kommazahlen rechnet.
	LAST_MAX=$( awk -v a="$LAST_MAX" -v b="$LAST" 'BEGIN { print (b+0 > a+0) ? b : a }' )
	case "$ANTWORT" in
		keine|—) ;;
		*)
			ROH=$( printf '%s' "$ANTWORT" | tr -d 's' )
			ZEIT_MAX=$( awk -v a="$ZEIT_MAX" -v b="$ROH" 'BEGIN { print (b+0 > a+0) ? b : a }' )
			;;
	esac

	sleep 10
done

echo
fett "Zusammenfassung ($PROBEN Proben)"
grau "  Hoechste Last:          $LAST_MAX  (von $KERNE Kern[en])"
grau "  Langsamste Antwort:     ${ZEIT_MAX}s"
grau "  Panel nicht erreichbar: ${STUMM}x"
echo

if [ "$STUMM" -gt 0 ]; then
	rot "Das Panel war zwischendurch gar nicht erreichbar — da stimmt noch etwas nicht."
elif awk -v z="$ZEIT_MAX" 'BEGIN { exit !(z+0 > 3) }'; then
	rot "Antwortzeiten ueber drei Sekunden. Die Sicherung drueckt den Server spuerbar."
elif awk -v l="$LAST_MAX" -v k="$KERNE" 'BEGIN { exit !(l+0 > k*2) }'; then
	rot "Die Last lag deutlich ueber dem, was der Server verkraftet."
else
	gruen "Sieht ruhig aus: das Panel blieb waehrend der Sicherung bedienbar."
fi
