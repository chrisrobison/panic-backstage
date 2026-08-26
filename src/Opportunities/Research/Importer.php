<?php
declare(strict_types=1);

namespace Panic\Opportunities\Research;

use Panic\Database;
use Panic\Opportunities\Companies;

use function Panic\slugify;

/**
 * Turns a human-reviewed selection from a completed research job's
 * `result_json` into real CRM rows — the "Research Complete / [ ] item /
 * Import Selected" review-then-import workflow the spec requires ("Research
 * must not silently populate trusted CRM data"). Called only from
 * Jobs::import(), itself gated on `manage_opportunities` and only reachable
 * via a human-clicked POST — never from the research job itself.
 *
 * $selections is a plain object of `{resultArrayKey: [selected indices]}`,
 * e.g. `{"companies": [0, 2, 3]}` for find_target_companies, matching
 * result_json's own top-level array keys 1:1 so the frontend's checklist
 * (one checkbox per result item) needs no separate id scheme.
 *
 * Every row this creates preserves source provenance where the source table
 * has a `source_url` column (every table below does) — "results retain
 * sources" (acceptance criterion) survives the import, not just the job's
 * own result_json. Rows already imported by a prior call are marked
 * `_imported`/`_imported_id` directly on the stored result_json so importing
 * twice is a no-op rather than a duplicate (re-selecting an already-imported
 * index is simply skipped).
 *
 * Deliberately does NOT overwrite an existing human-entered field — e.g.
 * find_target_companies dedupes against an existing company/link rather
 * than clobbering it, and research_company's company_fields import only
 * ever fills a currently-NULL column. "Do not overwrite human-entered
 * fields without confirmation" (spec).
 */
final class Importer
{
    private const COMPANY_RESEARCH_FIELDS = ['industry', 'hq_city', 'hq_state', 'description', 'linkedin_url', 'website_url'];

    /** @return array<string,int> a small {label: count} summary of what was created/linked */
    public static function import(Database $db, array $job, array $selections, ?int $userId): array
    {
        $result = json_decode((string) ($job['result_json'] ?? ''), true);
        if (!is_array($result)) {
            throw new \InvalidArgumentException('This job has no importable result.');
        }

        $jobType = (string) $job['job_type'];
        $summary = match ($jobType) {
            'discover_conferences'     => self::importConferences($db, $result, $selections, $userId),
            'research_conference'      => self::importConferenceResearch($db, $job, $result, $selections, $userId),
            'find_target_companies'    => self::importTargetCompanies($db, $job, $result, $selections, $userId),
            'research_company'         => self::importCompanyResearch($db, $job, $result, $selections, $userId),
            'research_side_events'     => self::importSideEvents($db, $job, $result, $selections, $userId),
            'generate_outreach_angles' => self::importOutreachAngles($db, $job, $result, $selections, $userId),
            default => throw new \InvalidArgumentException('Unknown job_type.'),
        };

        $db->run(
            'UPDATE opportunity_research_jobs SET result_json = ? WHERE id = ?',
            [json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int) $job['id']]
        );

