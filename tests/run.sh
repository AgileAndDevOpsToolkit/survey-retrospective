#!/usr/bin/env bash
# Lance les tests dans une copie temporaire de l'application (le data.json du dépôt n'est pas touché).
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
APP="$(cd "$HERE/.." && pwd)"
PORT="${PORT:-8099}"
WORK="$(mktemp -d)"
trap 'kill "$PHP_PID" 2>/dev/null || true; rm -rf "$WORK"' EXIT

cp "$APP"/{index.html,results.html,admin.html,api.php,data.json} "$WORK"/
php -S "127.0.0.1:$PORT" -t "$WORK" > "$WORK/php.log" 2>&1 &
PHP_PID=$!
for _ in $(seq 1 30); do
    curl -fs "http://127.0.0.1:$PORT/api.php?action=health" > /dev/null 2>&1 && break
    sleep 0.2
done

export BASE="http://127.0.0.1:$PORT" WORK
bash "$HERE/api_tests.sh"

cp "$APP/data.json" "$WORK/data.json"     # repartir de la base de référence pour les tests d'interface
if python3 -c "import playwright" 2>/dev/null; then
    python3 "$HERE/ui_tests.py"
else
    echo "(Playwright non installé : tests d'interface ignorés — pip install playwright && playwright install chromium)"
fi
echo
echo "Journal PHP :"; cat "$WORK/php.log" | grep -v "Accepted\|Closing\|\[200\]\|\[204\]" || true
