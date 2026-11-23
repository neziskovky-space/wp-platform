<?php
// Run: php tests/nz-cache-test.php
define('ABSPATH', '/');
define('WP_CONTENT_DIR', '/wp');
function add_action() {}
require __DIR__ . '/../mu-plugins/nz-cache.php';

$d = '/wp/cache/nz';
assert(nz_cache_file('Example.SK', '/') === "$d/example.sk/index.html");
assert(nz_cache_file('example.sk', '/o-nas/') === "$d/example.sk/o-nas/index.html");
assert(nz_cache_file('example.sk', '/%C5%A1pit%C3%A1l/') === "$d/example.sk/špitál/index.html");
assert(nz_cache_file('example.sk', '/o-nas/?utm=1') === null);
assert(nz_cache_file('example.sk', '/o-nas') === null);
assert(nz_cache_file('example.sk', '/../etc/') === null);
assert(nz_cache_file('example.sk', '/%2e%2e/etc/') === null);
assert(nz_cache_file('example.sk', '//evil/') === null);
assert(nz_cache_file('ex/ample.sk', '/') === null);
assert(nz_cache_file('', '/') === null);

$ok = '<html></html>';
assert(nz_cache_storable(200, ['Content-Type: text/html; charset=UTF-8'], $ok));
assert(!nz_cache_storable(404, [], $ok));
assert(!nz_cache_storable(200, [], '<html>truncated'));
assert(!nz_cache_storable(200, ['Set-Cookie: wp_woocommerce_session_x=1'], $ok));
assert(!nz_cache_storable(200, ['Cache-Control: no-cache, must-revalidate, max-age=0'], $ok));
assert(!nz_cache_storable(200, ['Content-Type: application/json'], $ok));

echo "ok\n";
