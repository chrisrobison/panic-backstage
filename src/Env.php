<?php
declare(strict_types=1);

namespace Panic;

final class Env
{
    /**
     * Read a single environment value. Instance accessor so services can be
     * constructed with an injected Env (per the payment-provider contract)
     * rather than reaching for getenv() globally. Values are populated by
     * load() into $_ENV/putenv(), so this simply reads them back.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, "\"'");
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }

    /**
     * Rewrite a small, caller-chosen set of KEY=value lines in a .env-style
     * overlay file, leaving every other line byte-for-byte untouched. This
     * is the only supported way to edit env values from a web request (see
     * AppSettings::put()) — callers must pass an explicit allow-list of
     * keys; there is no path from arbitrary user input to an arbitrary env
     * key here.
     *
     * Written for an *overlay* file (loaded after the real .env — see
     * Kernel::boot()), so a blank value means "no override" rather than
     * "set to empty": an empty string in $updates removes that key's line
     * entirely (falling back to whatever the base .env says) instead of
     * writing `KEY=` and clobbering the real value with blank. If you ever
     * point this at a file that has no separate "base" to fall back to,
     * this distinction won't do what you want — it was written for the
     * overlay case specifically.
     *
     * - Existing `KEY=...` lines are replaced in place (or dropped, for an
     *   empty value), preserving the order of everything else.
     * - Keys in $updates not already present in the file are appended at the
     *   end under a single "# App Settings" comment (added once); a new key
     *   with an empty value is simply skipped — nothing to override yet.
     * - Values may not contain newlines (would inject extra, unintended env
     *   lines) — throws if one does. Any literal double quote is stripped
     *   (this format doesn't support escaping); values containing a space
     *   are then wrapped in double quotes so they still parse as one value.
     * - Written via a temp file + rename() in the same directory, so a
     *   request that dies mid-write can never leave a half-written file.
     */
    public static function updateKeys(string $file, array $updates): void
    {
        if (!$updates) {
            return;
        }
        foreach ($updates as $key => $value) {
            if (str_contains((string) $value, "\n") || str_contains((string) $value, "\r")) {
                throw new \InvalidArgumentException("Env value for $key may not contain a newline");
            }
        }

        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
        $remaining = $updates;
        $kept = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            $isSetLine = $trimmed !== '' && !str_starts_with($trimmed, '#') && str_contains($trimmed, '=');
            $key = $isSetLine ? trim(explode('=', $trimmed, 2)[0]) : null;
            if ($key !== null && array_key_exists($key, $remaining)) {
                $value = (string) $remaining[$key];
                unset($remaining[$key]);
                if ($value === '') {
                    continue; // empty = clear the override; drop the line
                }
                $kept[] = self::formatLine($key, $value);
                continue;
            }
            $kept[] = $line;
        }
        $lines = $kept;

        // Remaining = keys not already present as a line. Nothing to do for
        // ones being cleared to empty — there's no override to add.
        $remaining = array_filter($remaining, static fn ($value) => (string) $value !== '');
        if ($remaining) {
            // Add the header once; later calls that introduce a further new
            // key (one not previously written here) just append under the
            // same block instead of stacking up repeat headers.
            if (!in_array('# App Settings', array_map('trim', $lines), true)) {
                if ($lines) {
                    $lines[] = '';
                }
                $lines[] = '# App Settings';
            }
            foreach ($remaining as $key => $value) {
                $lines[] = self::formatLine($key, (string) $value);
            }
        }

        $dir = dirname($file);
        $tmp = @tempnam($dir, '.env.tmp');
        if ($tmp === false) {
            throw new \RuntimeException("Could not create a temp file in $dir to write .env safely");
        }
        if (file_put_contents($tmp, implode("\n", $lines) . "\n") === false) {
            @unlink($tmp);
            throw new \RuntimeException("Could not write to $tmp");
        }
        // tempnam() defaults to mode 0600 (owner-only), which would make a
        // brand-new file unreadable by anything but this one PHP-FPM worker
        // user — match the existing file's mode if there is one, else a
        // sane shared default (this directory is group-writable on purpose;
        // see storage/config's permissions).
        @chmod($tmp, is_file($file) ? (fileperms($file) & 0777) : 0664);
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not save $file (rename failed)");
        }

        // Deliberately doesn't try to patch $_ENV/putenv() here for
        // "immediate effect" — a cleared key needs the *base* .env's value
        // restored, not blanked, and this function only knows about the one
        // overlay file it just wrote, not where the base file lives or
        // what's in it. Callers that need the rest of the current request
        // to see fresh values should re-run their normal load sequence
        // (base file, then this overlay) right after calling this — see
        // AppSettings::put(), which does exactly that.
    }

    private static function formatLine(string $key, string $value): string
    {
        $value = str_replace('"', '', $value);
        if ($value !== '' && (str_contains($value, ' ') || str_contains($value, '#'))) {
            $value = '"' . $value . '"';
        }
        return "$key=$value";
    }
}
