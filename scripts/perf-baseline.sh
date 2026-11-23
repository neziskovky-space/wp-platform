#!/bin/bash
# Speed snapshot of every live multisite homepage -> CSV.
# usage: scripts/perf-baseline.sh [out.csv]      (default metrics/YYYY-MM-DD.csv)
# needs: ssh to the server, Google Chrome, npx, python3. Takes ~15 min.
#
# ttfb_cache_ms : server-side time to first byte served by nz-cache (median of 5, measured on the server, no network)
# ttfb_php_ms   : same URL with a query string, so WordPress renders it in PHP (median of 5)
# lh_*          : Lighthouse mobile lab run (simulated slow 4G + mid-range phone), one run from this machine
set -euo pipefail
SRV=${SRV:-martin@195.210.29.50}
LH=lighthouse@12.6.0
cd "$(dirname "$0")/.."
out=${1:-metrics/$(date +%F).csv}
mkdir -p "$(dirname "$out")"
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT

# Server side: TTFB medians for all sites in one ssh session.
ssh "$SRV" 'bash -s' > "$tmp/ttfb" <<'EOF'
med() { sort -n | awk '{a[NR]=$1} END {print int(a[int((NR+1)/2)]*1000)}'; }
for u in $(wp --path=/srv/www/wordpress --skip-plugins --skip-themes site list --field=url 2>/dev/null); do
  h=$(echo "$u" | sed -E 's#https?://##; s#/##g')
  t() { curl -s -o /dev/null -m 30 --resolve "$h:443:127.0.0.1" -w '%{time_starttransfer}\n' "$1"; }
  t "https://$h/" >/dev/null  # warm cache
  c=$(for i in 1 2 3 4 5; do t "https://$h/"; done | med)
  p=$(for i in 1 2 3 4 5; do t "https://$h/?nzbaseline=$RANDOM"; sleep 0.3; done | med)
  echo "$h,$c,$p"
done
EOF

echo "host,ttfb_cache_ms,ttfb_php_ms,lh_score,lh_fcp_ms,lh_lcp_ms,lh_tbt_ms,lh_cls,lh_page_kb,lh_requests" > "$out"
while IFS=, read -r h c p; do
  echo "lighthouse $h" >&2
  if npx -y "$LH" "https://$h/" --quiet --only-categories=performance --output=json --output-path="$tmp/lh.json" \
       --chrome-flags="--headless=new" >/dev/null 2>&1; then
    lh=$(python3 -I -c '
import json,sys
r=json.load(open(sys.argv[1])); a=r["audits"]; n=lambda k:a[k].get("numericValue") or 0
print(",".join(str(x) for x in [round((r["categories"]["performance"]["score"] or 0)*100), round(n("first-contentful-paint")),
  round(n("largest-contentful-paint")), round(n("total-blocking-time")), round(n("cumulative-layout-shift"),3),
  round(n("total-byte-weight")/1024), len(a["network-requests"].get("details",{}).get("items",[]))]))' "$tmp/lh.json")
  else lh=",,,,,,"; fi
  echo "$h,$c,$p,$lh" >> "$out"
done < "$tmp/ttfb"
echo "wrote $out" >&2
