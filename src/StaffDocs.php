<?php
declare(strict_types=1);

namespace Panic;

/**
 * Staff Handbook & Compliance — canonical documents.
 *
 * Markdown under docs/staff/** is the source of truth for content; this
 * table stores operational metadata (current published version, status,
 * which role(s) it's assigned to) and a durable, immutable record of every
 * published version's exact text (see StaffDocVersions handling below) so
 * an acknowledgment can always be tied to precisely what a person agreed
 * to, even after the file on disk changes.
 *
 *   GET  /api/staff-docs                    list documents (published only,
 *                                            unless ?all=1 and caller has
 *                                            manage_staff_docs)
 *   POST /api/staff-docs                    create a new DB-authored document
 *                                            (admin; no seed file involved)
 *   GET  /api/staff-docs/{slug}              document detail: current
 *                                            version's frozen HTML + TOC +
 *                                            the caller's acknowledgment
 *                                            (admins also get draft state)
 *   GET  /api/staff-docs/{slug}/versions     version history (admin)
 *   POST /api/staff-docs/{slug}/publish      (re)publish from the Markdown
 *                                            file on disk (admin; source=file)
 *   PUT  /api/staff-docs/{slug}/draft        save an in-progress edit,
 *                                            without publishing it (admin)
 *   POST /api/staff-docs/{slug}/publish-draft  publish the saved draft (admin)
 *   POST /api/staff-docs/{slug}/acknowledge  record the caller's
 *                                            acknowledgment of the current
 *                                            version (idempotent)
 *
 * Any authenticated user can read published documents and acknowledge them
 * — that's a login gate, not a capability, since every staff member needs
 * to be able to read what applies to them. Listing drafts, viewing version
 * history, and publishing (from either a file or a draft) require
 * manage_staff_docs (venue_admin).
 *
 * Two content sources coexist per document (`staff_documents.source`):
 * `file` documents are seeded/republished from docs/staff/** on disk — this
 * is how the shared, git-authored starter template ships (see
 * docs/staff/README.md) and is what scripts/sync-staff-docs.php /
 * seed-staff-doc-defaults.php drive. `db` documents are authored entirely
 * through the draft/publish-draft API above, with no backing file — this is
 * how a SaaS tenant customizes their own copy (seeded from the file-based
 * template at provisioning, per TenantProvisioner) or adds a document the
 * template doesn't have, without needing git/file access to the shared
 * codebase. Either source publishes into the same staff_document_versions /
 * acknowledgment tables, which are already tenant-scoped by virtue of SaaS
 * mode's per-tenant database.
 */
