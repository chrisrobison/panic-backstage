<?php
declare(strict_types=1);

namespace Panic\Opportunities;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Corporate buyer contacts — humans associated with a prospect company
 * (Field Marketing Director, Events Manager, ...). Deliberately NOT the
 * existing `contacts` table (a B2C ticket-buyer marketing audience) — see
 * docs/OPPORTUNITIES-IMPLEMENTATION.md §1.15/§3.1 for why that reuse was
 * unsafe.
 *
 *   GET    /api/opportunity-companies/{companyId}/contacts              list
 *   POST   /api/opportunity-companies/{companyId}/contacts              create
 *   PATCH  /api/opportunity-companies/{companyId}/contacts/{contactId}  update
 *   DELETE /api/opportunity-companies/{companyId}/contacts/{contactId}  delete
 *
 * Dedup: normalized email is the identity when present (spec's Phase 4
 * instruction: "prefer normalized email as identity when available; do not
 * create multiple independent people solely because research found the
 * same person twice") — a second contact with the same (company_id, email)
 * is rejected rather than silently merged, matching how
 * Companies::create() handles a colliding domain.
 *
 * "Likely buyer" is computed here on every read, not stored — a
 * deterministic keyword match of `title` against the spec's own "useful
 * roles" example list — so it never goes stale relative to a manually
 * edited title and needs no migration if the keyword list changes.
 *
 * Capabilities: view_opportunities (read), manage_opportunities (write).
 */
final class Contacts extends BaseEndpoint
{
    public const STATUSES = ['active', 'cold', 'left_company', 'unknown'];

    private const WRITABLE_FIELDS = [
        'name', 'title', 'department', 'email', 'phone', 'linkedin_url',
        'status', 'source_url', 'last_touch_at',
    ];

    // Flat keyword list, not an enum — job titles are free text. Matching
    // substrings against the spec's own example role list keeps "likely
    // buyer" transparent and editable in one place instead of mysterious.
    private const BUYER_TITLE_KEYWORDS = [
        'field marketing', 'experiential marketing', 'event', 'workplace experience',
        'partner marketing', 'developer relations', 'chief of staff', 'people operations',
        'people ops', 'recruiting', 'community manager', 'executive assistant', 'hospitality',
        'marketing director', 'marketing manager',
    ];

    public function handle(Request $request): Response
    {
        $companyId = (int) ($this->params['companyId'] ?? 0);
        $contactId = $this->params['contactId'] ?? null;

        return match ($request->method()) {
            'GET'    => $this->index($companyId),
            'POST'   => $this->create($request, $companyId),
            'PATCH'  => $contactId ? $this->update($request, $companyId, (int) $contactId) : Response::methodNotAllowed(),
            'DELETE' => $contactId ? $this->deleteContact($companyId, (int) $contactId) : Response::methodNotAllowed(),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(int $companyId): Response
    {
        if ($denied = $this->requireGlobalCapability('view_opportunities')) {
            return $denied;
        }
        if (!$this->db->one('SELECT id FROM opportunity_companies WHERE id = ?', [$companyId])) {
            return $this->notFound('Company not found');
        }

        $contacts = $this->db->all(
            'SELECT * FROM opportunity_contacts WHERE company_id = ?
             ORDER BY FIELD(status, "active","unknown","cold","left_company"), name ASC',
            [$companyId]
        );

        return $this->ok(['contacts' => array_map([$this, 'hydrate'], $contacts), 'statuses' => self::STATUSES]);
    }

    private function create(Request $request, int $companyId): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }
        if (!$this->db->one('SELECT id FROM opportunity_companies WHERE id = ?', [$companyId])) {
            return $this->notFound('Company not found');
        }

        $b    = $request->body();
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            return Response::json(['error' => 'name is required'], 422);
        }

        $email = $this->normalizeEmail($b['email'] ?? null);
        if ($email !== null && $this->db->one('SELECT id FROM opportunity_contacts WHERE company_id = ? AND email = ?', [$companyId, $email])) {
            return Response::json(['error' => 'A contact with this email already exists for this company'], 422);
        }

        $status = (string) ($b['status'] ?? 'unknown');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'unknown';
        }

        $id = $this->db->insert(
            'INSERT INTO opportunity_contacts (
                company_id, name, title, department, email, phone, linkedin_url,
                status, source_url, created_by
             ) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $companyId,
                $name,
                $b['title']        ?? null,
                $b['department']   ?? null,
                $email,
                $b['phone']        ?? null,
                $b['linkedin_url'] ?? null,
                $status,
                $b['source_url']   ?? null,
                $this->userId(),
            ]
        );

        return $this->ok(['contact' => $this->hydrate($this->db->one('SELECT * FROM opportunity_contacts WHERE id = ?', [$id]))]);
    }

    private function update(Request $request, int $companyId, int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }

        $existing = $this->db->one('SELECT * FROM opportunity_contacts WHERE id = ? AND company_id = ?', [$id, $companyId]);
        if (!$existing) {
            return $this->notFound('Contact not found');
        }

        $b = $request->body();

        if (array_key_exists('status', $b) && !in_array($b['status'], self::STATUSES, true)) {
            return Response::json(['error' => 'Invalid status'], 422);
        }

        if (array_key_exists('email', $b)) {
            $email = $this->normalizeEmail($b['email']);
            $clash = $email !== null
                ? $this->db->one('SELECT id FROM opportunity_contacts WHERE company_id = ? AND email = ? AND id != ?', [$companyId, $email, $id])
                : null;
            if ($clash) {
                return Response::json(['error' => 'A contact with this email already exists for this company'], 422);
            }
            $b['email'] = $email;
        }

        $sets   = [];
        $params = [];
        foreach (self::WRITABLE_FIELDS as $field) {
            if (!array_key_exists($field, $b)) {
                continue;
            }
            $val = $b[$field];
            if ($field === 'last_touch_at') {
                $val = $val !== null && $val !== '' ? (string) $val : null;
            }
            $sets[]   = "`$field` = ?";
            $params[] = $val;
        }

        if (empty($sets)) {
            return $this->ok(['contact' => $this->hydrate($existing)]);
        }

        $params[] = $id;
        $this->db->run('UPDATE opportunity_contacts SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        return $this->ok(['contact' => $this->hydrate($this->db->one('SELECT * FROM opportunity_contacts WHERE id = ?', [$id]))]);
    }

    private function deleteContact(int $companyId, int $id): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_opportunities')) {
            return $denied;
        }
        if (!$this->db->one('SELECT id FROM opportunity_contacts WHERE id = ? AND company_id = ?', [$id, $companyId])) {
            return $this->notFound('Contact not found');
        }

        // opportunities.primary_contact_id has a real ON DELETE SET NULL FK,
        // so it's cleaned up automatically. opportunity_note_links is
        // polymorphic (no SQL FK possible against linked_id) — clear its
        // rows explicitly, same as Conferences::deleteConference() does for
        // its own linked notes.
        $this->db->run("DELETE FROM opportunity_note_links WHERE linked_type = 'contact' AND linked_id = ?", [$id]);
        $this->db->run('DELETE FROM opportunity_contacts WHERE id = ?', [$id]);

        return Response::noContent();
    }

    private function normalizeEmail(mixed $raw): ?string
    {
        $raw = trim(strtolower((string) $raw));
        return $raw !== '' ? $raw : null;
    }

    /** Adds the computed, non-persisted `is_likely_buyer` flag. */
    private function hydrate(array $contact): array
    {
        $title = strtolower((string) ($contact['title'] ?? ''));
        $isLikely = false;
        if ($title !== '') {
            foreach (self::BUYER_TITLE_KEYWORDS as $keyword) {
                if (str_contains($title, $keyword)) {
                    $isLikely = true;
                    break;
                }
            }
        }
        $contact['is_likely_buyer'] = $isLikely;
        return $contact;
    }
}
