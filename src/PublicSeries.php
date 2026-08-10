<?php
declare(strict_types=1);

namespace Panic;

/** Resolve one stable recurring-series slug to its current public occurrence. */
final class PublicSeries extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        $slug = trim((string) ($this->params['slug'] ?? ''));
        if ($slug === '') {
            return $this->notFound('Series not found');
        }

        $series = $this->db->one(
            'SELECT id, title, public_slug FROM event_series WHERE public_slug = ?',
            [$slug]
        );
        if ($series === null) {
            return $this->notFound('Series not found');
        }

        // Prefer today/next, and fall back to the most recent visible date so
        // a stable link still has a useful destination between series runs.
        $event = $this->db->one(
            "SELECT id, title, date
               FROM events
              WHERE series_id = ? AND public_visibility = 1
                AND status <> 'canceled' AND date >= CURDATE()
              ORDER BY date, id LIMIT 1",
            [(int) $series['id']]
        ) ?? $this->db->one(
            "SELECT id, title, date
               FROM events
              WHERE series_id = ? AND public_visibility = 1
                AND status <> 'canceled'
              ORDER BY date DESC, id DESC LIMIT 1",
            [(int) $series['id']]
        );

        if ($event === null) {
            return $this->notFound('No public occurrence is available');
        }

        return $this->ok([
            'series' => [
                'id' => (int) $series['id'],
                'title' => (string) $series['title'],
                'slug' => (string) $series['public_slug'],
                'public_page' => series_public_path($series),
            ],
            'event_id' => (int) $event['id'],
            'event_date' => (string) $event['date'],
        ]);
    }
}
