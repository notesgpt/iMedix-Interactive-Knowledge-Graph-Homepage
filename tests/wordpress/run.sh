#!/usr/bin/env bash
# WordPress integration test: real WordPress + SQLite + WP-CLI + PHP built-in server.
#
#   WP_ZIP=…/wordpress-6.5.zip SQLITE_ZIP=…/sqlite-database-integration.zip WP_CLI=…/wp-cli.phar \
#     tests/wordpress/run.sh
#
# Everything happens in build/wp (throw-away). The plugin is COPIED (never symlinked),
# so uninstall tests cannot touch the repository.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
W="$ROOT/build/wp"
PORT="${KU_PORT:-8899}"
URL="http://127.0.0.1:$PORT"
export KU_FIXTURES="$ROOT/tests/fixtures/sheets"
: "${WP_ZIP:?set WP_ZIP}" "${SQLITE_ZIP:?set SQLITE_ZIP}" "${WP_CLI:?set WP_CLI}"

wp() { php "$WP_CLI" --path="$W" --allow-root --skip-themes=false "$@"; }
pass=0; fail=0
check() { # check "description" condition-command...
  local desc="$1"; shift
  if "$@" >/dev/null 2>&1; then pass=$((pass+1)); echo "  ✓ $desc"; else fail=$((fail+1)); echo "  ✗ $desc"; fi
}
contains() { grep -qF -- "$2" <<<"$1"; }
not_contains() { ! grep -qF -- "$2" <<<"$1"; }
equals() { [ "$1" = "$2" ]; }

echo "== Building WordPress in $W"
rm -rf "$W"; mkdir -p "$W"
unzip -q "$WP_ZIP" -d "$W"
unzip -q "$SQLITE_ZIP" -d "$W/wp-content/plugins/"
for d in "$W"/wp-content/plugins/sqlite-database-integration-*; do [ -d "$d" ] && mv "$d" "$W/wp-content/plugins/sqlite-database-integration"; done
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$W/wp-content/plugins/sqlite-database-integration#" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
    "$W/wp-content/plugins/sqlite-database-integration/db.copy" > "$W/wp-content/db.php"
cat > "$W/wp-config.php" <<EOF
<?php
define( 'DB_NAME', 'wp' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8' ); define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' ); define( 'DB_FILE', 'wp.sqlite' );
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', __DIR__ . '/debug.log' ); define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true ); define( 'AUTOMATIC_UPDATER_DISABLED', true );
\$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
EOF
mkdir -p "$W/wp-content/mu-plugins" "$W/wp-content/database"
cp "$ROOT/tests/wordpress/ku-test-env.php" "$W/wp-content/mu-plugins/"
cp -r "$ROOT/tests/wordpress/classic-theme" "$W/wp-content/themes/ku-classic"
cp -r "$ROOT/wordpress/imedix-knowledge-universe" "$W/wp-content/plugins/"

wp core install --url="$URL" --title="KU Test" --admin_user=admin --admin_password=admin --admin_email=test@example.com --skip-email >/dev/null
wp rewrite structure '/%postname%/' --hard >/dev/null
wp plugin activate imedix-knowledge-universe >/dev/null
wp eval-file "$ROOT/tests/wordpress/seed.php"
wp rewrite flush --hard >/dev/null

echo "== Sync"
out=$(wp imedix-ku sync --force 2>&1 || true)
echo "  $out"
check "first sync builds the graph" contains "$out" "Success: built"
state=$(wp imedix-ku status)
check "drafts are excluded (797 - 2 drug drafts)" contains "$state" '"drug": 795'
check "draft disease excluded (96 - 1)" contains "$state" '"disease": 95'
check "static versioned file written" contains "$state" "/wp-content/uploads/imedix-ku/graph-"
rej=$(wp imedix-ku rejected --format=csv)
check "draft posts logged as not published / not found" bash -c 'grep -Eq "abilify,(wp_not_found|wp_not_published)" <<<"$1"' _ "$rej"
check "retired rows logged" contains "$rej" "status_retired"
graph=$(wp option get imedix_ku_graph)
check "WordPress titles replace slug names" contains "$graph" '"n":"WP title: Metformin"'
check "content links become links_to edges" contains "$graph" '["drug:metformin","disease:diabetes-mellitus","links_to"]'
check "relative content links resolved" contains "$graph" '["drug:metformin","disease:metabolic-syndrome","links_to"]'
check "excerpt stripped of markup" contains "$graph" '"x":"Excerpt with markup & entities."'
check "node paths are site-relative" contains "$graph" '"p":"/drugs/metformin/"'

