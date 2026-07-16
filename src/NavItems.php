<?php
declare(strict_types=1);

namespace Panic;

use function Panic\boolish;

/**
 * App shell main navigation structure — the data behind Admin > Navigation
 * (the "Navigation Manager") and the source the app shell's sidebar renders
 * from (see public/assets/nav-shared.js).
 *
 *   GET    /api/nav-items            list all items (any authenticated user —
 *                                     every user's sidebar needs this, same
 *                                     as /api/me)
 *   POST   /api/nav-items            create                  (manage_navigation)
 *   PATCH  /api/nav-items/{id}       update                  (manage_navigation)
 *   DELETE /api/nav-items/{id}       delete (cascades to any children)
 *                                                             (manage_navigation)
 *   POST   /api/nav-items/reorder    bulk {id, parent_id, sort_order} update,
 *                                     used by the drag-and-drop list
 *                                                             (manage_navigation)
 *
 * Nav is at most two levels deep (top-level items + one level of children),
 * matching what the .nav-group/.nav-children sidebar CSS actually supports.
 * That's enforced here, not just in the UI.
 */
final class NavItems extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAuth()) {
            return $denied;
        }

        if (($this->params['action'] ?? null) === 'reorder') {
            if ($request->method() !== 'POST') {
                return Response::methodNotAllowed();
            }
            if ($denied = $this->requireGlobalCapability('manage_navigation')) {
                return $denied;
            }
            return $this->reorder($request);
        }

        $itemId = $this->params['itemId'] ?? null;

        return match ($request->method()) {
            'GET' => $this->index(),
            'POST' => $this->guardWrite() ?? $this->create($request),
            'PATCH' => $this->guardWrite() ?? $this->update($request, (int) $itemId),
            'DELETE' => $this->guardWrite() ?? $this->deleteItem((int) $itemId),
            default => Response::methodNotAllowed(),
        };
    }

    private function guardWrite(): ?Response
    {
        return $this->requireGlobalCapability('manage_navigation');
    }

    private function index(): Response
    {
        $items = $this->db->all('SELECT * FROM nav_items ORDER BY parent_id IS NOT NULL, sort_order, id');
        return $this->ok([
            'items' => array_map([$this, 'cast'], $items),
            'capabilities' => array_values(array_keys($this->globalCapabilities())),
        ]);
    }

    private function create(Request $request): Response
    {
        [$payload, $error] = $this->payload($request, isCreate: true);
        if ($error) {
            return $error;
        }

        $id = $this->db->insert(
            'INSERT INTO nav_items (parent_id, label, icon, link, capability, open_in_new_window, visible, is_home, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $payload['parent_id'],
                $payload['label'],
                $payload['icon'],
                $payload['link'],
                $payload['capability'],
                $payload['open_in_new_window'],
                $payload['visible'],
                $payload['is_home'],
                $payload['sort_order'],
            ]
        );

        if ($payload['is_home']) {
            $this->db->run('UPDATE nav_items SET is_home = 0 WHERE id != ?', [$id]);
        }

        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $id): Response
    {
        if (!$id) {
            return $this->notFound();
        }
        $existing = $this->db->one('SELECT id, parent_id FROM nav_items WHERE id = ?', [$id]);
        if (!$existing) {
            return $this->notFound('Navigation item not found');
        }

        [$payload, $error] = $this->payload($request, isCreate: false, existingId: $id);
        if ($error) {
            return $error;
        }

        // Refuse to turn a parent-with-children into a child (would produce a
        // 3rd nav level, which the sidebar CSS doesn't support).
        if ($payload['parent_id'] !== null) {
            $hasChildren = $this->db->one('SELECT id FROM nav_items WHERE parent_id = ? LIMIT 1', [$id]);
            if ($hasChildren) {
                return Response::json(['error' => 'This item has its own children — remove them before nesting it under another item'], 422);
            }
        }

        $this->db->run(
            'UPDATE nav_items SET parent_id=?, label=?, icon=?, link=?, capability=?, open_in_new_window=?, visible=?, is_home=?, sort_order=? WHERE id=?',
            [
                $payload['parent_id'],
                $payload['label'],
                $payload['icon'],
                $payload['link'],
                $payload['capability'],
                $payload['open_in_new_window'],
                $payload['visible'],
                $payload['is_home'],
                $payload['sort_order'],
                $id,
            ]
        );

        if ($payload['is_home']) {
            $this->db->run('UPDATE nav_items SET is_home = 0 WHERE id != ?', [$id]);
        }

        return $this->ok(['ok' => true]);
    }

    private function deleteItem(int $id): Response
    {
        if (!$id) {
            return $this->notFound();
        }
        // parent_id has ON DELETE CASCADE — deleting a parent removes its children too.
        $this->db->run('DELETE FROM nav_items WHERE id = ?', [$id]);
        return Response::noContent();
    }

    private function reorder(Request $request): Response
    {
        $items = $request->body('items');
        if (!is_array($items) || !$items) {
            return Response::json(['error' => 'items is required'], 422);
        }

        $ids = array_map(static fn ($row) => (int) ($row['id'] ?? 0), $items);
        $existing = $this->db->all(
            'SELECT id, parent_id FROM nav_items WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $existingIds = array_column($existing, 'id');
        $childless = [];
        foreach ($existing as $row) {
            $childless[(int) $row['id']] = true;
        }

        $incomingIds = [];
        foreach ($items as $row) {
            $id = (int) ($row['id'] ?? 0);
            $parentId = isset($row['parent_id']) && $row['parent_id'] !== '' && $row['parent_id'] !== null
                ? (int) $row['parent_id']
                : null;

            if (!in_array($id, $existingIds, true)) {
                return Response::json(['error' => "Unknown nav item id $id"], 422);
            }
            if ($id === $parentId) {
                return Response::json(['error' => 'An item cannot be its own parent'], 422);
            }
            if ($parentId !== null) {
                if (!in_array($parentId, $existingIds, true)) {
                    return Response::json(['error' => "Unknown parent id $parentId"], 422);
                }
                // The chosen parent must itself be a top-level item (2-level max).
                $parentRow = $this->db->one('SELECT parent_id FROM nav_items WHERE id = ?', [$parentId]);
                if ($parentRow && $parentRow['parent_id'] !== null) {
                    return Response::json(['error' => 'Cannot nest under an item that is itself a child'], 422);
                }
            }
            $incomingIds[] = $id;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            foreach ($items as $row) {
                $id = (int) ($row['id'] ?? 0);
                $parentId = isset($row['parent_id']) && $row['parent_id'] !== '' && $row['parent_id'] !== null
                    ? (int) $row['parent_id']
                    : null;
                $sortOrder = (int) ($row['sort_order'] ?? 0);
                $this->db->run('UPDATE nav_items SET parent_id = ?, sort_order = ? WHERE id = ?', [$parentId, $sortOrder, $id]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $this->ok(['ok' => true]);
    }

    /** @return array{0: array, 1: ?Response} */
    private function payload(Request $request, bool $isCreate, ?int $existingId = null): array
    {
        $label = trim((string) $request->body('label', ''));
        if ($label === '') {
            return [[], Response::json(['error' => 'label is required'], 422)];
        }

        // Link is optional: a purely-grouping parent (e.g. "Events", "Admin")
        // renders as a button with children, not an anchor, so it has no
        // route of its own. Leaf items should set one, but that's enforced
        // by the admin UI, not here, since "will this end up with children"
        // isn't known at save time.
        $link = trim((string) $request->body('link', ''));
        if (str_starts_with($link, '#')) {
            $link = ltrim($link, '#');
        }
        $link = $link === '' ? null : $link;

        $icon = trim((string) $request->body('icon', ''));
        if ($icon === '') {
            $icon = 'fa-solid fa-circle';
        }

        $parentId = $request->body('parent_id');
        $parentId = ($parentId === '' || $parentId === null) ? null : (int) $parentId;
        if ($parentId !== null) {
            if ($parentId === $existingId) {
                return [[], Response::json(['error' => 'An item cannot be its own parent'], 422)];
            }
            $parent = $this->db->one('SELECT id, parent_id FROM nav_items WHERE id = ?', [$parentId]);
            if (!$parent) {
                return [[], Response::json(['error' => 'Unknown parent item'], 422)];
            }
            if ($parent['parent_id'] !== null) {
                return [[], Response::json(['error' => 'Cannot nest under an item that is itself a child (2 levels max)'], 422)];
            }
        }

        $capability = trim((string) $request->body('capability', ''));
        $capability = $capability === '' ? null : $capability;

        return [[
            'parent_id' => $parentId,
            'label' => $label,
            'icon' => $icon,
            'link' => $link,
            'capability' => $capability,
            'open_in_new_window' => boolish($request->body('open_in_new_window', 0)),
            'visible' => boolish($request->body('visible', 1)),
            'is_home' => boolish($request->body('is_home', 0)),
            'sort_order' => (int) $request->body('sort_order', 0),
        ], null];
    }

    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['parent_id'] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        $row['open_in_new_window'] = (bool) $row['open_in_new_window'];
        $row['visible'] = (bool) $row['visible'];
        $row['is_home'] = (bool) $row['is_home'];
        $row['sort_order'] = (int) $row['sort_order'];
        return $row;
    }
}
