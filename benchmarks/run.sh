#!/bin/bash
# Messlauf der Meterpreis-Erweiterung gegen eine Instanz der DevBox: erst die Seitenzeiten über HTTP,
# dann die Dienste über PHPBench. Die Werte werden an benchmarks/results.csv angehängt, mit Datum,
# Fassung (aus der Instanz, nicht aus dem Quellbaum) und Rechner. Spalte `ms`: bei Seiten der Median
# von 12 Aufrufen, bei Diensten der Modus, den PHPBench über die Durchläufe ermittelt.
#
# Aufruf vom Arbeitsplatz: bash benchmarks/run.sh [instanz]
#
# Zahlen verschiedener Rechner oder Instanzen sind nicht vergleichbar; verglichen wird die Reihe
# derselben Instanz. Unter APP_ENV=dev liegen die Seitenzeiten höher als auf Live, die
# Unterschiede zwischen zwei Läufen bleiben aussagekräftig.
set -euo pipefail

INSTANCE=${1:-live-clone}
HOST=devbox
PLUGIN=RcDynamicPrice
PHPBENCH_VERSION='^1.7'
ROOT=$(cd "$(dirname "$0")/.." && pwd)
RESULTS=$ROOT/benchmarks/results.csv

# Die Messklassen liegen außerhalb von src/ und kommen mit keinem Rollout in die Instanz.
tar -C "$ROOT" -cf - benchmarks phpbench.json | ssh "$HOST" "tar -xf - -C /workspace/plugins/$PLUGIN"

remote() { ssh "$HOST" "cd /workspace/shopware/instances/$INSTANCE && $1"; }

VERSION=$(remote "ddev mysql -N -e \"SELECT version FROM plugin WHERE name='$PLUGIN'\" 2>/dev/null")
MACHINE=$(ssh "$HOST" hostname)
DATE=$(date +%F)
[ -f "$RESULTS" ] || echo "datum;fassung;rechner;instanz;art;messung;ms;streuung" > "$RESULTS"

echo "Seitenzeiten ($INSTANCE, Fassung $VERSION) ..."
# Als Datei statt über die Standardeingabe: `ddev mysql` im Skript läse sonst den Rest mit.
tr -d '\r' < "$ROOT/benchmarks/http/product-pages.sh" | ssh "$HOST" "cat > /workspace/temp/rc-dynamic-price-pages.sh"
ssh "$HOST" "bash /workspace/temp/rc-dynamic-price-pages.sh $INSTANCE" |
  while IFS=';' read -r page median status; do
    echo "  $page: $median ms ($status)"
    echo "$DATE;$VERSION;$MACHINE;$INSTANCE;seite;$page;$median;HTTP $status" >> "$RESULTS"
  done

# PHPBench liegt im var/-Ordner der Instanz: Im gemeinsamen Plugin-Ordner sähen es alle drei
# Instanzen, und in deren vendor/ gehört kein Messwerkzeug.
echo "Dienste (PHPBench) ..."
remote "ddev exec 'test -x var/rc-bench/vendor/bin/phpbench || (mkdir -p var/rc-bench && cd var/rc-bench && echo {} > composer.json && composer require -q --no-interaction phpbench/phpbench:$PHPBENCH_VERSION)'" >/dev/null 2>&1
remote "ddev exec 'cd custom/plugins/$PLUGIN && PROJECT_ROOT=/var/www/html php /var/www/html/var/rc-bench/vendor/bin/phpbench run --config=phpbench.json --report=results --progress=none'" 2>&1 |
  awk -F'|' '/Bench +\|/ {gsub(/ /, "", $2); gsub(/ /, "", $3); gsub(/^ +| +$/, "", $4); gsub(/[ ,]/, "", $7); gsub(/ /, "", $8); gsub(/ /, "", $9); print $2 "::" $3 ($4 == "" ? "" : " (" $4 ")") ";" $7 ";" $8 " Speicher " $9}' |
  while IFS=';' read -r subject mode spread; do
    echo "  $subject: $mode ($spread)"
    echo "$DATE;$VERSION;$MACHINE;$INSTANCE;dienst;$subject;${mode%ms};$spread" >> "$RESULTS"
  done

echo "Abgelegt in benchmarks/results.csv"