out=$(wp imedix-ku sync 2>&1 || true)
check "unchanged sheet + content → incremental skip" contains "$out" "unchanged"
wp post update "$(wp post list --post_type=drugs --name=metformin --field=ID)" --post_status=draft >/dev/null
out=$(wp imedix-ku sync 2>&1 || true)
check "publication change marks graph dirty → rebuild" contains "$out" "built"
check "unpublished drug disappears" not_contains "$(wp option get imedix_ku_graph)" '"id":"drug:metformin"'
wp post update "$(wp post list --post_type=drugs --name=metformin --post_status=draft --field=ID)" --post_status=publish >/dev/null
wp imedix-ku sync >/dev/null 2>&1 || true

before=$(wp option get imedix_ku_graph | md5sum)
wp option update ku_test_sheet html >/dev/null
out=$(wp imedix-ku sync --force 2>&1 || true)
check "unshared sheet (HTML response) is reported" contains "$out" "Unexpected content type"
check "last good graph kept after failure" equals "$before" "$(wp option get imedix_ku_graph | md5sum)"
wp option update ku_test_sheet truncated >/dev/null
out=$(wp imedix-ku sync --force 2>&1 || true)
check "collapse guard refuses a truncated sheet" contains "$out" "Refusing to publish"
check "last good graph still kept" equals "$before" "$(wp option get imedix_ku_graph | md5sum)"
wp option update ku_test_sheet ok >/dev/null

echo "== Front end"
home_id=$(wp post create --post_type=page --post_title=Home --post_status=publish --porcelain)
wp post meta update "$home_id" _wp_page_template imedix-knowledge-universe.php >/dev/null
plain_id=$(wp post create --post_type=page --post_title=Plain --post_name=plain --post_status=publish --porcelain)
wp option update show_on_front page >/dev/null
wp option update page_on_front "$home_id" >/dev/null

cat > "$W/router.php" <<'EOF'
<?php
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $path && is_file( __DIR__ . $path ) ) { return false; }
require __DIR__ . '/index.php';
EOF
php -S "127.0.0.1:$PORT" -t "$W" "$W/router.php" >"$ROOT/build/wp-server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for _ in $(seq 1 50); do curl -s -o /dev/null "$URL/" && break; sleep 0.2; done

run_frontend_checks() {
  local theme="$1"
  html=$(curl -s "$URL/")
  check "[$theme] homepage renders the Knowledge Universe" contains "$html" 'data-ku-root'
  check "[$theme] exactly one <h1>" equals "$(grep -o '<h1' <<<"$html" | wc -l | tr -d ' ')" "1"
  check "[$theme] search form works without JS (GET ?s=)" contains "$html" 'name="s"'
  check "[$theme] counts rendered server-side" contains "$html" 'Search 795 drug guides and 95 disease guides'
  check "[$theme] crawlable topic links" contains "$html" "href=\"$URL/diseases/hypertension/\""
  check "[$theme] news titles escaped, tags stripped" contains "$html" 'Integration test news item 14: A&amp;B trial'
  check "[$theme] no raw script injection" not_contains "$html" '<script>alert(1)'
  check "[$theme] draft news excluded" not_contains "$html" 'news item 15 '
  check "[$theme] news archive link" contains "$html" "$URL/news/"
  check "[$theme] drug archive discovered at runtime" contains "$html" "href=\"$URL/drugs/\""
  check "[$theme] disease card falls back to search (no archive)" contains "$html" 'href="#ku-q" data-ku-focus-search="disease"'
  check "[$theme] module script enqueued" contains "$html" 'type="module"'
  check "[$theme] stylesheet enqueued" contains "$html" 'imedix-ku-0-css'
  check "[$theme] config JSON printed" contains "$html" 'id="imedix-ku-config"'
  check "[$theme] canonical link from WordPress core kept" contains "$html" 'rel="canonical"'
  check "[$theme] no structured data added" not_contains "$html" 'application/ld+json'
  plain=$(curl -s "$URL/plain/")
  check "[$theme] assets not loaded on other pages" not_contains "$plain" 'imedix-ku-0-css'
}

wp theme activate ku-classic >/dev/null
html=$(curl -s "$URL/")
check "[classic] theme header present" contains "$html" 'ku-test-theme-header'
check "[classic] theme footer present" contains "$html" 'ku-test-theme-footer'
run_frontend_checks classic
wp theme activate twentytwentyfour >/dev/null
html=$(curl -s "$URL/")
check "[block] block theme header part rendered" contains "$html" 'wp-block-template-part'
run_frontend_checks block

