#!/usr/bin/env bash
# GLPI 11 jetable + plugin GLPI MCP + données de test, pour les tests de bout en bout.
# Utilisé par la CI (GitHub Actions) ; reproductible en local :
#   DB_HOST=127.0.0.1 DB_USER=root DB_PASSWORD=root WORK=/tmp/glpimcp-e2e bash tests/e2e/ci_setup.sh
# N'utilise aucune instance existante : base dédiée (DB_NAME, recréée), GLPI téléchargé, jetons générés à la volée.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
GLPI_VERSION="${GLPI_VERSION:-11.0.12}"
WORK="${WORK:-${RUNNER_TEMP:-/tmp}/glpimcp-e2e}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-glpi_e2e}"
DB_USER="${DB_USER:-root}"
DB_PASSWORD="${DB_PASSWORD:-root}"
PORT="${GLPI_PORT:-8080}"
GLPI="$WORK/glpi"
CONSOLE=(php "$GLPI/bin/console" --allow-superuser --no-interaction)

mkdir -p "$WORK"
if [ ! -f "$GLPI/bin/console" ]; then
  echo "== Téléchargement de GLPI $GLPI_VERSION"
  curl -fsSL "https://github.com/glpi-project/glpi/releases/download/$GLPI_VERSION/glpi-$GLPI_VERSION.tgz" -o "$WORK/glpi.tgz"
  tar xzf "$WORK/glpi.tgz" -C "$WORK"
fi

echo "== Plugin (copie de travail, sans .git)"
rm -rf "$GLPI/plugins/glpimcp"
mkdir -p "$GLPI/plugins/glpimcp"
tar -C "$PLUGIN_DIR" --exclude=.git -cf - . | tar -C "$GLPI/plugins/glpimcp" -xf -
# Commande de données de test : copiée seulement pour les tests, jamais livrée
mkdir -p "$GLPI/plugins/glpimcp/src/Console"
cp "$PLUGIN_DIR/tests/e2e/FixturesCommand.php" "$GLPI/plugins/glpimcp/src/Console/FixturesCommand.php"

echo "== Base jetable $DB_NAME"
"${CONSOLE[@]}" db:install -H "$DB_HOST" -P "$DB_PORT" -d "$DB_NAME" -u "$DB_USER" -p "$DB_PASSWORD" \
  --no-telemetry --force --reconfigure -L en_GB > "$WORK/db_install.log" 2>&1 || { tail -30 "$WORK/db_install.log"; exit 1; }
"${CONSOLE[@]}" glpi:config:set url_base "http://127.0.0.1:$PORT" > /dev/null

echo "== Installation du plugin"
"${CONSOLE[@]}" plugin:install glpimcp --username=glpi
"${CONSOLE[@]}" plugin:activate glpimcp
# Instance de test : suppression définitive autorisée pour tester l'approbation humaine
"${CONSOLE[@]}" glpi:config:set --context=plugin:glpimcp perm_purge 1 > /dev/null
"${CONSOLE[@]}" cache:clear > /dev/null

echo "== Données de test (entités, compte à deux profils, jetons)"
GLPIMCP_E2E=1 "${CONSOLE[@]}" plugins:glpimcp:e2e-fixtures 2>/dev/null | sed -n '/^{/,/^}/p' > "$WORK/fixtures.json"
python3 - "$WORK/fixtures.json" "$WORK/e2e.env" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
with open(sys.argv[2], "w") as f:
    for k, v in d.items():
        f.write(f"{k}={v}\n")
PY

echo "== Serveur web de test (plusieurs processus : nécessaire au test de confirmations simultanées)"
cd "$GLPI"
PHP_CLI_SERVER_WORKERS=8 setsid nohup php -S "127.0.0.1:$PORT" -t public public/index.php > "$WORK/php-server.log" 2>&1 < /dev/null &
echo $! > "$WORK/php-server.pid"
cd - > /dev/null
for _ in $(seq 1 60); do curl -fs -o /dev/null "http://127.0.0.1:$PORT/" && break; sleep 0.5; done
curl -fs -o /dev/null "http://127.0.0.1:$PORT/" || { tail -30 "$WORK/php-server.log"; exit 1; }

cat >> "$WORK/e2e.env" <<EOF
MCP_URL=http://127.0.0.1:$PORT/plugins/glpimcp/mcp
GLPI_LOGIN=glpi
GLPI_PASSWORD=glpi
DUAL_LOGIN=mcpdual
DUAL_PASSWORD=mcpdual-E2E-1
SELF_LOGIN=post-only
SELF_PASSWORD=postonly
DB_CLI="mysql -h$DB_HOST -P$DB_PORT -u$DB_USER${DB_PASSWORD:+ -p$DB_PASSWORD} $DB_NAME"
EOF
echo "Prêt : variables dans $WORK/e2e.env"
