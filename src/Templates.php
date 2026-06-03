<?php
declare(strict_types=1);

namespace Panic;

/**
 * Event template CRUD.
 *
 *   GET    /api/templates              list (any authenticated user)
 *   POST   /api/templates              create
 *   PATCH  /api/templates/{id}         update (name, copy, price, age, checklist, schedule)
 *   DELETE /api/templates/{id}         delete
 *
 * checklist_json and schedule_json may be sent as JSON strings OR as PHP arrays
 * (the JSON body decoder will hand us arrays); both are accepted.
 */
final class Templates extends BaseEndpoint
{
    private const TYPES = [
        'live_music','karaoke','open_mic','promoter_night','dj_night',
        'comedy','private_event','special_event',
    ];

    public function handle(Request $request): Response
    {
        $method = $request->method();
        // Reads are available to any authenticated user — the event quick-create
        // dialog needs to list templates. Writes still require manage_templates.
        if ($method !== 'GET' && ($denied = $this->requireGlobalCapability('manage_templates'))) {
            return $denied;
        }
        $id = $this->params['templateId'] ?? null;
        return match ($method) {
            'GET'    => $id ? $this->show((int) $id) : $this->index(),
            'POST'   => $this->create($request),
            'PATCH'  => $this->update($request, (int) $id),
            'DELETE' => $this->delete((int) $id),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(): Response
    {
        return $this->ok([
            'templates' => $this->db->all(
                'SELECT t.*, v.name venue_name FROM event_templates t JOIN venues v ON v.id = t.venue_id ORDER BY t.name'
            ),
            'venues' => $this->db->all('SELECT * FROM venues ORDER BY name'),
            'types'  => self::TYPES,
        ]);
    }

    private function show(int $id): Response
    {
        $row = $this->db->one('SELECT * FROM event_templates WHERE id = ?', [$id]);
        if (!$row) {
            return $this->notFound('Template not found');
        }
        return $this->ok(['template' => $row]);
    }

    private function create(Request $request): Response
    {
        [$payload, $error] = $this->payload($request);
        if ($error) return $error;
        $id = $this->db->insert(
            'INSERT INTO event_templates (venue_id, name, event_type, default_title, default_description_public, default_ticket_price, default_age_restriction, checklist_json, schedule_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $payload['venue_id'],
                $payload['name'],
                $payload['event_type'],
                $payload['default_title'],
                $payload['default_description_public'],
                $payload['default_ticket_price'],
                $payload['default_age_restriction'],
                $payload['checklist_json'],
                $payload['schedule_json'],
            ]
        );
        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $id): Response
    {
        if (!$id) return $this->notFound();
        $existing = $this->db->one('SELECT id FROM event_templates WHERE id = ?', [$id]);
        if (!$existing) return $this->notFound('Template not found');
        [$payload, $error] = $this->payload($request);
        if ($error) return $error;
        $this->db->run(
            'UPDATE event_templates
             SET venue_id=?, name=?, event_type=?, default_title=?, default_description_public=?, default_ticket_price=?, default_age_restriction=?, checklist_json=?, schedule_json=?
             WHERE id=?',
            [
                $payload['venue_id'],
                $payload['name'],
                $payload['event_type'],
                $payload['default_title'],
                $payload['default_description_public'],
                $payload['default_ticket_price'],
                $payload['default_age_restriction'],
                $payload['checklist_json'],
                $payload['schedule_json'],
                $id,
            ]
        );
        return $this->ok(['ok' => true]);
    }

    private function delete(int $id): Response
    {
        if (!$id) return $this->notFound();
        $this->db->run('DELETE FROM event_templates WHERE id = ?', [$id]);
        return Response::noContent();
    }

    /** @return array{0: array, 1: ?Response} */
    private function payload(Request $request): array
    {
        $name = trim((string) $request->body('name', ''));
        if ($name === '') {
            return [[], Response::json(['error' => 'name is required'], 422)];
        }
        $type = (string) $request->body('event_type', '');
        if (!in_array($type, self::TYPES, true)) {
            return [[], Response::json(['error' => 'Invalid event_type'], 422)];
        }
        $venueId = (int) $request->body('venue_id', 0);
        if ($venueId <= 0) {
            return [[], Response::json(['error' => 'venue_id is required'], 422)];
        }
        return [[
            'venue_id'                   => $venueId,
            'name'                       => $name,
            'event_type'                 => $type,
            'default_title'              => trim((string) $request->body('default_title', '')) ?: null,
            'default_description_public' => trim((string) $request->body('default_description_public', '')) ?: null,
            'default_ticket_price'       => (float) $request->body('default_ticket_price', 0),
            'default_age_restriction'    => trim((string) $request->body('default_age_restriction', '')) ?: null,
            'checklist_json'             => $this->normalizeJsonList($request->body('checklist_json')),
            'schedule_json'              => $this->normalizeJsonList($request->body('schedule_json')),
        ], null];
    }

    /** Accepts either a JSON string or a PHP array; stores canonical JSON. */
    private function normalizeJsonList(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode(array_values($value));
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return json_encode(array_values($decoded));
            }
        }
        return '[]';
    }
}
