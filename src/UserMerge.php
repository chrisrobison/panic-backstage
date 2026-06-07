<?php
declare(strict_types=1);

namespace Panic;

use PDO;
use RuntimeException;

/**
 * Duplicate detection + account merge engine.
 *
 * This is a pure service: it owns the read-only duplicate scan and the atomic,
 * transactional merge. It reuses Panic\Identity for canonicalization, alias
 * decoding, and the global email-uniqueness guard. It NEVER touches HTTP; the
 * Duplicates endpoint adapts it to the API.
 *
 * Merge contract (POST /api/users/merge):
 *   (a) Repoint every users(id) reference loser -> survivor. Tables with a
 *       UNIQUE constraint that could collide after repoint are de-duped first.
 *   (b) Move passkeys loser -> survivor (keep the device login); delete the
 *       loser's refresh_tokens (force a clean re-login).
 *   (c) Fold the loser's primary email + its alt_emails into the survivor's
 *       alt_emails as VERIFIED entries (respecting emailIsTaken).
 *   (d) Deactivate / delete the loser only after every FK is repointed.
 *   (e) Role guard: survivor.role != loser.role => caller must pass an explicit
 *       override or the merge is refused (RoleMismatchException). The decision
 *       is recorded in the user_merges audit row.
 */
final class UserMerge
{
    /**
     * Tables that hold a users(id) reference, derived from every
     * `REFERENCES users(id)` in schema.sql PLUS the un-constrained
     * INT user columns the product still treats as user references
     * (tickets / scans / scanner links / payment settings).
     *
     * 'simple' columns are blind UPDATE ... SET col = survivor WHERE col = loser.
     *
     * NOTE: there is intentionally no user_access_requests entry — that table
     * does not exist in this schema. Access requests are stored on the users row
     * itself (access_status='requested'), which is dropped with the loser.
     *
     * @var array<int,array{table:string,column:string}>
     */
    private const SIMPLE_REFS = [
        ['table' => 'events',                'column' => 'owner_user_id'],
        ['table' => 'event_tasks',           'column' => 'assigned_user_id'],
        ['table' => 'event_blockers',        'column' => 'owner_user_id'],
        ['table' => 'event_assets',          'column' => 'uploaded_by_user_id'],
        ['table' => 'event_settlements',     'column' => 'settled_by_user_id'],
        ['table' => 'event_activity_log',    'column' => 'user_id'],
        ['table' => 'staff_members',         'column' => 'user_id'],
        ['table' => 'event_guest_list',      'column' => 'created_by_user_id'],
        ['table' => 'contracts',             'column' => 'created_by_user_id'],
        ['table' => 'contracts',             'column' => 'approved_by_user_id'],
        ['table' => 'contract_versions',     'column' => 'created_by_user_id'],
        ['table' => 'ticket_orders',         'column' => 'buyer_user_id'],
        ['table' => 'tickets',               'column' => 'redeemed_by_user_id'],
        ['table' => 'ticket_scans',          'column' => 'scanned_by_user_id'],
        ['table' => 'event_scanner_links',   'column' => 'created_by_user_id'],
        ['table' => 'payment_settings',      'column' => 'updated_by_user_id'],
    ];

    public function __construct(private readonly Database $db) {}

    // ---------------------------------------------------------------------
    // Duplicate detection (read-only)
    // ---------------------------------------------------------------------

