# Dedicated FPM pool for realtime on panicbooking.com/backstage

Status: **prepared, not applied.** Everything here needs root; apply it by
hand and verify with the checks at the bottom.

Config files live in `deploy/php-fpm/panic-www-data.conf` and
`deploy/apache/panic-fpm-handler.conf`.

## Why

`docs/realtime-data.md`'s realtime stream (`GET /api/realtime/stream`,
opt-in via `REALTIME_ENABLED`) pins one PHP-FPM worker per open browser tab
for up to `REALTIME_STREAM_TTL_SECONDS` (~55s), reconnecting immediately
when that ends. This host's actual PHP-FPM setup is the stock
`/etc/php/8.2/fpm/pool.d/www.conf` — `pm = dynamic`, `pm.max_children = 5`,
shared by every vhost on the box (~60 sites, not just Backstage). A handful
of open Backstage tabs would exhaust that shared pool and take every other
site on the host down with it, not just Backstage. This is a separate,
smaller decision than `docs/php-fpm-cdr-pool.md`'s pool: same `www-data`
user as today, sized only to fix this capacity problem. Install only one of
`deploy/php-fpm/panic.conf` (cdr) or `deploy/php-fpm/panic-www-data.conf`
(this one) — never both, they'd fight over the same socket path.

## Scope

This covers `panicbooking.com/backstage` only (the `panicbooking.com-le-ssl.conf`
vhost). `app.panicbackstage.com` already has `REALTIME_ENABLED=true` set in
its own `.env` (see that checkout's `.env`, set 2026-08-12 "so this dev/test
box's server continues to exercise the SSE stream") and is drawing from the
same shared `www` pool today — worth pointing this same pool (or a second
dedicated one) at that vhost too, but that's a separate decision; not done
here.

## Apply

```bash
cd /home/cdr/domains/panicbooking.com/www/backstage

# 1. Install the pool + the shared Apache snippet
sudo install -m 644 deploy/php-fpm/panic-www-data.conf /etc/php/8.2/fpm/pool.d/panic-www.conf
sudo install -m 644 deploy/apache/panic-fpm-handler.conf /etc/apache2/panic-fpm-handler.conf

# 2. Validate the pool BEFORE touching Apache
sudo php-fpm8.2 -t

# 3. Route panicbooking.com to the new pool: add
#      Include /etc/apache2/panic-fpm-handler.conf
#    inside the <VirtualHost *:443> block in
#    /etc/apache2/sites-available/panicbooking.com-le-ssl.conf
#    (the :80 vhost only 301s to HTTPS and serves no PHP — leave it alone)
sudo apache2ctl configtest

# 4. Reload (pool first, so the socket exists before Apache routes to it)
sudo systemctl reload php8.2-fpm
sudo systemctl reload apache2
```

## Verify

```bash
# Pool is up, workers still run as www-data (no ownership change)
sudo systemctl status php8.2-fpm --no-pager | grep -A2 panic-www
ls -l /run/php/php8.2-panic.sock

# Site still serves ordinary traffic through the new pool
curl -sI https://panicbooking.com/backstage/ | head -1

# Confirm requests are actually landing on the new pool, not the old one —
# php-fpm8.2's status page (if enabled) or simply:
sudo systemctl reload php8.2-fpm && sudo journalctl -u php8.2-fpm --since "1 min ago" | grep panic-www
```

Then flip the feature flag:

```bash
# In /home/cdr/domains/panicbooking.com/www/backstage/.env
REALTIME_ENABLED=true
```

No code change, no reload needed for the flag itself — `Env::load()` runs
on every request. Confirm end to end:

```bash
# From a logged-in browser tab, or:
node tests/ui/run.mjs   # 115-realtime-data-layer.test.mjs asserts
                         # window.PBData.getRealtimeState().state === 'connected'
```

Watch `pm.max_children` headroom for the first few days with real staff
usage — `sudo systemctl status php8.2-fpm` / a process-manager status page
if you add one — before considering it fully proven at your actual
concurrent-tab count.

## Rollback

Two independent levers, either alone is enough:

- **Turn the feature back off:** set `REALTIME_ENABLED=false` (or delete
  the line) in `.env`. Immediate, no reload — the endpoint 404s again and
  clients fall back to direct HTTP, exactly as designed.
- **Un-route the dedicated pool:** comment out the `SetHandler` line in
  `/etc/apache2/panic-fpm-handler.conf` and `sudo systemctl reload apache2`.
  Traffic falls back to the shared `www` pool. The `panic-www` pool file can
  stay in place unused.
