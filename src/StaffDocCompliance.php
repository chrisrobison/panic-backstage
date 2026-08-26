<?php
declare(strict_types=1);

namespace Panic;

/**
 * Management compliance overview: for every active staff member, which
 * assigned documents are acknowledged (and at what version) and which
 * certifications are current/expiring/expired.
 *
 *   GET /api/staff-compliance
 *
 * Admin-only (manage_staff_docs). Read-only aggregate — filtering (by
 * role, missing acknowledgment, expiring certification, etc.) is left to
 * the frontend table, same convention as other admin list views in this
 * app (see ui-conventions memory).
 */
final class StaffDocCompliance extends BaseEndpoint
{
    /** Certifications expiring within this many days are flagged "expiring soon". */
    private const EXPIRING_SOON_DAYS = 30;

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_staff_docs')) {
            return $denied;
        }
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        $staffRows = $this->db->all(
            "SELECT s.id, s.name, s.default_role, s.active, s.user_id
             FROM staff_members s
             ORDER BY s.active DESC, s.name"
        );

        $publishedDocs = $this->db->all(
            "SELECT id, slug, title, document_type, current_version, current_version_id, requires_acknowledgment
             FROM staff_documents WHERE status = 'published' ORDER BY document_type, title"
        );

        $assignments = $this->db->all('SELECT document_id, role_key, required FROM staff_document_assignments');
        $assignmentsByDoc = [];
        foreach ($assignments as $a) {
            $assignmentsByDoc[(int) $a['document_id']][] = $a;
        }

        // All acknowledgments, keyed by "userId:versionId" for O(1) lookups below.
        $acks = $this->db->all('SELECT user_id, document_version_id, acknowledged_at, version FROM staff_document_acknowledgments');
        $ackIndex = [];
        foreach ($acks as $a) {
            $ackIndex[$a['user_id'] . ':' . $a['document_version_id']] = $a;
        }

        $certs = $this->db->all(
            'SELECT c.staff_member_id, c.expires_at, c.issued_at, t.name type_name, t.slug type_slug, t.expiration_required
             FROM staff_certifications c JOIN staff_certification_types t ON t.id = c.certification_type_id'
        );
        $certsByStaff = [];
        foreach ($certs as $c) {
            $certsByStaff[(int) $c['staff_member_id']][] = $c;
        }

        $today = new \DateTimeImmutable('today');
        $out = [];
        foreach ($staffRows as $staff) {
            $roleKey = $staff['default_role'];
            $uid = $staff['user_id'] !== null ? (int) $staff['user_id'] : null;

            $docStatuses = [];
            foreach ($publishedDocs as $doc) {
                $docId = (int) $doc['id'];
                $applies = false;
                $required = false;
                foreach ($assignmentsByDoc[$docId] ?? [] as $a) {
                    if ($a['role_key'] === 'all_staff' || $a['role_key'] === $roleKey) {
                        $applies = true;
                        $required = $required || (bool) $a['required'];
                    }
                }
                if (!$applies) {
                    continue;
                }
                $ack = ($uid && $doc['current_version_id'])
                    ? ($ackIndex[$uid . ':' . $doc['current_version_id']] ?? null)
                    : null;
                $docStatuses[] = [
                    'slug' => $doc['slug'],
                    'title' => $doc['title'],
                    'required' => $required,
                    'current_version' => $doc['current_version'],
                    'acknowledged' => $ack !== null,
                    'acknowledged_version' => $ack['version'] ?? null,
                    'acknowledged_at' => $ack['acknowledged_at'] ?? null,
                    'no_login' => $uid === null,
                ];
            }

            $certStatuses = [];
            foreach ($certsByStaff[(int) $staff['id']] ?? [] as $c) {
                $status = 'current';
                if ($c['expires_at']) {
                    $expires = new \DateTimeImmutable($c['expires_at']);
                    $daysLeft = (int) $today->diff($expires)->format('%r%a');
                    if ($daysLeft < 0) {
                        $status = 'expired';
                    } elseif ($daysLeft <= self::EXPIRING_SOON_DAYS) {
                        $status = 'expiring_soon';
                    }
                } elseif ($c['expiration_required']) {
                    $status = 'missing_expiration';
                }
                $certStatuses[] = [
                    'type' => $c['type_name'],
                    'slug' => $c['type_slug'],
                    'issued_at' => $c['issued_at'],
                    'expires_at' => $c['expires_at'],
                    'status' => $status,
                ];
            }

            $out[] = [
                'staff_member_id' => (int) $staff['id'],
                'name' => $staff['name'],
                'role' => $roleKey,
                'active' => (bool) $staff['active'],
                'has_login' => $uid !== null,
                'documents' => $docStatuses,
                'certifications' => $certStatuses,
            ];
        }

        return $this->ok([
            'staff' => $out,
            'documents' => array_map(fn($d) => ['slug' => $d['slug'], 'title' => $d['title'], 'document_type' => $d['document_type']], $publishedDocs),
        ]);
    }
}
