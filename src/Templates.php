<?php
declare(strict_types=1);

namespace Panic;

final class Templates extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_templates')) {
            return $denied;
        }
        return match ($request->method()) {
            'GET' => $this->index(),
            'POST' => $this->create($request),
            default => Response::methodNotAllowed()
        };
    }

    private function index(): Response
    {
        return $this->ok([
            'templates' => $this->db->all('SELECT t.*, v.name venue_name FROM event_templates t JOIN venues v ON v.id = t.venue_id ORDER BY t.name'),
            'venues' => $this->db->all('SELECT * FROM venues ORDER BY name'),
        ]);
    }

    private function create(Request $request): Response
    {
        $id = $this->db->insert(
            'INSERT INTO event_templates (venue_id, name, event_type, default_title, default_description_public, default_ticket_price, default_age_restriction, checklist_json, schedule_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$request->body('venue_id'), $request->body('name'), $request->body('event_type'), $request->body('default_title'), $request->body('default_description_public'), $request->body('default_ticket_price', 0), $request->body('default_age_restriction'), $request->body('checklist_json', '[]'), $request->body('schedule_json', '[]')]
        );
        return $this->ok(['id' => $id]);
    }
}
