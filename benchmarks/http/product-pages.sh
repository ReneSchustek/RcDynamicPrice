#!/bin/bash
# Antwortzeiten der Seiten mit einem Meterpreis-Artikel: Produktseite, Warenkorb und Warenkorb-Leiste
# mit einem Zuschnitt von 2500 mm, je 12 warme Aufrufe, Median in Millisekunden. Läuft auf der DevBox
# gegen eine ddev-Instanz.
#
# Ausgabe je Seite eine Zeile: Seite;Median_ms;HTTP-Status
# Aufruf: product-pages.sh <instanz>
set -u
INSTANCE=${1:-live-clone}
RUNS=12
BASE=https://$INSTANCE.ddev.site
JAR=$(mktemp)
trap 'rm -f "$JAR"' EXIT

cd "/workspace/shopware/instances/$INSTANCE" || exit 1
PRODUCT=$(ddev mysql -N -e "SELECT LOWER(HEX(id)) FROM product WHERE product_number='BP-1110'" 2>/dev/null)

curl -skL -c "$JAR" -b "$JAR" "$BASE/" -o /dev/null
curl -skL -c "$JAR" -b "$JAR" -o /dev/null -X POST "$BASE/checkout/line-item/add"   --data-urlencode "lineItems[$PRODUCT][id]=$PRODUCT" --data-urlencode "lineItems[$PRODUCT][type]=product"   --data-urlencode "lineItems[$PRODUCT][referencedId]=$PRODUCT" --data-urlencode "lineItems[$PRODUCT][quantity]=1"   --data-urlencode "mmLength=2500"

median_ms() { sort -n | awk '{v[NR]=$1} END {printf "%d", (v[int((NR+1)/2)] + v[int((NR+2)/2)]) / 2 * 1000}'; }

measure() {
  local page=$1; shift
  curl -sk -c "$JAR" -b "$JAR" -o /dev/null "$@"
  local samples
  samples=$(for _ in $(seq $RUNS); do curl -sk -c "$JAR" -b "$JAR" -o /dev/null -w "%{time_total} %{http_code}\n" "$@"; done)
  printf "%s;%s;%s\n" "$page" "$(echo "$samples" | awk '{print $1}' | median_ms)" "$(echo "$samples" | awk '{print $2}' | sort -u | paste -sd/)"
}

# Liegt der Zuschnitt nicht im Warenkorb, messen die Seiten den leeren Fall; dann lieber abbrechen.
if ! curl -sk -c "$JAR" -b "$JAR" "$BASE/checkout/offcanvas" | grep -q '2\.500 mm'; then
  echo "Zuschnitt fehlt im Warenkorb; Messung abgebrochen." >&2
  exit 1
fi

measure Produktseite "$BASE/detail/$PRODUCT"
measure Warenkorb "$BASE/checkout/cart"
measure Warenkorb-Leiste "$BASE/checkout/offcanvas"
