<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;
use function Panic\date_or_null;
use function Panic\boolish;

/**
 * Event vendor records — tracking external service providers per event.
 *
 *   GET    /api/events/{id}/vendors              list vendors
 *   POST   /api/events/{id}/vendors              create a vendor record
 *   PATCH  /api/events/{id}/vendors/{vid}        update
 *   DELETE /api/events/{id}/vendors/{vid}        delete
 *
 * Capabilities: read_event (GET), manage_vendors (write)
 */
final class Vendors extends BaseEndpoint
{
    private const CATEGORIES = [
        'sound','lighting','av','catering','security','cleaning','photography',
        'videography','florist','rental','transportation','staffing_agency',
        'entertainment','production','venue_support','other',
    ];

    private const COI_STATUSES = ['not_required','requested','received','expired','waived'];

    public function handle(Request $request): Response
    {
        $eventId  = $this->requireEventId();
        $vendorId = $this->params['vendorId'] ?? null;

        $cap = $request->method() === 'GET' ? 'read_event' : 'manage_vendors';
        if ($denied = $this->requireEventCapability($eventId, $cap)) {
            return $denied;
        }

        return match ($request->method()) {
            'GET'    => $vendorId ? $this->show($eventId, (int) $vendorId) : $this->index($eventId),
            'POST'   => $this->create($request, $eventId),
            'PATCH'  => $this->update($request, $eventId, (int) $vendorId),
            'DELETE' => $this->delete($eventId, (int) $vendorId),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(int $eventId): Response
    {
        $vendors = $this->db->all(
            "SELECT v.*, u.name owner_name
             FROM event_vendors v
             LEFT JOIN users u ON u.id = v.owner_user_id
             WHERE v.event_id = ?
             ORDER BY FIELD(v.service_category,'sound','lighting','av','security','catering','cleaning','other'), v.company_name",
            [$eventId]
        );
        return $this->ok([
            'vendors'     => $vendors,
            'categories'  => self::CATEGORIES,
            'coi_statuses' => self::COI_STATUSES,
        ]);
    }

    private function show(int $eventId, int $vendorId): Response
    {
        $vendor = $this->db->one(
            'SELECT v.*, u.name owner_name FROM event_vendors v
             LEFT JOIN users u ON u.id = v.owner_user_id
             WHERE v.id = ? AND v.event_id = ?',
            [$vendorId, $eventId]
        );
        return $vendor ? $this->ok(['vendor' => $vendor]) : $this->notFound();
    }

    private function create(Request $request, int $eventId): Response
    {
        $b = $request->body();

        $category = (string) ($b['service_category'] ?? 'other');
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'other';
        }

        $id = $this->db->insert(
            'INSERT INTO event_vendors
             (event_id, company_name, contact_name, contact_email, contact_phone,
              service_category, description, quote_amount, approved_amount,
              coi_required, coi_status, coi_expiry_date, load_in_time, load_out_time,
              notes, owner_user_id, created_by_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $eventId,
                $b['company_name']    ?? null,
                $b['contact_name']    ?? null,
                $b['contact_email']   ?? null,
                $b['contact_phone']   ?? null,
                $category,
                $b['description']     ?? null,
                isset($b['quote_amount'])    ? (float) $b['quote_amount']    : null,
                isset($b['approved_amount']) ? (float) $b['approved_amount'] : null,
                boolish($b['coi_required']   ?? false),
                in_array($b['coi_status'] ?? '', self::COI_STATUSES, true) ? $b['coi_status'] : 'not_required',
                date_or_null($b['coi_expiry_date'] ?? null),
                $b['load_in_time']    ?? null,
                $b['load_out_time']   ?? null,
                $b['notes']           ?? null,
                isset($b['owner_user_id']) ? (int) $b['owner_user_id'] : $this->userId(),
                $this->userId(),
            ]
        );

        log_activity($this->db, $eventId, $this->userId(), 'vendor added', [
            'vendor_id' => $id,
            'category'  => $category,
        ]);

        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $eventId, int $vendorId): Response
    {
        if (!$this->db->one('SELECT id FROM event_vendors WHERE id = ? AND event_id = ?', [$vendorId, $eventId])) {
            return $this->notFound();
        }

        $b      = $request->body();
        $sets   = [];
        $params = [];

        $fields = [
            'company_name','contact_name','contact_email','contact_phone',
            'service_category','description','quote_amount','approved_amount','actual_amount',
            'payment_status','coi_required','coi_status','coi_expiry_date',
            'confirmation_status','load_in_time','load_out_time','notes','owner_user_id',
        ];

        foreach ($fields as $f) {
            if (!array_key_exists($f, $b)) continue;
            $val = $b[$f];
            if ($f === 'coi_expiry_date') {
                $val = date_or_null($val);
            } elseif ($f === 'coi_required') {
                $val = boolish($val);
            } elseif (in_array($f, ['quote_amount','approved_amount','actual_amount'], true)) {
                $val = $val !== null && $val !== '' ? (float) $val : null;
            }
            $sets[]   = "$f = ?";
            $params[] = $val;
        }

        // confirmation_status side-effect
        if (($b['confirmation_status'] ?? '') === 'confirmed') {
            $sets[]   = 'confirmed_at = NOW()';
        }

        if (empty($sets)) {
            return $this->ok(['ok' => true]);
        }

        $params[] = $vendorId;
        $this->db->run('UPDATE event_vendors SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        return $this->ok(['ok' => true]);
    }

    private function delete(int $eventId, int $vendorId): Response
    {
        if (!$this->db->one('SELECT id FROM event_vendors WHERE id = ? AND event_id = ?', [$vendorId, $eventId])) {
            return $this->notFound();
        }
        $this->db->run('DELETE FROM event_vendors WHERE id = ? AND event_id = ?', [$vendorId, $eventId]);
        log_activity($this->db, $eventId, $this->userId(), 'vendor removed', ['vendor_id' => $vendorId]);
        return Response::noContent();
    }
}
