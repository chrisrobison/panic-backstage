# Running PHP-FPM as `cdr` for the Backstage domains

Status: **prepared, not applied.** Everything here needs root; apply it by
hand and verify with the checks at the bottom.

Config files live in `deploy/php-fpm/panic.conf` and
`deploy/apache/panic-fpm-handler.conf`.

## Why

The web tier runs as `www-data`; everything else in this app's world runs as
`cdr`. That split is the root cause of a recurring class of bug:

- **The AI CLIs re-lock their own credentials.** The app shells out to
  `codex` and `claude` to ride their OAuth sessions. Both rewrite their
  credential files on token refresh and reset them to mode `0600`, which
  cuts off a `www-data` reader. `www-data` is in the `cdr` group, so the
  workaround has been "keep chmod'ing them group-readable" — but only the
  owner can chmod, and a `www-data` process cannot fix its own access.
  - `scripts/cron-fix-claude-cli-perms.sh` exists solely for this, running
    **every minute** to re-open `~/.claude/.credentials.json`.
  - The same failure hit `~/.codex/config.toml` on 2026-08-05 and broke AI
    flyer generation until commit `520e271`.
  - `~/.codex/auth.json` is the same trap, currently unsprung.
- **Split file ownership.** Cron jobs write as `cdr`, the web tier writes as
  `www-data`, into the same trees. There are ~2700 `www-data`-owned files in
  this checkout. Files the web tier creates cannot be deleted by `cdr`
  without `sudo` (e.g. orphaned upload dirs under `public/uploads/events/`),
  and `public/uploads` has been left `0777` to paper over it.

Running these vhosts as `cdr` removes the mismatch rather than patching each
symptom.

## The tradeoff — read this before applying

A PHP process running as `cdr` can read and write **everything `cdr` owns**:
all other sites under `/home/cdr/domains`, their `.env` files, and
`~/.ssh/id_*`.

The honest one-liner: **private SSH keys at `0600` are protected from
`www-data` today and would not be protected from a compromised app running
as `cdr`.**

The delta is smaller than it first looks — `www-data` is already in the `cdr`
group, so a compromise today already reads every group-readable file,
including other sites' code and the AI credentials. What's genuinely new is
*write* access to those trees, and read access to the `0600` files.

`deploy/php-fpm/panic.conf` ships a commented-out `open_basedir` as partial
mitigation. Note its limit: it constrains **PHP's own file functions only**,
not the `codex`/`claude` subprocesses the app `exec()`s. It also breaks the
app if the path list drifts. Optional, not a substitute for the above.

## Scope: both docroots move together

`panicbooking.com/www/backstage` and `panicbackstage.com/app` are separate
checkouts of the same codebase against the same production database.
Converting one and not the other recreates the mixed-ownership mess between
the two surfaces. Do both.

Blocks needing `Include /etc/apache2/panic-fpm-handler.conf`:

| File | VirtualHost | Note |
|---|---|---|
| `panicbooking.com-le-ssl.conf` | `panicbooking.com` :443 | main site |
| `panicbackstage.com.conf` | `www.panicbackstage.com` :80 | **serves PHP — no HTTPS redirect** |
| `panicbackstage.com.conf` | `app.panicbackstage.com` :80 | **serves PHP — no HTTPS redirect** |
| `panicbackstage.com-le-ssl.conf` | `www.panicbackstage.com` :443 | |
| `panicbackstage.com-le-ssl.conf` | `app.panicbackstage.com` :443 | |

`panicbooking.com.conf` (:80) only 301s to HTTPS and serves no PHP, so it
does not need the include; adding it anyway is harmless.

## Apply

```bash
cd /home/cdr/domains/panicbooking.com/www/backstage

# 1. Session dir for the pool (super-admin UI uses PHP sessions)
mkdir -p storage/sessions && chmod 700 storage/sessions

# 2. Install the pool + the shared Apache snippet
sudo install -m 644 deploy/php-fpm/panic.conf /etc/php/8.2/fpm/pool.d/panic.conf
sudo install -m 644 deploy/apache/panic-fpm-handler.conf /etc/apache2/panic-fpm-handler.conf

# 3. Validate the pool BEFORE touching Apache
sudo php-fpm8.2 -t

# 4. Add `Include /etc/apache2/panic-fpm-handler.conf` inside each of the
#    five VirtualHost blocks listed above, then validate
sudo apache2ctl configtest

# 5. Hand ownership of both checkouts to cdr
sudo chown -R cdr:cdr /home/cdr/domains/panicbooking.com/www/backstage
sudo chown -R cdr:cdr /home/cdr/domains/panicbackstage.com/app

# 6. Reload (pool first, so the socket exists before Apache routes to it)
sudo systemctl reload php8.2-fpm
sudo systemctl reload apache2
```

## Verify

```bash
# Workers running as cdr
ps -eo user,cmd | grep '[p]ool panic'

# Socket owned by www-data (Apache must be able to connect)
ls -l /run/php/php8.2-panic.sock

# Both surfaces still serve
curl -sI https://panicbooking.com/backstage/ | head -1
curl -sI https://app.panicbackstage.com/ | head -1
```

Then exercise a write path (upload an asset, or generate an AI flyer) and
confirm the new file is `cdr:cdr`, not `www-data`.

## Rollback

Comment out the `SetHandler` line in `/etc/apache2/panic-fpm-handler.conf`
and `sudo systemctl reload apache2`. Traffic falls back to the global
handler and the shared `www` pool. The pool file can stay in place unused.

## Cleanup this unlocks

Once verified:

- Delete `scripts/cron-fix-claude-cli-perms.sh` and its crontab line
  (`* * * * *`), then `chmod 600 ~/.claude/.credentials.json` — the sweep
  exists only to serve a `www-data` reader.
- `~/.codex/auth.json` can go back to `0600`; the latent breakage is gone.
  The `chmod 640` remedy noted in `src/Events/GenerateFlyer.php` becomes
  stale and can be trimmed.
- `chmod 755 public/uploads` — the `0777` was cross-user paperwork.
- Delete orphaned upload dirs without `sudo` (`public/uploads/events/`
  currently has six with no matching event row).

Keep the app-owned `config.toml` in `GenerateFlyer` regardless — not
depending on a personal dotfile is correct even when it is readable.

## One unrelated thing noticed

`app.panicbackstage.com` has a **different DocumentRoot per port**:
`/app/public` on :80 but `/app` on :443. The :443 exposure is mitigated by a
`DirectoryMatch` denying `.git|src|database|tests|scripts`, but the two
should probably agree on `/app/public`. Not touched here.
