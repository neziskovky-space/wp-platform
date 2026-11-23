# wp-platform

Shared WordPress infrastructure for the neziskovky.space multisite (Apache 2.4 + PHP-FPM, `/srv/www/wordpress`).

## nz-cache

Static page cache that replaces WP Rocket. PHP writes `wp-content/cache/nz/{host}/{path}/index.html`; Apache serves it via `.htaccess` without starting PHP (`X-NZ-Cache: HIT` header).

- **Cached:** anonymous HTTPS GET, no query string, 200 HTML responses.
- **Never cached:** logged-in users, WooCommerce cart/checkout/account, carts and sessions (cookies), search, 404, previews, password-protected posts, responses with `Set-Cookie` or `Cache-Control: no-cache`, `DONOTCACHEPAGE`.
- **Purge:** whole site on publish/update/delete, menu, term, widget, theme, plugin and Oxygen changes; admin bar "Vymazať cache"; `wp --url=<site> nz-cache purge`.
- **Coexistence:** does nothing on a site while WP Rocket is active there, so sites migrate one by one.

### Install

1. Copy `mu-plugins/nz-cache.php` to `/srv/www/wordpress/wp-content/mu-plugins/`.
2. Paste `htaccess/nz-cache.conf` at the top of `/srv/www/wordpress/.htaccess`.
3. Expiry (pages hold nonces and dated content), in the crontab of a `www-data` group member:
   ```
   */15 * * * * find /srv/www/wordpress/wp-content/cache/nz -name index.html -mmin +600 -delete
   ```

### Migrate a site from WP Rocket

```
wp --url=<site> plugin deactivate wp-rocket
curl -sI https://<site>/ ; curl -sI https://<site>/ | grep X-NZ-Cache   # first request writes, second is a HIT
```

When no site runs WP Rocket: delete the plugin, `wp-content/advanced-cache.php`, the `WP_CACHE` line in `wp-config.php` and the WP Rocket block in `.htaccess`.

### Test

```
php -d zend.assertions=1 -d assert.exception=1 tests/nz-cache-test.php
```

## perf baseline

`scripts/perf-baseline.sh` measures every live site's homepage into `metrics/<date>.csv`: server-side TTFB from nz-cache and with a full PHP render (median of 5, measured on the server incl. TLS), plus one Lighthouse mobile lab run (score, FCP, LCP, TBT, CLS, page weight, requests). Baseline: `metrics/2026-11-23-baseline.csv` (right after the WP Rocket -> nz-cache switch).

```
scripts/perf-baseline.sh                       # ~15 min, writes metrics/YYYY-MM-DD.csv
python3 scripts/perf-compare.py metrics/2026-11-23-baseline.csv metrics/YYYY-MM-DD.csv
```

Lighthouse is a single lab run: treat differences under ~10% (score +-5) as noise.

## License

GPL-2.0-or-later
