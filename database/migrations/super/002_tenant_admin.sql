-- Track who the first admin login was created for when a tenant is
-- provisioned, so TenantProvisioner can seed that person's login instead of
-- a hardcoded demo account, and so the super console can show who owns each
-- tenant.
ALTER TABLE tenants
  ADD COLUMN admin_name  VARCHAR(160) DEFAULT NULL AFTER name,
  ADD COLUMN admin_email VARCHAR(255) DEFAULT NULL AFTER admin_name;