    /**
     * Scan all users for likely-same-person pairs. Signals:
     *   - "name": identical normalized name (trim + lowercase + collapse spaces)
     *   - "phone": shared non-empty normalized phone
     *   - "gmail-canonical": Identity::canonical() of primary emails match
     *
     * Read-only, no side effects. Each unordered pair is returned at most once.
     *
     * @return array<int,array{
     *   user_a:array{id:int,name:string,email:string,role:string},
     *   user_b:array{id:int,name:string,email:string,role:string},
     *   signals:array<int,string>,
     *   same_role:bool
     * }>
     */
    public function findDuplicates(): array
    {
        $users = $this->db->all(
            'SELECT id, name, email, phone, role FROM users ORDER BY id'
        );

        // Bucket by each signal; any bucket with >1 member yields candidate pairs.
        $byName  = [];
        $byPhone = [];
        $byGmail = [];

        foreach ($users as $u) {
            $id = (int) $u['id'];

            $name = $this->normalizeName((string) ($u['name'] ?? ''));
            if ($name !== '') {
                $byName[$name][] = $id;
            }

            $phone = $this->normalizePhone((string) ($u['phone'] ?? ''));
            if ($phone !== '') {
                $byPhone[$phone][] = $id;
            }

            $canon = Identity::canonical((string) ($u['email'] ?? ''));
            if ($canon !== '' && str_contains($canon, '@')) {
                $byGmail[$canon][] = $id;
            }
        }

        $index = [];
        foreach ($users as $u) {
            $index[(int) $u['id']] = $u;
        }

        // pairKey => signals[]
        $pairs = [];
        $addSignal = static function (array $ids, string $signal) use (&$pairs): void {
            $ids = array_values(array_unique($ids));
            $n = count($ids);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = min($ids[$i], $ids[$j]);
                    $b = max($ids[$i], $ids[$j]);
                    $key = $a . ':' . $b;
                    if (!isset($pairs[$key])) {
                        $pairs[$key] = [];
                    }
                    if (!in_array($signal, $pairs[$key], true)) {
                        $pairs[$key][] = $signal;
                    }
                }
            }
        };

        foreach ($byName as $ids) {
            if (count($ids) > 1) {
                $addSignal($ids, 'name');
            }
        }
        foreach ($byPhone as $ids) {
            if (count($ids) > 1) {
                $addSignal($ids, 'phone');
            }
        }
        foreach ($byGmail as $ids) {
            if (count($ids) > 1) {
                $addSignal($ids, 'gmail-canonical');
            }
        }

        $out = [];
        foreach ($pairs as $key => $signals) {
            [$a, $b] = array_map('intval', explode(':', $key));
            $ua = $index[$a];
            $ub = $index[$b];
            $out[] = [
                'user_a'    => $this->publicUser($ua),
                'user_b'    => $this->publicUser($ub),
                'signals'   => $signals,
                'same_role' => (string) $ua['role'] === (string) $ub['role'],
            ];
        }

        // Strongest matches first (most signals).
        usort($out, static fn ($x, $y) => count($y['signals']) <=> count($x['signals']));

        return $out;
    }

    // ---------------------------------------------------------------------
    // Merge (atomic, transactional)
    // ---------------------------------------------------------------------

    /**
     * Perform an atomic merge of $loserId into $survivorId.
     *
     * @param bool     $overrideRole  allow merging across differing roles
     * @param int|null $performedBy   admin user id for the audit row
     *
     * @return array{
     *   survivor_id:int,
     *   loser_id:int,
     *   moved:array<string,int>,
     *   folded_emails:array<int,string>,
     *   role_overridden:bool,
     *   audit_id:int
     * }
     *
     * @throws MergeValidationException  bad input (same id / missing user)
     * @throws RoleMismatchException     roles differ and $overrideRole is false
     */
    public function merge(int $survivorId, int $loserId, bool $overrideRole, ?int $performedBy): array
    {
        if ($survivorId === $loserId) {
            throw new MergeValidationException('survivor_id and loser_id must differ');
        }

        $survivor = $this->db->one('SELECT * FROM users WHERE id = ?', [$survivorId]);
        $loser    = $this->db->one('SELECT * FROM users WHERE id = ?', [$loserId]);
        if (!$survivor) {
            throw new MergeValidationException('survivor user not found');
        }
        if (!$loser) {
            throw new MergeValidationException('loser user not found');
        }

        $survivorRole = (string) $survivor['role'];
        $loserRole    = (string) $loser['role'];
        $rolesDiffer  = $survivorRole !== $loserRole;
        if ($rolesDiffer && !$overrideRole) {
            throw new RoleMismatchException($survivorRole, $loserRole);
        }

        $pdo = $this->db->pdo();
        $alreadyInTransaction = $pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $moved = [];

            // (a) De-dupe UNIQUE-constrained refs BEFORE blind repoint, so the
            //     repoint can't violate a unique key.
            $this->dedupeCollaborators($survivorId, $loserId, $moved);

            // (a cont.) Blind repoint of every remaining user reference.
            foreach (self::SIMPLE_REFS as $ref) {
                $count = $this->repoint($ref['table'], $ref['column'], $survivorId, $loserId);
                if ($count > 0) {
                    $moved[$ref['table'] . '.' . $ref['column']] = $count;
                }
            }

            // (b) Move passkeys (device logins) to the survivor; drop the
            //     loser's refresh tokens to force a clean re-login.
            $passkeysMoved = $this->repoint('passkeys', 'user_id', $survivorId, $loserId);
            if ($passkeysMoved > 0) {
                $moved['passkeys.user_id'] = $passkeysMoved;
            }
            $tokensRevoked = $this->db->run('DELETE FROM refresh_tokens WHERE user_id = ?', [$loserId]);
            if ($tokensRevoked > 0) {
                $moved['refresh_tokens.revoked'] = $tokensRevoked;
            }

            // email_verification_tokens for the loser are now meaningless; move
            // their FK to the survivor so the loser row can be removed cleanly.
            $verifMoved = $this->repoint('email_verification_tokens', 'user_id', $survivorId, $loserId);
            if ($verifMoved > 0) {
                $moved['email_verification_tokens.user_id'] = $verifMoved;
            }

            // (c) Fold the loser's primary + alias emails into the survivor as
            //     VERIFIED entries (the loser's primary is known-good).
            $folded = $this->foldEmails($survivor, $loser);

            // (d) Remove the now-dereferenced loser row. Every FK above has been
            //     repointed or revoked, so a hard delete is safe; staff_members
            //     and other ON DELETE SET NULL columns were already repointed.
            $this->db->run('DELETE FROM users WHERE id = ?', [$loserId]);

            // (e) Audit trail.
            $details = [
                'moved'           => $moved,
                'folded_emails'   => $folded,
                'survivor_role'   => $survivorRole,
                'loser_role'      => $loserRole,
                'role_overridden' => $rolesDiffer && $overrideRole,
                'signals'         => $this->signalsFor($survivor, $loser),
            ];
            $auditId = $this->db->insert(
                'INSERT INTO user_merges
                    (survivor_user_id, loser_user_id, loser_email, performed_by_user_id, details)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $survivorId,
                    $loserId,
                    (string) ($loser['email'] ?? ''),
                    $performedBy,
                    json_encode($details),
                ]
            );

            if (!$alreadyInTransaction) {
                $pdo->commit();
            }

            return [
                'survivor_id'     => $survivorId,
                'loser_id'        => $loserId,
                'moved'           => $moved,
                'folded_emails'   => $folded,
                'role_overridden' => $rolesDiffer && $overrideRole,
                'audit_id'        => $auditId,
            ];
        } catch (\Throwable $e) {
            if (!$alreadyInTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function repoint(string $table, string $column, int $survivorId, int $loserId): int
    {
        // Identifiers are constants from SIMPLE_REFS / hard-coded — never user input.
        $sql = "UPDATE `$table` SET `$column` = ? WHERE `$column` = ?";
        return $this->db->run($sql, [$survivorId, $loserId]);
    }

    /**
     * event_collaborators has UNIQUE(event_id, user_id). Repointing the loser's
     * rows to the survivor would collide wherever both already collaborate on the
     * same event. For those events: keep the survivor's row but upgrade it to the
     * higher-capability role of the two, then delete the loser's row. For events
     * where only the loser collaborates: blind repoint.
     *
     * @param array<string,int> $moved
     */
    private function dedupeCollaborators(int $survivorId, int $loserId, array &$moved): void
    {
        $clashes = $this->db->all(
            'SELECT l.event_id, l.role AS loser_role, s.role AS survivor_role
               FROM event_collaborators l
               JOIN event_collaborators s
                 ON s.event_id = l.event_id AND s.user_id = ?
              WHERE l.user_id = ?',
            [$survivorId, $loserId]
        );

        $deduped = 0;
        foreach ($clashes as $row) {
            $eventId = (int) $row['event_id'];
            $best = $this->higherEventRole((string) $row['survivor_role'], (string) $row['loser_role']);
            if ($best !== (string) $row['survivor_role']) {
                $this->db->run(
                    'UPDATE event_collaborators SET role = ? WHERE event_id = ? AND user_id = ?',
                    [$best, $eventId, $survivorId]
                );
            }
            $this->db->run(
                'DELETE FROM event_collaborators WHERE event_id = ? AND user_id = ?',
                [$eventId, $loserId]
            );
            $deduped++;
        }
        if ($deduped > 0) {
            $moved['event_collaborators.deduped'] = $deduped;
        }

        // Remaining loser rows (no survivor row on that event) repoint cleanly.
        $repointed = $this->db->run(
            'UPDATE event_collaborators SET user_id = ? WHERE user_id = ?',
            [$survivorId, $loserId]
        );
        if ($repointed > 0) {
            $moved['event_collaborators.user_id'] = $repointed;
        }
    }

    /**
     * Rank two event-collaborator roles by capability breadth (per the event
     * capability map) and return the stronger one.
     */
    private function higherEventRole(string $a, string $b): string
    {
        $rank = [
            'venue_admin' => 7,
            'event_owner' => 6,
            'promoter'    => 5,
            'staff'       => 4,
            'designer'    => 3,
            'band'        => 2,
            'artist'      => 2,
            'viewer'      => 1,
        ];
        $ra = $rank[$a] ?? 0;
        $rb = $rank[$b] ?? 0;
        return $rb > $ra ? $b : $a;
    }

    /**
     * Fold the loser's primary email and every alt_email into the survivor's
     * alt_emails as VERIFIED entries. Skips any address that already belongs to
     * another user (emailIsTaken with $exceptUserId = survivor) or that is
     * already the survivor's primary/alias. Returns the addresses actually added.
     *
     * @return array<int,string>
     */
    private function foldEmails(array $survivor, array $loser): array
    {
        $survivorId      = (int) $survivor['id'];
        $survivorPrimary = strtolower(trim((string) $survivor['email']));

        $existing = [];
        foreach (Identity::altEmails($survivor) as $entry) {
            $addr = strtolower(trim((string) ($entry['email'] ?? '')));
            if ($addr !== '') {
                $existing[$addr] = $entry;
            }
        }

        // Candidate addresses from the loser, primary first.
        $candidates = [];
        $loserPrimary = strtolower(trim((string) $loser['email']));
        if ($loserPrimary !== '') {
            $candidates[] = $loserPrimary;
        }
        foreach (Identity::altEmails($loser) as $entry) {
            $addr = strtolower(trim((string) ($entry['email'] ?? '')));
            if ($addr !== '') {
                $candidates[] = $addr;
            }
        }

        $now    = (new \DateTimeImmutable('now'))->format('c');
        $folded = [];

        foreach (array_values(array_unique($candidates)) as $addr) {
            if ($addr === $survivorPrimary || isset($existing[$addr])) {
                continue; // already the survivor's
            }
            // Now that the loser row still exists, exclude BOTH ids from the
            // taken check: the address legitimately belongs to the loser.
            if ($this->emailIsTakenExcluding($addr, [$survivorId, (int) $loser['id']])) {
                continue; // belongs to a third party — never steal it
            }
            $existing[$addr] = [
                'email'       => $addr,
                'verified_at' => $now,   // loser's address is known-good
                'added_at'    => $now,
            ];
            $folded[] = $addr;
        }

        $merged = array_values($existing);
        $this->db->run(
            'UPDATE users SET alt_emails = ? WHERE id = ?',
            [json_encode($merged), $survivorId]
        );

        return $folded;
    }

    /**
     * Like Identity::emailIsTaken but tolerant of multiple "self" ids (survivor
     * AND loser) so folding the loser's own address is not flagged as taken.
     */
    private function emailIsTakenExcluding(string $e, array $exceptIds): bool
    {
        $e = strtolower(trim($e));
        if ($e === '') {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($exceptIds), '?'));

        $primary = $this->db->one(
            "SELECT id FROM users WHERE email = ? AND id NOT IN ($placeholders) LIMIT 1",
            array_merge([$e], $exceptIds)
        );
        if ($primary) {
            return true;
        }

        $alias = $this->db->one(
            "SELECT id FROM users WHERE ? MEMBER OF (alt_emails->'$[*].email') AND id NOT IN ($placeholders) LIMIT 1",
            array_merge([$e], $exceptIds)
        );

        return (bool) $alias;
    }

    /** @return array<int,string> */
    private function signalsFor(array $a, array $b): array
    {
        $signals = [];
        if ($this->normalizeName((string) $a['name']) !== ''
            && $this->normalizeName((string) $a['name']) === $this->normalizeName((string) $b['name'])) {
            $signals[] = 'name';
        }
        $pa = $this->normalizePhone((string) ($a['phone'] ?? ''));
        $pb = $this->normalizePhone((string) ($b['phone'] ?? ''));
        if ($pa !== '' && $pa === $pb) {
            $signals[] = 'phone';
        }
        $ca = Identity::canonical((string) $a['email']);
        $cb = Identity::canonical((string) $b['email']);
        if ($ca !== '' && $ca === $cb) {
            $signals[] = 'gmail-canonical';
        }
        return $signals;
    }

    private function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        return (string) preg_replace('/\s+/', ' ', $name);
    }

    private function normalizePhone(string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone);
    }

    /** @return array{id:int,name:string,email:string,role:string} */
    private function publicUser(array $u): array
    {
        return [
            'id'    => (int) $u['id'],
            'name'  => (string) $u['name'],
            'email' => (string) $u['email'],
            'role'  => (string) $u['role'],
        ];
    }
}

/** Bad merge input (same id, missing user). */
final class MergeValidationException extends RuntimeException {}

/** Survivor and loser roles differ and no override was supplied. */
final class RoleMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $survivorRole,
        public readonly string $loserRole
    ) {
        parent::__construct("Role mismatch: survivor=$survivorRole loser=$loserRole");
    }
}
