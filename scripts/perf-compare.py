"""Compare two perf-baseline CSVs: per-site deltas plus the median row.
usage: python3 scripts/perf-compare.py metrics/BASELINE.csv metrics/NEW.csv
Lighthouse numbers are single lab runs: treat changes under ~10% (score +-5) as noise."""
import csv, statistics, sys

def load(p):
    return {r["host"]: r for r in csv.DictReader(open(p))}

a, b = load(sys.argv[1]), load(sys.argv[2])
cols = [c for c in next(iter(a.values())) if c != "host"]
num = lambda v: float(v) if v not in ("", None) else None

print("host".ljust(36) + "".join(c.replace("lh_", "").rjust(17) for c in cols))
rows = sorted(set(a) | set(b))
for h in rows + ["MEDIAN"]:
    line = h.ljust(36)
    for c in cols:
        if h == "MEDIAN":
            va = [num(a[x][c]) for x in a if num(a[x][c]) is not None]
            vb = [num(b[x][c]) for x in b if num(b[x][c]) is not None]
            x, y = (statistics.median(va) if va else None), (statistics.median(vb) if vb else None)
        else:
            x, y = num(a.get(h, {}).get(c)), num(b.get(h, {}).get(c))
        cell = "-" if x is None or y is None else f"{x:g}->{y:g}"
        line += cell.rjust(17)
    print(line)
