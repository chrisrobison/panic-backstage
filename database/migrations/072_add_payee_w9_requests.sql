-- Collect a promoter/band's mailing address and W-9 for 1099/payout purposes.
--
-- `payees` is a reusable profile keyed by email — the same promoter/band
-- playing a future event doesn't have to re-submit their address or W-9.
-- The W-9 itself is never parsed/stored as structured tax-ID fields (SSN/EIN
-- never touch this schema): the payee uploads their own completed/signed W-9
-- PDF (or scanned image) and we just store the file, outside the public web
-- root — see src/PayeeSubmissionEndpoint.php.
--
-- `payee_requests` tracks one outbound "please submit your W-9 / address"
-- email per event, mirroring contract_signers' hashed/single-use/expiring
-- token pattern (src/Contracts.php / src/ContractSigningEndpoint.php): only
-- sha256(token) is ever persisted, and the token is nulled out once used.
CREATE TABLE IF NOT EXISTS `payees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `email` varchar(320) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `company` varchar(255) DEFAULT NULL,
  `mailing_address_line1` varchar(255) DEFAULT NULL,
  `mailing_address_line2` varchar(255) DEFAULT NULL,
  `mailing_city` varchar(120) DEFAULT NULL,
  `mailing_state` varchar(60) DEFAULT NULL,
  `mailing_zip` varchar(20) DEFAULT NULL,
  `mailing_country` varchar(60) NOT NULL DEFAULT 'US',
  `w9_file_path` varchar(500) DEFAULT NULL COMMENT 'relative to project root, outside public/ — see PayeeSubmissionEndpoint',
  `w9_original_filename` varchar(255) DEFAULT NULL,
  `w9_uploaded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_payees_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payee_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `payee_id` int(11) NOT NULL,
  `recipient_name` varchar(255) NOT NULL DEFAULT '',
  `recipient_email` varchar(320) NOT NULL DEFAULT '',
  `status` enum('pending','sent','viewed','submitted','expired','voided') NOT NULL DEFAULT 'pending',
  `token_hash` varchar(64) DEFAULT NULL COMMENT 'sha256(raw_token) — raw token is never persisted',
  `token_expires_at` datetime DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `viewed_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_payee_requests_event` (`event_id`),
  KEY `idx_payee_requests_payee` (`payee_id`),
  KEY `idx_payee_requests_token` (`token_hash`),
  KEY `idx_payee_requests_status` (`status`),
  CONSTRAINT `payee_requests_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payee_requests_ibfk_2` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
