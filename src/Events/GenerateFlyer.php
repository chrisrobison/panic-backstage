<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use Panic\Tenant\TenantContext;
use function Panic\log_activity;

/**
 * GET  /api/events/{id}/assets/generate-flyer  → preview the auto-built prompt
 * POST /api/events/{id}/assets/generate-flyer  → generate (uses body.prompt if supplied)
 *
 * Builds a prompt from the event title, lineup, and description, then
 * delegates to `codex exec` to produce a flyer PNG, which is registered
 * as an event asset (approval_status = needs_review).
 *
 * Requires the `upload_assets` capability.
 */
final class GenerateFlyer extends BaseEndpoint
{
    /** Absolute path to the codex binary. */
    private const CODEX_BIN = '/home/cdr/.nvm/versions/node/v22.22.0/bin/codex';

    /** Maximum seconds to wait for codex before giving up. */
    private const TIMEOUT = 180;

    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        if ($denied = $this->requireEventCapability($eventId, 'upload_assets')) {
            return $denied;
        }
        return match ($request->method()) {
            'GET'  => $this->preview($eventId),
            'POST' => $this->generate($eventId, $request),
            default => Response::methodNotAllowed(),
        };
    }

    /** Return the auto-built prompt so the UI can show it for editing. */
    private function preview(int $eventId): Response
    {
        $event = $this->db->one(
            'SELECT title, description_public FROM events WHERE id = ?',
            [$eventId]
        );
        if (!$event) {
            return $this->notFound();
        }
        $lineup = $this->db->all(
            "SELECT display_name FROM event_lineup
              WHERE event_id = ? AND status != 'canceled'
              ORDER BY billing_order, set_time",
            [$eventId]
        );
        return $this->ok(['prompt' => $this->buildPrompt($event, $lineup)]);
    }

    // -------------------------------------------------------------------------

    private function generate(int $eventId, Request $request): Response
    {
        $event = $this->db->one(
            'SELECT title, description_public FROM events WHERE id = ?',
            [$eventId]
        );
        if (!$event) {
            return $this->notFound();
        }

        // Use the caller-supplied prompt (from the modal textarea) if provided,
        // otherwise auto-build one from the event data.
        $customPrompt = trim((string) ($request->body('prompt') ?? ''));
        if ($customPrompt !== '') {
            $prompt = $customPrompt;
        } else {
            $lineup = $this->db->all(
                "SELECT display_name FROM event_lineup
                  WHERE event_id = ? AND status != 'canceled'
                  ORDER BY billing_order, set_time",
                [$eventId]
            );
            $prompt = $this->buildPrompt($event, $lineup);
        }

        // Permanent storage: mirror the pattern used in Assets.php
        $filename = time() . '-' . bin2hex(random_bytes(4)) . '-ai-flyer.png';
        $ctx = TenantContext::current();
        if ($ctx !== null) {
            $assetDir = $this->root . '/clients/' . $ctx->tenant['slug'] . '/assets/events/' . $eventId;
            $filePath = 'files/assets/events/' . $eventId . '/' . $filename;
        } else {
            $assetDir = $this->root . '/public/uploads/events/' . $eventId;
            $filePath = 'uploads/events/' . $eventId . '/' . $filename;
        }
        // Set up inside the try so that a filesystem failure returns a JSON
        // error naming the directory we could not create, rather than an
        // unhandled 500.
        $codexHome = null;
        $tmpDir    = null;

        try {
            $this->mustMkdir($assetDir, 0775);

            // Codex writes generated images to $CODEX_HOME/generated_images/<uuid>/
            // (not to the -C working dir), so we create codexHome here and search
            // it after the run rather than searching the working dir.
            //
            // codexHome/workdir must NOT live under the system temp dir: newer
            // Codex CLI versions refuse to install their PATH-alias helper
            // binaries (codex-linux-sandbox etc.) under a world-writable temp
            // location like /tmp, which then breaks internal sandboxed exec
            // calls (bwrap: execvp codex-linux-sandbox: No such file or
            // directory). storage/tmp is app-owned and not world-writable.
            //
            // storage/tmp must stay group-writable by the web server user
            // (2775, group www-data): when it was not, these creates failed
            // silently and codex reported the far more confusing
            // "CODEX_HOME points to ..., but that path does not exist".
            $runTmpBase = $this->root . '/storage/tmp';
            $this->mustMkdir($runTmpBase);

            $codexHome = $runTmpBase . '/codex-home-' . bin2hex(random_bytes(4));
            $this->mustMkdir($codexHome);
            $this->mustCopy('/home/cdr/.codex/auth.json',   $codexHome . '/auth.json');
            $this->mustCopy('/home/cdr/.codex/config.toml', $codexHome . '/config.toml');

            $tmpDir = $runTmpBase . '/pb-flyer-' . $eventId . '-' . bin2hex(random_bytes(4));
            $this->mustMkdir($tmpDir);

            $this->runCodex($prompt, $tmpDir, $codexHome);

            // Codex always saves to $CODEX_HOME/generated_images/<uuid>/*.png.
            // Searching all of $codexHome would match plugin icon PNGs first.
            $outFile = $this->findFirstPng($codexHome . '/generated_images');
            if ($outFile === null) {
                return Response::json(['error' => 'Codex completed but did not produce a PNG image'], 500);
            }

            if (!rename($outFile, $assetDir . '/' . $filename)) {
                throw new \RuntimeException('Could not move generated image to asset directory');
            }
        } catch (\RuntimeException $e) {
            error_log('GenerateFlyer failed for event ' . $eventId . ': ' . $e->getMessage());
            return Response::json(['error' => $e->getMessage()], 500);
        } finally {
            if ($tmpDir !== null) {
                $this->rrmdir($tmpDir);
            }
            if ($codexHome !== null) {
                $this->rrmdir($codexHome);
            }
        }

        $assetId = $this->db->insert(
            'INSERT INTO event_assets
                (event_id, asset_type, title, filename, original_filename,
                 file_path, uploaded_by_user_id, approval_status,
                 notes, generation_source, generation_prompt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $eventId,
                'flyer',
                'AI-generated flyer',
                $filename,
                'ai-flyer.png',
                $filePath,
                $this->userId(),
                'needs_review',
                null,
                'codex-ai',
                $prompt,
            ]
        );

        log_activity($this->db, $eventId, $this->userId(), 'AI flyer generated', ['asset_id' => $assetId]);

        return $this->ok([
            'id'                => $assetId,
            'file_path'         => $filePath,
            'generation_source' => 'codex-ai',
            'generation_prompt' => $prompt,
        ]);
    }

    /** Recursively delete a directory and all its contents. */
    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /**
     * Recursively find the first PNG file under $dir.
     * Codex sometimes saves images in generated_images/<uuid>/ subfolders.
     */
    private function findFirstPng(string $dir): ?string
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'png') {
                return $file->getPathname();
            }
        }
        return null;
    }

    /**
     * Build the natural-language prompt fed to codex.
     * Pulls title, bands (billing order), and public description.
     */
    private function buildPrompt(array $event, array $lineup): string
    {
        $title = $event['title'];
        $bands = implode(', ', array_column($lineup, 'display_name'));
        $desc  = trim((string) ($event['description_public'] ?? ''));
        if (mb_strlen($desc) > 300) {
            $desc = mb_substr($desc, 0, 300) . '…';
        }

        $prompt  = "Create a square (1:1 ratio) punk rock concert flyer PNG image for an event called \"{$title}\"";
        if ($bands) {
            $prompt .= " featuring {$bands}";
        }
        if ($desc) {
            $prompt .= ". Event description: {$desc}";
        }
        $prompt .= '. The flyer should have bold, high-contrast punk rock poster aesthetics'
                 . ' with striking typography, gritty textures, and vivid colors.'
                 . ' Generate exactly one image.';

        return $prompt;
    }

    /**
     * Create a directory (recursively), throwing if it could not be created.
     *
     * Guards against the race where a concurrent request creates the same
     * parent between the is_dir() check and the mkdir().
     */
    private function mustMkdir(string $dir, int $mode = 0755): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
            $err = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException("Could not create directory {$dir}: {$err}");
        }
    }

    /** Copy a file, throwing if the source is unreadable or the write fails. */
    private function mustCopy(string $from, string $to): void
    {
        if (!@copy($from, $to)) {
            $err = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException("Could not copy {$from} to {$to}: {$err}");
        }
    }

    /** Invoke `codex exec` and wait for it to finish. Throws on non-zero exit. */
    private function runCodex(string $prompt, string $workingDir, string $codexHome): void
    {
        set_time_limit(self::TIMEOUT + 30);

        $bin = self::CODEX_BIN;
        $cmd = 'HOME=' . escapeshellarg($codexHome)
             . ' CODEX_HOME=' . escapeshellarg($codexHome)
             . ' PATH=' . escapeshellarg(dirname($bin) . ':/usr/local/bin:/usr/bin:/bin')
             . ' ' . escapeshellarg($bin)
             . ' exec --skip-git-repo-check --ephemeral'
             . ' -C ' . escapeshellarg($workingDir)
             . ' -s workspace-write'
             . ' ' . escapeshellarg($prompt)
             . ' 2>&1';

        exec($cmd, $lines, $exitCode);

        if ($exitCode !== 0) {
            // Codex's fixed startup banner (version/workdir/model/sandbox
            // lines, plus any non-fatal warnings like the "could not create
            // PATH aliases" notice) can run 15-20 lines and ~500 chars on
            // its own, which used to swallow the actual failure reason
            // entirely when we only kept the first 20 lines / 500 chars.
            // Log the full output for debugging, but surface the *tail* of
            // the output in the thrown message since that's where codex
            // reports the actual error, after its banner.
            error_log('GenerateFlyer::runCodex failed (exit ' . $exitCode . "):\n" . implode("\n", $lines));
            $detail = implode("\n", array_slice($lines, -20));
            throw new \RuntimeException('Codex failed: ' . mb_substr($detail, 0, 1000));
        }
    }
}
