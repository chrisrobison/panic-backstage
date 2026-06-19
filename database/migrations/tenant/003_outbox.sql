-- Migration 003 (tenant): outbox — persisted record of every outgoing transactional email.
-- Stored by Mailer on every send() call so admins can browse, search, and
-- preview all messages the system has sent.
CREATE TABLE IF NOT EXISTS `outbox` (
  `id`          BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `sent_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `to_address`  VARCHAR(320) NOT NULL,
  `subject`     VARCHAR(998) NOT NULL DEFAULT '',
  `text_body`   MEDIUMTEXT   DEFAULT NULL,
  `html_body`   MEDIUMTEXT   DEFAULT NULL,
  `template`    VARCHAR(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sent_at` (`sent_at`),
  KEY `idx_to` (`to_address`(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
