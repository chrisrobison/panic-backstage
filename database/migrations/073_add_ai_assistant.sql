-- AI Assistant drawer (Phase 1: read-only Q&A) — see docs/AI-ASSISTANT-PLAN.md.
--
-- `ai_conversations` / `ai_messages` back the chat transcript for a
-- right-side admin-only drawer. A conversation is optionally scoped to one
-- event (opened from an event workspace tab) but can also be a general,
-- cross-event conversation (event_id NULL, opened from the topbar).
--
-- `ai_messages.role` follows the shape of a tool-using chat transcript:
-- 'user' / 'assistant' are the visible turns; 'tool_call' / 'tool_result'
-- record what the model asked the MCP server (scripts/ai-mcp-server.php)
-- and what came back, for debugging/audit — Phase 1 only exposes read-only
-- tools (get_event, list_events), so these rows never represent a write.
--
-- `ai_action_proposals` is created now, unused, so Phase 2 (propose/apply
-- write tools) doesn't need a second migration for one table. A proposal is
-- a server-computed diff the model *proposes*; applying it is always a
-- separate, human-clicked REST call (POST /api/ai/proposals/{id}/apply) —
-- the model itself never has an `apply_*` tool. `expires_at` bounds how
-- long a stale proposal can be replayed after the underlying data may have
-- changed (see Ai\Assistant, Phase 2).
CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `event_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_conversations_user` (`user_id`),
  KEY `idx_ai_conversations_event` (`event_id`),
  CONSTRAINT `ai_conversations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `ai_conversations_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11) NOT NULL,
  `role` enum('user','assistant','tool_call','tool_result') NOT NULL,
  `content` longtext DEFAULT NULL,
  `tool_name` varchar(100) DEFAULT NULL,
  `tool_args_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_messages_conversation` (`conversation_id`),
  CONSTRAINT `ai_messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_action_proposals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `event_id` int(11) DEFAULT NULL,
  `tool_name` varchar(100) NOT NULL,
  `args_json` longtext NOT NULL,
  `diff_json` longtext NOT NULL,
  `status` enum('pending','applied','discarded','expired') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL,
  `applied_at` timestamp NULL DEFAULT NULL,
  `applied_by_user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ai_proposals_conversation` (`conversation_id`),
  KEY `idx_ai_proposals_user` (`user_id`),
  KEY `idx_ai_proposals_status` (`status`),
  CONSTRAINT `ai_action_proposals_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_action_proposals_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `ai_action_proposals_ibfk_3` FOREIGN KEY (`applied_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
