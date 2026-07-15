-- 060_support_login.sql
--
-- Lets a platform super admin (super_admin_users, in the separate super-admin
-- registry DB) log into any tenant as a full site admin for customer
-- support, without ever having a per-tenant password. AuthEndpoint::login()
-- falls back to checking super_admin_users only after normal tenant-user
-- auth fails (never shadows a real tenant login), then finds-or-creates a
-- row in THIS table to hold that identity — a real users row, not a
-- synthetic in-memory user, so every existing permission/FK/activity-log
-- code path keeps working unmodified. See src/SupportLogin.php.
--
-- support_super_admin_id (never email!) is the join key. Keying by email
-- instead would let anyone pre-squat a super admin's address as their own
-- tenant account before support-login ever ran there — the real super admin
-- would then log into the attacker's row — and would silently lock out a
-- real person who later tries to sign up with that same address.
--
-- is_hidden keeps the row out of the tenant's own Users/Team page and every
-- assignment/notification picker (see call sites updated alongside this
-- migration) so a tenant's admins never see a mystery account they didn't
-- create.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS is_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER role,
  ADD COLUMN IF NOT EXISTS support_super_admin_id INT UNSIGNED NULL AFTER is_hidden;

ALTER TABLE users
  ADD UNIQUE KEY IF NOT EXISTS uq_support_super_admin (support_super_admin_id);
