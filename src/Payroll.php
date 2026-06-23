<?php
declare(strict_types=1);

namespace Panic;

/**
 * GET /api/payroll/export?start=YYYY-MM-DD&end=YYYY-MM-DD
 *
 * Venue-admin-only batch payroll CSV covering all staffing rows for events
 * whose show_time falls within the requested date range.
 *
 * Defaults to the current calendar month when no dates are supplied.
 */
final class Payroll extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if (!$this->isVenueAdmin()) {
            return $this->forbidden('venue_admin required');
        }

        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        $start = (string) ($request->query('start') ?? date('Y-m-01'));
        $end   = (string) ($request->query('end')   ?? date('Y-m-t'));

        $rows = $this->db->all(
            'SELECT
               e.id          event_id,
               e.title       event_title,
               DATE(e.show_time) event_date,
               sm.name       staff_name,
               sm.email      staff_email,
               sm.phone      staff_phone,
               es.role,
               es.source,
               es.clock_in,
               es.clock_out,
               es.actual_hours,
               es.estimated_hours,
               es.approved_overtime_hours,
               es.notes
             FROM event_staffing es
             JOIN events e ON e.id = es.event_id
             LEFT JOIN staff_members sm ON sm.id = es.staff_member_id
             WHERE DATE(e.show_time) BETWEEN ? AND ?
             ORDER BY e.show_time, es.role, sm.name',
            [$start, $end]
        );

        $csv      = $this->buildCsvContent($rows);
        $filename = 'payroll-' . $start . '--' . $end . '.csv';
        return Response::csv($csv, $filename);
    }

    /**
     * Build a CSV string from an array of payroll row arrays.
     */
    private function buildCsvContent(array $rows): string
    {
        $csv = "Event ID,Event Title,Event Date,Staff Name,Email,Phone,Role,Source,Clock In,Clock Out,Actual Hours,Est Hours,OT Hours,Notes\n";
        foreach ($rows as $row) {
            $fields = [
                $row['event_id'],
                $row['event_title'],
                $row['event_date'],
                $row['staff_name'],
                $row['staff_email'],
                $row['staff_phone'],
                $row['role'],
                $row['source'],
                $row['clock_in'],
                $row['clock_out'],
                $row['actual_hours'],
                $row['estimated_hours'],
                $row['approved_overtime_hours'],
                $row['notes'],
            ];
            $csv .= implode(',', array_map(
                fn($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"',
                $fields
            )) . "\n";
        }
        return $csv;
    }
}
