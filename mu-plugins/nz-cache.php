<?php
/**
 * Plugin Name: NZ Cache
 * Description: Static page cache for the neziskovky.space multisite. PHP writes HTML files, Apache serves them (see htaccess/nz-cache.conf).
 * License: GPL-2.0-or-later
 *
 * Files: wp-content/cache/nz/{host}/{path}/index.html
 * Cached: anonymous HTTPS GET, no query string, 200 text/html, no Set-Cookie / no-cache headers, no DONOTCACHEPAGE.
 * Purge: whole site on any content change, admin bar button, `wp nz-cache purge`. Expiry: cron (see README).
 * Inactive on sites where WP Rocket is still active, so sites can migrate one by one.
 */

defined('ABSPATH') || exit;

const NZ_CACHE_DIR = WP_CONTENT_DIR . '/cache/nz';
// Must match the cookie condition in htaccess/nz-cache.conf.
const NZ_CACHE_BYPASS_COOKIES = '/^(wordpress_logged_in_|wp-postpass_|comment_author_|woocommerce_items_in_cart|wp_woocommerce_session_)/';

/** Cache file for a host + raw request URI, or null when the URL must not be cached. Pure: tested in tests/. */
function nz_cache_file(string $host, string $uri): ?string {
	$host = strtolower($host);
	if (!preg_match('/^[a-z0-9.-]+$/', $host)) return null;
	if (str_contains($uri, '?')) return null;
	$path = rawurldecode($uri); // Apache's %{REQUEST_URI} is decoded, so files are stored decoded.
	if ($path === '' || $path[0] !== '/' || !str_ends_with($path, '/')) return null;
	if (str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, '//')) return null;
	return NZ_CACHE_DIR . '/' . $host . $path . 'index.html';
}

/** Whether a finished response may be stored. Pure: tested in tests/. */
function nz_cache_storable(int $status, array $headers, string $html): bool {
	if ($status !== 200 || !str_contains($html, '</html>')) return false;
	foreach ($headers as $h) {
		if (stripos($h, 'set-cookie:') === 0) return false;
		if (stripos($h, 'cache-control:') === 0 && preg_match('/no-cache|no-store|private/i', $h)) return false;
		if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return false;
	}
	return true;
}

function nz_cache_active(): bool {
	return !defined('WP_ROCKET_VERSION');
}

function nz_cache_request_cacheable(): bool {
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !is_ssl()) return false;
	if (is_user_logged_in() || defined('DONOTCACHEPAGE')) return false;
	foreach (array_keys($_COOKIE) as $c) if (preg_match(NZ_CACHE_BYPASS_COOKIES, $c)) return false;
	if (is_search() || is_404() || is_feed() || is_preview() || is_trackback() || is_robots() || is_customize_preview()) return false;
	if (is_singular() && post_password_required()) return false;
	if (function_exists('is_woocommerce') && (is_cart() || is_checkout() || is_account_page())) return false;
	return true;
}

add_action('template_redirect', function () {
	if (!nz_cache_active() || !nz_cache_request_cacheable()) return;
	$file = nz_cache_file($_SERVER['HTTP_HOST'] ?? '', $_SERVER['REQUEST_URI'] ?? '');
	if (!$file) return;
	ob_start(function ($html) use ($file) {
		// DONOTCACHEPAGE can be defined late (e.g. by a shortcode while rendering).
		if (defined('DONOTCACHEPAGE') || !nz_cache_storable(http_response_code() ?: 200, headers_list(), $html)) return $html;
		nz_cache_write($file, $html . "\n<!-- nz-cache " . gmdate('c') . ' -->');
		return $html;
	});
}, PHP_INT_MAX);

function nz_cache_write(string $file, string $html): void {
	$dir = dirname($file);
	// Group-writable so the expiry cron (run as a www-data group member) can delete files.
	if (!is_dir($dir)) {
		$umask = umask(0002); // mkdir() applies umask to every level it creates
		$ok = @mkdir($dir, 0775, true);
		umask($umask);
		if (!$ok && !is_dir($dir)) return;
	}
	$tmp = $file . '.' . getmypid() . '.tmp';
	if (@file_put_contents($tmp, $html) === false) return;
	@chmod($tmp, 0664);
	@rename($tmp, $file);
}

function nz_cache_rmdir(string $dir): void {
	if (!is_dir($dir)) return;
	$trash = $dir . '.purge-' . getmypid() . '-' . mt_rand(); // rename first so Apache never serves a half-deleted tree
	if (!@rename($dir, $trash)) return;
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($trash, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
	@rmdir($trash);
}

/** Purge the current site (both apex and www host). */
function nz_cache_purge_site(): void {
	$host = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
	if ($host === '') return;
	$bare = preg_replace('/^www\./', '', $host);
	foreach ([$bare, 'www.' . $bare] as $h) nz_cache_rmdir(NZ_CACHE_DIR . '/' . $h);
}

// ponytail: whole-site purge on any change; sites are small. Per-URL purge if a site gets big.
add_action('transition_post_status', function ($new, $old, $post) {
	if (($new === 'publish' || $old === 'publish') && !wp_is_post_revision($post) && $post->post_type !== 'nav_menu_item') nz_cache_purge_site();
}, 10, 3);
foreach (['deleted_post', 'edited_term', 'created_term', 'delete_term', 'wp_update_nav_menu', 'switch_theme', 'customize_save_after',
	'activated_plugin', 'deactivated_plugin', 'update_option_sidebars_widgets', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock'] as $hook) {
	add_action($hook, 'nz_cache_purge_site');
}
// Oxygen saves templates/stylesheets/global settings as options.
add_action('updated_option', function ($name) {
	if (preg_match('/^(oxygen_|ct_global_settings|ct_style_sheets|ct_components_classes|ct_custom_selectors)/', $name)) nz_cache_purge_site();
});

add_action('admin_bar_menu', function ($bar) {
	if (!nz_cache_active() || !current_user_can('edit_pages')) return;
	$bar->add_node(['id' => 'nz-cache-purge', 'title' => 'Vymazať cache',
		'href' => wp_nonce_url(admin_url('admin-post.php?action=nz_cache_purge'), 'nz_cache_purge')]);
}, 100);
add_action('admin_post_nz_cache_purge', function () {
	if (!current_user_can('edit_pages')) wp_die('Forbidden', 403);
	check_admin_referer('nz_cache_purge');
	nz_cache_purge_site();
	wp_safe_redirect(wp_get_referer() ?: admin_url());
	exit;
});

if (defined('WP_CLI') && WP_CLI) {
	/** Purge the page cache of the site given by --url. */
	WP_CLI::add_command('nz-cache purge', function () {
		nz_cache_purge_site();
		WP_CLI::success('Purged ' . home_url());
	});
}
