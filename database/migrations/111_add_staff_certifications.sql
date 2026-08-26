-- Staff Handbook & Compliance, part 2: certification/training tracking
-- foundation. Deliberately minimal -- data model only, so a future UI phase
-- has somewhere to land -- see docs/staff/knowledge-audit.md.
--
-- Certifications attach to `staff_members` (the existing venue roster),
-- not `users`, because a certification can apply to a contractor or staff
-- member who has no Backstage login at all (staff_members.user_id is
-- nullable) -- unlike document acknowledgments, which require an
-- authenticated session and so key off `users`.

CREATE TABLE IF NOT EXISTS `staff_certification_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(60) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `expiration_required` tinyint(1) NOT NULL DEFAULT 0,
  `default_validity_months` int(11) DEFAULT NULL COMMENT 'Typical validity period in months, used only to suggest an expires_at when recording a new certification -- not enforced',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_certification_types_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_certifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_member_id` int(11) NOT NULL,
  `certification_type_id` int(11) NOT NULL,
  `issued_at` date DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `certificate_number` varchar(100) DEFAULT NULL,
  `document_path` varchar(255) DEFAULT NULL COMMENT 'Uploaded proof of certification, under storage/uploads',
  `verified_at` datetime DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_staff_certifications_staff_member_id` (`staff_member_id`),
  KEY `idx_staff_certifications_type_id` (`certification_type_id`),
  KEY `idx_staff_certifications_expires_at` (`expires_at`),
  CONSTRAINT `staff_certifications_ibfk_1` FOREIGN KEY (`staff_member_id`) REFERENCES `staff_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `staff_certifications_ibfk_2` FOREIGN KEY (`certification_type_id`) REFERENCES `staff_certification_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `staff_certifications_ibfk_3` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