        return $summary;
    }

    // ── discover_conferences → opportunity_conferences ──────────────────────

    private static function importConferences(Database $db, array &$result, array $selections, ?int $userId): array
    {
        $created = 0;
        foreach (self::selectedIndices($selections, 'conferences', count($result['conferences'] ?? [])) as $i) {
            $item = &$result['conferences'][$i];
            if (!empty($item['_imported'])) {
                continue;
            }
            $baseSlug = slugify((string) $item['name']);
            $slug = $baseSlug;
            $suffix = 2;
            while ($db->one('SELECT id FROM opportunity_conferences WHERE slug = ?', [$slug])) {
                $slug = $baseSlug . '-' . $suffix++;
            }
            $id = $db->insert(
                'INSERT INTO opportunity_conferences
                    (name, slug, website_url, venue_name, starts_at, ends_at, estimated_attendance, source_url, last_researched_at, created_by)
                 VALUES (?,?,?,?,?,?,?,?,NOW(),?)',
                [
                    $item['name'], $slug, $item['website_url'] ?? null, $item['venue'] ?? null,
                    $item['starts_on'] ?? null, $item['ends_on'] ?? null, $item['estimated_attendance'] ?? null,
                    $item['source_urls'][0] ?? null, $userId,
                ]
            );
            $item['_imported'] = true;
            $item['_imported_id'] = $id;
            $created++;
        }
        unset($item);
        return ['conferences_created' => $created];
    }

    // ── research_conference → opportunity_conference_facts + opportunity_signals ──

    private static function importConferenceResearch(Database $db, array $job, array &$result, array $selections, ?int $userId): array
    {
        $conferenceId = (int) ($job['conference_id'] ?? 0);
        if ($conferenceId <= 0) {
            throw new \InvalidArgumentException('This job has no conference to import into.');
        }
        $factsCreated = 0;
        $nextSort = (int) ($db->one('SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM opportunity_conference_facts WHERE conference_id = ?', [$conferenceId])['n'] ?? 0);
        foreach (self::selectedIndices($selections, 'facts', count($result['facts'] ?? [])) as $i) {
            $item = &$result['facts'][$i];
            if (!empty($item['_imported'])) {
                continue;
            }
            $id = $db->insert(
                'INSERT INTO opportunity_conference_facts (conference_id, fact, source_url, sort_order, created_by) VALUES (?,?,?,?,?)',
                [$conferenceId, $item['label'] . ': ' . $item['value'], $item['source_url'] ?? null, $nextSort++, $userId]
            );
            $item['_imported'] = true;
            $item['_imported_id'] = $id;
            $factsCreated++;
        }
        unset($item);

        $signalsCreated = 0;
        foreach (self::selectedIndices($selections, 'side_event_patterns', count($result['side_event_patterns'] ?? [])) as $i) {
            $item = &$result['side_event_patterns'][$i];
            if (!empty($item['_imported'])) {
                continue;
            }
            $id = $db->insert(
                'INSERT INTO opportunity_signals (conference_id, signal_type, description, confidence, source_url, is_ai_generated, created_by)
                 VALUES (?, \'side_event_history\', ?, \'medium\', ?, 1, ?)',
                [$conferenceId, $item['description'], $item['source_url'] ?? null, $userId]
            );
            $item['_imported'] = true;
            $item['_imported_id'] = $id;
            $signalsCreated++;
        }
        unset($item);

        if ($factsCreated > 0) {
            $db->run("UPDATE opportunity_conferences SET last_researched_at = NOW() WHERE id = ?", [$conferenceId]);
        }

        return ['facts_created' => $factsCreated, 'signals_created' => $signalsCreated];
    }

    // ── find_target_companies → opportunity_companies + opportunity_conference_companies ──

    private static function importTargetCompanies(Database $db, array $job, array &$result, array $selections, ?int $userId): array
    {
        $conferenceId = (int) ($job['conference_id'] ?? 0);
        if ($conferenceId <= 0) {
            throw new \InvalidArgumentException('This job has no conference to import into.');
        }
        $companiesCreated = 0;
        $linksCreated = 0;
        $created = false;
        foreach (self::selectedIndices($selections, 'companies', count($result['companies'] ?? [])) as $i) {
            $item = &$result['companies'][$i];
            if (!empty($item['_imported'])) {
                continue;
            }
            $companyId = self::findOrCreateCompany($db, (string) $item['name'], $item['domain'] ?? null, $userId, $created);
            $companiesCreated += $created ? 1 : 0;

            $existingLink = $db->one('SELECT id FROM opportunity_conference_companies WHERE conference_id = ? AND company_id = ?', [$conferenceId, $companyId]);
            if (!$existingLink) {
                $db->insert(
                    'INSERT INTO opportunity_conference_companies
                        (conference_id, company_id, role, participation_notes, confidence, source_url, observed_at, created_by)
                     VALUES (?,?,?,?,?,?,NOW(),?)',
                    [$conferenceId, $companyId, $item['role'] ?? 'unknown', $item['why_relevant'] ?? null, $item['confidence'] ?? 'medium', $item['source_url'] ?? null, $userId]
                );
                $linksCreated++;
            }
            $item['_imported'] = true;
            $item['_imported_id'] = $companyId;
        }
        unset($item);
        return ['companies_created' => $companiesCreated, 'companies_linked' => $linksCreated];
    }

    // ── research_company → opportunity_companies fields + signals + contacts ──

    private static function importCompanyResearch(Database $db, array $job, array &$result, array $selections, ?int $userId): array
    {
        $companyId = (int) ($job['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new \InvalidArgumentException('This job has no company to import into.');
        }

        $fieldsApplied = 0;
        $requestedFields = is_array($selections['company_fields'] ?? null) ? $selections['company_fields'] : [];
        if ($requestedFields) {
            $current = $db->one('SELECT * FROM opportunity_companies WHERE id = ?', [$companyId]) ?: [];
            $sets = [];
            $params = [];
            foreach (self::COMPANY_RESEARCH_FIELDS as $field) {
                if (!in_array($field, $requestedFields, true)) {
                    continue;
                }
                // Never overwrite an existing human-entered value — only
                // fill a currently-empty column (spec: "do not overwrite
                // human-entered fields without confirmation").
                if (!empty($current[$field])) {
                    continue;
                }
                $value = $result['company'][$field] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $sets[] = "`$field` = ?";
                $params[] = $value;
                $fieldsApplied++;
            }
            if ($sets) {
                $params[] = $companyId;
                $db->run('UPDATE opportunity_companies SET ' . implode(', ', $sets) . ', last_researched_at = NOW() WHERE id = ?', $params);
            } elseif (!empty($result['company']['industry']) || !empty($result['company']['description'])) {
                $db->run('UPDATE opportunity_companies SET last_researched_at = NOW() WHERE id = ?', [$companyId]);
            }
        }

        $signalsCreated = 0;
        foreach (['conference_presence' => 'other', 'hospitality_signals' => 'hospitality_history'] as $key => $signalType) {
            foreach (self::selectedIndices($selections, $key, count($result[$key] ?? [])) as $i) {
                $item = &$result[$key][$i];
                if (!empty($item['_imported'])) {
                    continue;
                }
                $description = $key === 'conference_presence'
                    ? sprintf('Conference presence: %s%s', $item['conference_name'], !empty($item['role']) ? " ({$item['role']})" : '')
                    : (string) $item['description'];
                $id = $db->insert(
                    'INSERT INTO opportunity_signals (company_id, signal_type, description, confidence, source_url, is_ai_generated, created_by)
                     VALUES (?, ?, ?, \'medium\', ?, 1, ?)',
                    [$companyId, $signalType, $description, $item['source_url'] ?? null, $userId]
                );
                $item['_imported'] = true;
                $item['_imported_id'] = $id;
                $signalsCreated++;
            }
            unset($item);
        }

        $contactsCreated = 0;
        $roleSignalsCreated = 0;
        foreach (self::selectedIndices($selections, 'buyer_roles', count($result['buyer_roles'] ?? [])) as $i) {
            $item = &$result['buyer_roles'][$i];
            if (!empty($item['_imported'])) {
                continue;
            }
            if (!empty($item['name'])) {
                // A real, source-backed named person (Modes::validateResult()
                // already stripped any name that lacked a source_url) — safe
                // to import as an ordinary buyer contact.
                $id = $db->insert(
                    'INSERT INTO opportunity_contacts (company_id, name, title, source_url, created_by) VALUES (?,?,?,?,?)',
                    [$companyId, $item['name'], $item['title'] ?? null, $item['source_url'] ?? null, $userId]
                );
                $item['_imported'] = true;
                $item['_imported_id'] = $id;
                $contactsCreated++;
            } else {
                // No named person found — spec: "store role suggestions
                // rather than fabricating a person." A signal, not a contact.
                $desc = 'Likely buyer role: ' . $item['title'] . (!empty($item['note']) ? ' — ' . $item['note'] : '');
                $id = $db->insert(
                    'INSERT INTO opportunity_signals (company_id, signal_type, description, confidence, source_url, is_ai_generated, created_by)
                     VALUES (?, \'other\', ?, \'medium\', ?, 1, ?)',
                    [$companyId, $desc, $item['source_url'] ?? null, $userId]
                );
                $item['_imported'] = true;
                $item['_imported_id'] = $id;
                $roleSignalsCreated++;
            }
        }
        unset($item);

        return [
            'fields_applied'        => $fieldsApplied,
            'signals_created'       => $signalsCreated + $roleSignalsCreated,
            'contacts_created'      => $contactsCreated,
        ];
    }

    // ── research_side_events → opportunity_signals (conference + company scoped) ──

    private static function importSideEvents(Database $db, array $job, array &$result, array $selections, ?int $userId): array
    {
        $conferenceId = (int) ($job['conference_id'] ?? 0);
        if ($conferenceId <= 0) {
            throw new \InvalidArgumentException('This job has no conference to import into.');
        }
        $created = 0;
        $ignoredCreated = false;
        foreach (self::selectedIndices($selections, 'side_events', count($result['side_events'] ?? [])) as $i) {
            $item = &$result['side_events'][$i];
            if (!empty($item['_imported'])) {
                continue;
            }
            // Also find-or-create the host company so it exists as a real
            // prospect row — a signal alone would otherwise be an orphaned
            // fact with nothing to act on from the Companies list.
            $companyId = self::findOrCreateCompany($db, (string) $item['host_company'], null, $userId, $ignoredCreated);
            $description = sprintf('%s — %s (%s)', $item['host_company'], $item['event_name'], str_replace('_', ' ', (string) $item['type']));
            $id = $db->insert(
                'INSERT INTO opportunity_signals (conference_id, company_id, signal_type, description, observed_at, confidence, source_url, is_ai_generated, created_by)
                 VALUES (?, ?, \'side_event_history\', ?, ?, \'medium\', ?, 1, ?)',
                [$conferenceId, $companyId, $description, $item['date'] ?? null, $item['source_url'] ?? null, $userId]
            );
            $item['_imported'] = true;
            $item['_imported_id'] = $id;
            $created++;
        }
        unset($item);
        return ['signals_created' => $created];
    }

    // ── generate_outreach_angles → opportunity_notes (note_type=strategy) ──

    private static function importOutreachAngles(Database $db, array $job, array &$result, array $selections, ?int $userId): array
    {
        $conferenceId = !empty($job['conference_id']) ? (int) $job['conference_id'] : null;
        $companyId    = !empty($job['company_id']) ? (int) $job['company_id'] : null;
        if ($conferenceId === null && $companyId === null) {
            throw new \InvalidArgumentException('This job has no conference or company to attach notes to.');
        }
        $created = 0;
        $model = trim((string) (getenv('OPPORTUNITY_RESEARCH_MODEL') ?: '')) ?: 'sonnet';
        foreach (self::selectedIndices($selections, 'angles', count($result['angles'] ?? [])) as $i) {
            $item = &$result['angles'][$i];
            if (!empty($item['_imported'])) {
                continue;
            }
            $body = "**{$item['title']}**\n\n" . ($item['description'] ?? '')
                . (!empty($item['rationale']) ? "\n\n_Why:_ {$item['rationale']}" : '');
            $noteId = $db->insert(
                "INSERT INTO opportunity_notes (body, note_type, is_ai_generated, ai_model, ai_prompt_version, created_by)
                 VALUES (?, 'strategy', 1, ?, 'v1', ?)",
                [$body, $model, $userId]
            );
            if ($conferenceId !== null) {
                $db->run("INSERT INTO opportunity_note_links (note_id, linked_type, linked_id) VALUES (?, 'conference', ?)", [$noteId, $conferenceId]);
            }
            if ($companyId !== null) {
                $db->run("INSERT INTO opportunity_note_links (note_id, linked_type, linked_id) VALUES (?, 'company', ?)", [$noteId, $companyId]);
            }
            $item['_imported'] = true;
            $item['_imported_id'] = $noteId;
            $created++;
        }
        unset($item);
        return ['notes_created' => $created];
    }

    // ── Shared helpers ───────────────────────────────────────────────────────

    /** @return list<int> valid, unique, in-range indices requested for $key */
    private static function selectedIndices(array $selections, string $key, int $count): array
    {
        $raw = $selections[$key] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            if (is_numeric($v)) {
                $i = (int) $v;
                if ($i >= 0 && $i < $count) {
                    $out[] = $i;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** Find an existing opportunity_companies row by normalized domain or exact name, else create one. Sets &$created. */
    private static function findOrCreateCompany(Database $db, string $name, ?string $domain, ?int $userId, bool &$created): int
    {
        $created = false;
        $normalizedDomain = $domain !== null ? Companies::normalizeDomain($domain) : null;
        if ($normalizedDomain !== null) {
            $existing = $db->one('SELECT id FROM opportunity_companies WHERE domain = ?', [$normalizedDomain]);
            if ($existing) {
                return (int) $existing['id'];
            }
        }
        $existingByName = $db->one('SELECT id FROM opportunity_companies WHERE name = ? LIMIT 1', [$name]);
        if ($existingByName) {
            return (int) $existingByName['id'];
        }
        $id = $db->insert(
            'INSERT INTO opportunity_companies (name, domain, created_by) VALUES (?, ?, ?)',
            [$name, $normalizedDomain, $userId]
        );
        $created = true;
        return $id;
    }
}