echo "== REST"
g=$(curl -s -i "$URL/wp-json/imedix-ku/v1/graph")
check "graph endpoint 200" contains "$g" "HTTP/1.1 200"
check "graph endpoint cacheable" contains "$g" "Cache-Control: public, max-age=900"
check "graph schema" contains "$g" '"schema":"imedix-ku\/client@1"'
n=$(curl -s "$URL/wp-json/imedix-ku/v1/news?limit=5")
check "news endpoint returns 5 items" equals "$(php -r 'echo count(json_decode(stream_get_contents(STDIN),true)["items"]);' <<<"$n")" "5"
check "news newest first" equals "$(php -r '$i=json_decode(stream_get_contents(STDIN),true)["items"]; echo strcmp($i[0]["date"],$i[1]["date"])>0?"ok":"bad";' <<<"$n")" "ok"
check "news titles plain text" contains "$n" '"title":"Integration test news item 14: A&B trial"'
check "news limit validated" contains "$(curl -s -o /dev/null -w '%{http_code}' "$URL/wp-json/imedix-ku/v1/news?limit=999")" "400"
check "news CPT not exposed by core REST (unchanged config)" contains "$(curl -s -o /dev/null -w '%{http_code}' "$URL/wp-json/wp/v2/news")" "404"

echo "== Brand alignment"
css=$(cat "$W"/wp-content/plugins/imedix-knowledge-universe/assets/dist/assets/*.css)
check "no bundled web fonts (theme fonts inherited)" bash -c '! ls "$1"/wp-content/plugins/imedix-knowledge-universe/assets/dist/assets/*.woff2 2>/dev/null | grep -q .' _ "$W"
check "no hard-coded font families in CSS" bash -c '! grep -oE "font-family:[^;}]*" <<<"$1" | grep -vqE "font-family:(var\(--[a-z-]+\)|inherit)$"' _ "$css"
check "theme colour hooks available (--imedix-color-*)" contains "$css" "--imedix-color-navy"

echo "== Admin"
admin=$(wp eval 'wp_set_current_user(1); \Imedix\KnowledgeUniverse\WordPress\Admin::render();')
check "settings page renders sync status" contains "$admin" "Sync status"
check "settings page lists rejected records" contains "$admin" "status_retired"
noadmin=$(wp eval 'wp_set_current_user(0); \Imedix\KnowledgeUniverse\WordPress\Admin::render(); echo "END";')
check "settings page hidden from anonymous users" equals "$noadmin" "END"
san=$(wp eval 'echo json_encode(\Imedix\KnowledgeUniverse\WordPress\Settings::sanitize(array("spreadsheet_id"=>"<script>","news_count"=>999,"base_drug"=>"javascript:x","sheet_host"=>"evil host","source_mode"=>"x")));')
check "settings: count clamped" contains "$san" '"news_count":20'
check "settings: bad base path rejected" contains "$san" '"base_drug":"\/drugs\/"'
check "settings: unknown mode rejected" contains "$san" '"source_mode":"csv_export"'
check "settings: bad host rejected" contains "$san" '"sheet_host":"www.imedix.com"'
check "settings: bad spreadsheet id rejected" contains "$san" '"spreadsheet_id":"1KLBtihRPuRrwAn9VB0a9lVcyg9z-kjZAqvg4UWO0Ivk"'
check "cron event scheduled" contains "$(wp cron event list --fields=hook --format=csv)" "imedix_ku_sync"

echo "== PHP notices"
if [ -f "$W/debug.log" ] && grep -i "imedix\|knowledge-universe" "$W/debug.log" >/dev/null; then
  grep -i "imedix\|knowledge-universe" "$W/debug.log" | head -20
  fail=$((fail+1)); echo "  ✗ plugin emitted PHP notices/warnings"
else
  pass=$((pass+1)); echo "  ✓ no PHP notices or warnings from the plugin"
fi

echo "== Uninstall"
wp plugin deactivate imedix-knowledge-universe >/dev/null
wp eval 'define("WP_UNINSTALL_PLUGIN", true); include WP_PLUGIN_DIR . "/imedix-knowledge-universe/uninstall.php";'
check "options removed" equals "$(wp option get imedix_ku_graph 2>/dev/null || echo gone)" "gone"
check "generated files removed" bash -c "[ ! -d '$W/wp-content/uploads/imedix-ku' ]"
check "posts untouched" equals "$(wp post list --post_type=drugs --format=count --post_status=any)" "797"

echo
echo "WordPress integration: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
