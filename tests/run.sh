#!/usr/bin/env bash
# Prüfungen ohne Symcon: Syntax und Kachel-Tests. Aus dem Repository-Ordner: tests/run.sh
set -euo pipefail
cd "$(dirname "$0")/.."
for f in "TileVisu Minikalender/module.php" "TileVisu Minikalender"/libs/*.php tests/*.php; do php -l "$f" >/dev/null; done
for f in "TileVisu Minikalender"/*.json library.json; do python3 -m json.tool "$f" >/dev/null; done
echo "Syntax und JSON in Ordnung."
php tests/tile_test.php | tail -1
