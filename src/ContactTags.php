<?php
declare(strict_types=1);

namespace Panic;

/**
 * Free-form tag definitions for contacts (e.g. "VIP", "Engaged", "Customer")
 * — the colored pills shown on a contact and in the ListMaster member table
 * (public/assets/listmaster.js). This class owns the tag *definitions*
 * (name/color) only; assigning/unassigning a tag to a specific contact goes
 * through Contacts' own sub-resource (GET/POST /contacts/{id}/tags,
 * DELETE /contacts/{id}/tags/{tagId}, POST /contacts/bulk-tag) so there's
 * exactly one place that writes contact_tag_assignments rows, same split as
 * MailingLists vs list_membership.
 *
 *   GET    /api/contact-tags        list all tags (+ usage count)
 *   POST   /api/contact-tags        create {name, color?}
 *   PATCH  /api/contact-tags/{id}   update {name?, color?}
 *   DELETE /api/contact-tags/{id}   delete (assignments cascade)
 *
 * Gated by the manage_campaigns global capability — same feature group as
 * Contacts/MailingLists/Campaigns (Lists & tags are used together to build
 * campaign audiences).
 */
final class ContactTags extends BaseEndpoint
{
    private const DEFAULT_COLOR = '#2563eb';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_campaigns')) {
            return $denied;
        }

        $id = $this->params['tagId'] ?? null;
        return match ($request->method()) {
            'GET'    => $id ? $this->show((int) $id) : $this->index(),
            'POST'   => $this->create($request),
            'PATCH'  => $this->update($request, (int) $id),
            'DELETE' => $this->delete((int) $id),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(): Response
    {
        $tags = $this->db->all(
            'SELECT ct.*, COUNT(cta.contact_id) AS usage_count
             FROM contact_tags ct
             LEFT JOIN contact_tag_assignments cta ON cta.tag_id = ct.id
             GROUP BY ct.id
             ORDER BY ct.name'
        );
        return $this->ok(['tags' => $tags]);
    }

    private function show(int $id): Response
    {
        $tag = $this->db->one(
            'SELECT ct.*, COUNT(cta.contact_id) AS usage_count
             FROM contact_tags ct
             LEFT JOIN contact_tag_assignments cta ON cta.tag_id = ct.id
             WHERE ct.id = ?
             GROUP BY ct.id',
            [$id]
        );
        if (!$tag) {
            return $this->notFound('Tag not found');
        }
        return $this->ok(['tag' => $tag]);
    }

    private function create(Request $request): Response
    {
        $name = trim((string) $request->body('name', ''));
        if ($name === '') {
            return Response::json(['error' => 'A tag name is required'], 422);
        }
        $color = $this->normalizeColor((string) $request->body('color', ''));

        try {
            $id = $this->db->insert(
                'INSERT INTO contact_tags (name, color) VALUES (?, ?)',
                [$name, $color]
            );
        } catch (\PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                return Response::json(['error' => 'A tag with that name already exists'], 422);
            }
            throw $e;
        }

        return $this->ok(['tag' => $this->db->one('SELECT *, 0 AS usage_count FROM contact_tags WHERE id = ?', [$id])]);
    }

    private function update(Request $request, int $id): Response
    {
        if (!$id) return $this->notFound();
        $existing = $this->db->one('SELECT id FROM contact_tags WHERE id = ?', [$id]);
        if (!$existing) return $this->notFound('Tag not found');

        $fields = [];
        $params = [];
        if ($request->body('name') !== null) {
            $name = trim((string) $request->body('name', ''));
            if ($name === '') {
                return Response::json(['error' => 'A tag name is required'], 422);
            }
            $fields[] = 'name = ?';
            $params[] = $name;
        }
        if ($request->body('color') !== null) {
            $fields[] = 'color = ?';
            $params[] = $this->normalizeColor((string) $request->body('color', ''));
        }
        if ($fields === []) {
            return $this->show($id);
        }

        $params[] = $id;
        try {
            $this->db->run('UPDATE contact_tags SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
        } catch (\PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                return Response::json(['error' => 'A tag with that name already exists'], 422);
            }
            throw $e;
        }
        return $this->show($id);
    }

    private function delete(int $id): Response
    {
        if (!$id) return $this->notFound();
        $existing = $this->db->one('SELECT id FROM contact_tags WHERE id = ?', [$id]);
        if (!$existing) return $this->notFound('Tag not found');
        $this->db->run('DELETE FROM contact_tags WHERE id = ?', [$id]);
        return Response::noContent();
    }

    private function normalizeColor(string $color): string
    {
        $color = trim($color);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : self::DEFAULT_COLOR;
    }

    private function isDuplicateKey(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 || $e->getCode() === '23000';
    }
}