final class StaffDocs extends BaseEndpoint
{
    /** Directories/files under docs/staff/ that are source content, not editorial-only (audit/interview docs are intentionally excluded). */
    private const CONTENT_GLOBS = ['docs/staff/*.md', 'docs/staff/sop/*.md'];
    /**
     * Editorial/internal artifacts that live alongside the real content but
     * are never staff-facing documents themselves — the knowledge audit and
     * management interview questionnaire (see docs/staff/README.md).
     */
    private const EXCLUDED_BASENAMES = ['README.md', 'knowledge-audit.md', 'management-interview.md'];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAuth()) {
            return $denied;
        }

        $slug = $this->params['slug'] ?? null;
        $action = $this->params['action'] ?? null;

        if ($slug === null) {
            if ($request->method() === 'GET') {
                return $this->index($request);
            }
            if ($request->method() === 'POST') {
                return $this->create($request);
            }
            return Response::methodNotAllowed();
        }

        if ($action === 'versions') {
            return $request->method() === 'GET' ? $this->versions($slug) : Response::methodNotAllowed();
        }
        if ($action === 'publish') {
            return $request->method() === 'POST' ? $this->publish($slug) : Response::methodNotAllowed();
        }
        if ($action === 'draft') {
            return $request->method() === 'PUT' ? $this->saveDraft($request, $slug) : Response::methodNotAllowed();
        }
        if ($action === 'publish-draft') {
            return $request->method() === 'POST' ? $this->publishDraft($slug) : Response::methodNotAllowed();
        }
        if ($action === 'acknowledge') {
            return $request->method() === 'POST' ? $this->acknowledge($request, $slug) : Response::methodNotAllowed();
        }
        if ($action !== null) {
            return $this->notFound('Unknown staff document action');
        }

        return $request->method() === 'GET' ? $this->show($slug) : Response::methodNotAllowed();
    }

    private function index(Request $request): Response
    {
        $isAdmin = $this->hasGlobalCapability('manage_staff_docs');
        $includeDrafts = $isAdmin && $request->query('all') === '1';

        $sql = 'SELECT * FROM staff_documents';
        if (!$includeDrafts) {
            $sql .= " WHERE status = 'published'";
        }
        $sql .= ' ORDER BY document_type, title';
        $docs = $this->db->all($sql);

        $roleKey = $this->currentUserRoleKey();
        $uid = $this->userId();

        $assignments = $this->db->all('SELECT document_id, role_key, required FROM staff_document_assignments');
        $assignmentsByDoc = [];
        foreach ($assignments as $a) {
            $assignmentsByDoc[(int) $a['document_id']][] = $a;
        }

        $acks = $uid ? $this->db->all(
            'SELECT document_version_id, acknowledged_at FROM staff_document_acknowledgments WHERE user_id = ?',
            [$uid]
        ) : [];
        $ackByVersion = [];
        foreach ($acks as $a) {
            $ackByVersion[(int) $a['document_version_id']] = $a['acknowledged_at'];
        }

        $out = [];
        foreach ($docs as $doc) {
            $docId = (int) $doc['id'];
            $required = false;
            $assigned = false;
            foreach ($assignmentsByDoc[$docId] ?? [] as $a) {
                if ($a['role_key'] === 'all_staff' || ($roleKey !== null && $a['role_key'] === $roleKey)) {
                    $assigned = true;
                    $required = $required || (bool) $a['required'];
                }
            }
            $versionId = $doc['current_version_id'] !== null ? (int) $doc['current_version_id'] : null;
            $acknowledgedAt = $versionId !== null ? ($ackByVersion[$versionId] ?? null) : null;

            $out[] = [
                'id' => $docId,
                'slug' => $doc['slug'],
                'title' => $doc['title'],
                'document_type' => $doc['document_type'],
                'status' => $doc['status'],
                'source' => $doc['source'] ?? 'file',
                'current_version' => $doc['current_version'],
                'requires_acknowledgment' => (bool) $doc['requires_acknowledgment'],
                'published_at' => $doc['published_at'],
                'assigned_to_me' => $assigned,
                'required_for_me' => $required,
                'acknowledged_at' => $acknowledgedAt,
                'needs_acknowledgment' => (bool) $doc['requires_acknowledgment']
                    && $versionId !== null
                    && $acknowledgedAt === null,
            ];
        }

        return $this->ok(['documents' => $out, 'is_admin' => $isAdmin]);
    }

    /**
     * Create a new DB-authored document (source = 'db') with no backing
     * file — for a tenant adding a document the shared seed template has no
     * equivalent for. Starts as an empty draft; write it via
     * PUT .../draft and publish with POST .../publish-draft.
     */
    private function create(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_staff_docs')) {
            return $denied;
        }

        $slug = trim((string) $request->body('slug', ''));
        $title = trim((string) $request->body('title', ''));
        if ($slug === '' || $title === '') {
            return Response::json(['error' => 'slug and title are required'], 422);
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return Response::json(['error' => 'slug must contain only lowercase letters, digits, and hyphens'], 422);
        }
        $type = (string) $request->body('document_type', 'policy');
        if (!in_array($type, ['handbook', 'policy', 'sop'], true)) {
            $type = 'policy';
        }
        if ($this->db->one('SELECT id FROM staff_documents WHERE slug = ?', [$slug])) {
            return Response::json(['error' => 'A document with this slug already exists'], 409);
        }

        $requiresAck = (bool) $request->body('requires_acknowledgment', false);
        $id = $this->db->insert(
            "INSERT INTO staff_documents (slug, title, document_type, file_path, source, status, requires_acknowledgment)
             VALUES (?, ?, ?, NULL, 'db', 'draft', ?)",
            [$slug, $title, $type, $requiresAck ? 1 : 0]
        );

        return $this->ok(['document' => [
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'document_type' => $type,
            'status' => 'draft',
            'source' => 'db',
            'requires_acknowledgment' => $requiresAck,
        ]]);
    }

    private function show(string $slug): Response
    {
        $isAdmin = $this->hasGlobalCapability('manage_staff_docs');
        $doc = $this->db->one('SELECT * FROM staff_documents WHERE slug = ?', [$slug]);
        if (!$doc || ($doc['status'] !== 'published' && !$isAdmin)) {
            return $this->notFound('Staff document not found');
        }

        $version = null;
        $html = '';
        $toc = [];
        if ($doc['current_version_id'] !== null) {
            $version = $this->db->one('SELECT * FROM staff_document_versions WHERE id = ?', [(int) $doc['current_version_id']]);
            if ($version) {
                $html = $version['rendered_html'];
                $decoded = json_decode((string) $version['frontmatter_json'], true);
                $toc = $decoded['toc'] ?? [];
            }
        }

        $uid = $this->userId();
        $ack = ($uid && $version)
            ? $this->db->one(
                'SELECT acknowledged_at, version FROM staff_document_acknowledgments WHERE user_id = ? AND document_version_id = ?',
                [$uid, (int) $version['id']]
            )
            : null;

        // Draft state is only meaningful (and only exposed) to admins — it's
        // an in-progress edit, not something every staff member needs to see
        // when they're just reading the current published version.
        $draft = null;
        if ($isAdmin) {
            $draftMarkdown = $doc['draft_markdown'] ?? null;
            $draftDirty = false;
            if ($draftMarkdown !== null && trim((string) $draftMarkdown) !== '') {
                [, $draftBody] = Markdown::splitFrontmatter((string) $draftMarkdown);
                $draftDirty = $version === null || hash('sha256', $draftBody) !== $version['content_hash'];
            }
            $draft = [
                'markdown' => $draftMarkdown,
                'updated_at' => $doc['draft_updated_at'] ?? null,
                'dirty' => $draftDirty,
            ];
        }

        return $this->ok([
            'document' => [
                'id' => (int) $doc['id'],
                'slug' => $doc['slug'],
                'title' => $doc['title'],
                'document_type' => $doc['document_type'],
                'status' => $doc['status'],
                'source' => $doc['source'] ?? 'file',
                'requires_acknowledgment' => (bool) $doc['requires_acknowledgment'],
                'current_version' => $doc['current_version'],
                'published_at' => $doc['published_at'],
            ],
            'version' => $version ? [
                'id' => (int) $version['id'],
                'version' => $version['version'],
                'effective_date' => $version['effective_date'],
                'published_at' => $version['published_at'],
                'content_hash' => $version['content_hash'],
            ] : null,
            'html' => $html,
            'toc' => $toc,
            'acknowledgment' => $ack,
            'draft' => $draft,
        ]);
    }

    private function versions(string $slug): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_staff_docs')) {
            return $denied;
        }
        $doc = $this->db->one('SELECT id FROM staff_documents WHERE slug = ?', [$slug]);
        if (!$doc) {
            return $this->notFound('Staff document not found');
        }
        $rows = $this->db->all(
            'SELECT v.id, v.version, v.effective_date, v.published_at, v.content_hash, u.name published_by_name
             FROM staff_document_versions v
             LEFT JOIN users u ON u.id = v.published_by
             WHERE v.document_id = ? ORDER BY v.id DESC',
            [(int) $doc['id']]
        );
        return $this->ok(['versions' => $rows]);
    }

    private function publish(string $slug): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_staff_docs')) {
            return $denied;
        }
        $result = self::publishFromFile($this->db, $this->root ?: dirname(__DIR__), $slug, $this->userId());
        if (isset($result['error'])) {
            return Response::json(['error' => $result['error']], $result['code'] ?? 422);
        }
        return $this->ok($result);
    }

    /**
     * Save an in-progress edit without publishing it. Body: {markdown}
     * (frontmatter + body, same shape as a seed file). Any authenticated
     * document — file- or db-sourced — can take a draft; publishing it
     * (publish-draft) is what actually creates a new version and switches
     * the document over to source = 'db' semantics going forward for that
     * content (the row's `source` column itself is left alone here — it
     * only reflects how the document was first created).
     */
    private function saveDraft(Request $request, string $slug): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_staff_docs')) {
            return $denied;
        }
        $doc = $this->db->one('SELECT id FROM staff_documents WHERE slug = ?', [$slug]);
        if (!$doc) {
            return $this->notFound('Staff document not found');
        }

        $markdown = (string) $request->body('markdown', '');
        if (trim($markdown) === '') {
            return Response::json(['error' => 'markdown is required'], 422);
        }
        [$meta] = Markdown::splitFrontmatter($markdown);
        if (isset($meta['slug']) && $meta['slug'] !== $slug) {
            return Response::json(['error' => "Frontmatter slug \"{$meta['slug']}\" does not match {$slug}"], 422);
        }

        $this->db->run(
            'UPDATE staff_documents SET draft_markdown = ?, draft_updated_at = NOW(), draft_updated_by = ? WHERE id = ?',
            [$markdown, $this->userId(), (int) $doc['id']]
        );
        return $this->ok(['slug' => $slug, 'saved' => true]);
    }

    private function publishDraft(string $slug): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_staff_docs')) {
            return $denied;
        }
        $result = self::publishFromDraft($this->db, $slug, $this->userId());
        if (isset($result['error'])) {
            return Response::json(['error' => $result['error']], $result['code'] ?? 422);
        }
        return $this->ok($result);
    }

    private function acknowledge(Request $request, string $slug): Response
    {
        $uid = $this->userId();
        $doc = $this->db->one('SELECT * FROM staff_documents WHERE slug = ?', [$slug]);
        if (!$doc || $doc['status'] !== 'published' || $doc['current_version_id'] === null) {
            return Response::json(['error' => 'Document is not currently published'], 422);
        }
        $versionId = (int) $doc['current_version_id'];

        $existing = $this->db->one(
            'SELECT id, acknowledged_at, version FROM staff_document_acknowledgments WHERE user_id = ? AND document_version_id = ?',
            [$uid, $versionId]
        );
        if ($existing) {
            return $this->ok(['acknowledgment' => $existing, 'already_acknowledged' => true]);
        }

        $userAgent = substr((string) ($request->header('User-Agent') ?? ''), 0, 255);
        try {
            $this->db->run(
                'INSERT INTO staff_document_acknowledgments
                    (user_id, document_id, document_version_id, version, acknowledged_at, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, NOW(), ?, ?)',
                [$uid, (int) $doc['id'], $versionId, $doc['current_version'], Request::clientIp(), $userAgent]
            );
        } catch (\PDOException $e) {
            // Unique (user_id, document_version_id) race: someone double-clicked
            // Acknowledge from two tabs. Fall through and read back what won.
            if (!str_contains($e->getMessage(), 'uq_staff_document_ack_user_version')) {
                throw $e;
            }
        }

        $row = $this->db->one(
            'SELECT id, acknowledged_at, version FROM staff_document_acknowledgments WHERE user_id = ? AND document_version_id = ?',
            [$uid, $versionId]
        );
        return $this->ok(['acknowledgment' => $row, 'already_acknowledged' => false]);
    }

    private function currentUserRoleKey(): ?string
    {
        $uid = $this->userId();
        if (!$uid) {
            return null;
        }
        $row = $this->db->one(
            'SELECT default_role FROM staff_members WHERE user_id = ? AND active = 1 LIMIT 1',
            [$uid]
        );
        return $row['default_role'] ?? null;
    }

    // ---------------------------------------------------------------
    // Static helpers reused by scripts/sync-staff-docs.php so publishing
    // works identically from the CLI (bootstrap/seed) and from the API
    // (ongoing edits) — see docs/staff/knowledge-audit.md for why this
    // pairs with a filesystem scan rather than an admin-authored document
    // record: content is authored in Markdown/Git, not in a CMS field.
    // ---------------------------------------------------------------

    /**
     * Scan docs/staff/** for Markdown files and upsert a draft
     * staff_documents row per file (by slug from frontmatter). Never
     * touches status/current_version — that only changes via publish().
     *
     * @return list<array{slug:string,file:string,action:string}>
     */
    public static function syncFromDisk(Database $db, string $root): array
    {
        $files = [];
        foreach (self::CONTENT_GLOBS as $pattern) {
            foreach (glob($root . '/' . $pattern) ?: [] as $file) {
                if (!in_array(basename($file), self::EXCLUDED_BASENAMES, true)) {
                    $files[] = $file;
                }
            }
        }
        sort($files);

        $results = [];
        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            [$meta] = Markdown::splitFrontmatter($raw);
            $slug = $meta['slug'] ?? null;
            if (!$slug) {
                $results[] = ['slug' => '(missing)', 'file' => $file, 'action' => 'skipped-no-slug'];
                continue;
            }
            $relativePath = ltrim(str_replace($root, '', $file), '/');
            $title = $meta['title'] ?? $slug;
            $type = in_array($meta['document_type'] ?? '', ['handbook', 'policy', 'sop'], true)
                ? $meta['document_type'] : 'policy';
            $requiresAck = (bool) ($meta['requires_acknowledgment'] ?? false);

            $existing = $db->one('SELECT id, source FROM staff_documents WHERE slug = ?', [$slug]);
            if ($existing) {
                // A tenant may have created a DB-authored document (or
                // converted a seeded one into one — see publishFromDraft())
                // that happens to share a slug with a template file added or
                // changed later. Never let a disk scan clobber that.
                if (($existing['source'] ?? 'file') === 'db') {
                    $results[] = ['slug' => $slug, 'file' => $relativePath, 'action' => 'skipped-db-authored'];
                    continue;
                }
                $db->run(
                    'UPDATE staff_documents SET title = ?, document_type = ?, requires_acknowledgment = ?, file_path = ? WHERE id = ?',
                    [$title, $type, $requiresAck ? 1 : 0, $relativePath, (int) $existing['id']]
                );
                $results[] = ['slug' => $slug, 'file' => $relativePath, 'action' => 'updated'];
            } else {
                $db->insert(
                    "INSERT INTO staff_documents (slug, title, document_type, file_path, source, status, requires_acknowledgment)
                     VALUES (?, ?, ?, ?, 'file', 'draft', ?)",
                    [$slug, $title, $type, $relativePath, $requiresAck ? 1 : 0]
                );
                $results[] = ['slug' => $slug, 'file' => $relativePath, 'action' => 'created'];
            }
        }
        return $results;
    }

    /**
     * Re-read a registered document's Markdown file from disk and publish
     * it if the version number in frontmatter is new. Refuses (rather than
     * silently overwriting) if the version number is unchanged but the
     * content differs — a published version's frozen text must never
     * change under an acknowledgment that already points at it; bump the
     * version number in frontmatter to publish an edit.
     *
     * @return array{error:string,code?:int}|array<string,mixed>
     */
    public static function publishFromFile(Database $db, string $root, string $slug, ?int $publishedBy): array
    {
        $doc = $db->one('SELECT * FROM staff_documents WHERE slug = ?', [$slug]);
        if (!$doc) {
            return ['error' => 'Document not registered — run scripts/sync-staff-docs.php first', 'code' => 404];
        }
        if (empty($doc['file_path'])) {
            return ['error' => 'Document has no source file (source = db) — use the draft/publish-draft API instead', 'code' => 422];
        }
        $path = $root . '/' . $doc['file_path'];
        if (!is_file($path)) {
            return ['error' => "Source file not found: {$doc['file_path']}", 'code' => 404];
        }
        $raw = file_get_contents($path);
        [$meta, $body] = Markdown::splitFrontmatter((string) $raw);
        return self::publishBody($db, $doc, $meta, $body, $publishedBy);
    }

    /**
     * Publish a document's saved draft_markdown (see StaffDocs::saveDraft())
     * — the DB-authored counterpart to publishFromFile(), same version-bump/
     * hash rules, same staff_document_versions/acknowledgment machinery.
     *
     * @return array{error:string,code?:int}|array<string,mixed>
     */
    public static function publishFromDraft(Database $db, string $slug, ?int $publishedBy): array
    {
        $doc = $db->one('SELECT * FROM staff_documents WHERE slug = ?', [$slug]);
        if (!$doc) {
            return ['error' => 'Document not registered', 'code' => 404];
        }
        $raw = $doc['draft_markdown'] ?? null;
        if ($raw === null || trim((string) $raw) === '') {
            return ['error' => 'No draft saved for this document yet — save one with PUT .../draft first', 'code' => 422];
        }
        [$meta, $body] = Markdown::splitFrontmatter((string) $raw);
        return self::publishBody($db, $doc, $meta, $body, $publishedBy);
    }

    /**
     * Shared publish tail for both publishFromFile() and publishFromDraft():
     * refuses to overwrite an existing version's frozen text under the same
     * version number (see publishFromFile() docblock for why), otherwise
     * inserts a new immutable staff_document_versions row and marks it
     * current. $doc is the pre-fetched staff_documents row (source-agnostic
     * beyond that).
     *
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $meta
     * @return array{error:string,code?:int}|array<string,mixed>
     */
    private static function publishBody(Database $db, array $doc, array $meta, string $body, ?int $publishedBy): array
    {
        $slug = $doc['slug'];
        $version = (string) ($meta['version'] ?? '0.1');
        $hash = hash('sha256', $body);

        $existingVersion = $db->one(
            'SELECT * FROM staff_document_versions WHERE document_id = ? AND version = ?',
            [(int) $doc['id'], $version]
        );

        if ($existingVersion) {
            if ($existingVersion['content_hash'] === $hash) {
                // Idempotent re-run: make sure it's marked current, nothing else to do.
                if ((int) $doc['current_version_id'] !== (int) $existingVersion['id']) {
                    $db->run(
                        "UPDATE staff_documents SET current_version = ?, current_version_id = ?, status = 'published' WHERE id = ?",
                        [$version, (int) $existingVersion['id'], (int) $doc['id']]
                    );
                }
                return ['status' => 'unchanged', 'slug' => $slug, 'version' => $version];
            }
            return [
                'error' => "Version {$version} was already published with different text. Bump the `version:` field before republishing.",
                'code' => 409,
            ];
        }

        $rendered = Markdown::render($body);
        $html = self::rewriteCrossDocLinks($rendered['html']);
        $effectiveDate = $meta['effective_date'] ?? null;
        $frontmatterJson = json_encode(['frontmatter' => $meta, 'toc' => $rendered['toc']], JSON_UNESCAPED_SLASHES);

        $versionId = $db->insert(
            'INSERT INTO staff_document_versions
                (document_id, version, content_hash, frontmatter_json, source_markdown, rendered_html, effective_date, published_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $doc['id'], $version, $hash, $frontmatterJson, $body, $html, $effectiveDate ?: null, $publishedBy]
        );

        $title = $meta['title'] ?? $doc['title'];
        $type = in_array($meta['document_type'] ?? '', ['handbook', 'policy', 'sop'], true) ? $meta['document_type'] : $doc['document_type'];
        $requiresAck = array_key_exists('requires_acknowledgment', $meta) ? (bool) $meta['requires_acknowledgment'] : (bool) $doc['requires_acknowledgment'];

        $db->run(
            "UPDATE staff_documents
                SET current_version = ?, current_version_id = ?, status = 'published', published_at = NOW(),
                    title = ?, document_type = ?, requires_acknowledgment = ?
              WHERE id = ?",
            [$version, $versionId, $title, $type, $requiresAck ? 1 : 0, (int) $doc['id']]
        );

        return ['status' => 'published', 'slug' => $slug, 'version' => $version, 'version_id' => $versionId];
    }

    /**
     * Content is authored with plain Git-relative Markdown links between
     * documents (e.g. "sop/bartender.md", "../staff/alcohol-service.md") so
     * it reads and cross-links correctly on GitHub too. Rewrite those into
     * this app's `#staff-docs-<slug>` hash routes so the same links also
     * work as real in-app navigation once rendered. Slug is derived purely
     * from path shape (docs/staff/sop/* -> "sop-<name>", everything else ->
     * "<name>") rather than a DB lookup, since this runs at render time
     * against arbitrary link text, not just known-registered slugs.
     */
    private static function rewriteCrossDocLinks(string $html): string
    {
        return preg_replace_callback(
            '/href="([^"#]*?)([a-z0-9-]+)\.md(#[a-z0-9-]+)?"/i',
            static function (array $m): string {
                [$whole, $dir, $name] = $m;
                // Leave absolute/external links alone -- only rewrite what
                // looks like a relative reference to another staff doc.
                if (preg_match('#^(https?:)?//#i', $dir)) {
                    return $whole;
                }
                $slug = str_contains($dir, 'sop/') ? "sop-{$name}" : $name;
                return 'href="#staff-docs-' . $slug . '"';
            },
            $html
        ) ?? $html;
    }

    /**
     * Re-render every document's CURRENT version's rendered_html/TOC from
     * its already-frozen source_markdown (not the file on disk) using
     * today's Markdown renderer. For fixing a renderer bug's downstream
     * effect without touching source_markdown/content_hash/version number
     * — so it never invalidates an existing acknowledgment (which is tied
     * to the text, not to how it happens to be rendered).
     *
     * @return list<string> slugs updated
     */
    public static function rerenderCurrentVersions(Database $db): array
    {
        $rows = $db->all(
            'SELECT d.slug, v.id AS version_id, v.source_markdown
             FROM staff_documents d
             JOIN staff_document_versions v ON v.id = d.current_version_id'
        );
        $updated = [];
        foreach ($rows as $row) {
            $rendered = Markdown::render($row['source_markdown']);
            $html = self::rewriteCrossDocLinks($rendered['html']);
            $existing = $db->one('SELECT frontmatter_json FROM staff_document_versions WHERE id = ?', [(int) $row['version_id']]);
            $decoded = json_decode((string) $existing['frontmatter_json'], true) ?: [];
            $decoded['toc'] = $rendered['toc'];
            $db->run(
                'UPDATE staff_document_versions SET rendered_html = ?, frontmatter_json = ? WHERE id = ?',
                [$html, json_encode($decoded, JSON_UNESCAPED_SLASHES), (int) $row['version_id']]
            );
            $updated[] = $row['slug'];
        }
        return $updated;
    }
}
