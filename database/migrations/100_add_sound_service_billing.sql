-- Issue #24: model sound billing like security billing and render both service
-- rates only when the counterparty participates in the cost.
ALTER TABLE contracts
  ADD COLUMN IF NOT EXISTS sound_rate DECIMAL(10,2) NULL AFTER security_paid_by,
  ADD COLUMN IF NOT EXISTS sound_paid_by ENUM('venue','artist','promoter','client','shared') NULL AFTER sound_rate;

-- Use complete, payer-aware terms rather than embedding an unconditional rate
-- token in the sentence. Update current draft sections as well as the library;
-- immutable rendered contract_versions are deliberately untouched.
UPDATE contract_modules
SET body_template = 'Security staffing shall consist of {{security_count}} licensed guard(s). {{security_terms}} Guards shall be on duty from doors through final load-out and clearance of the premises.'
WHERE module_key = 'security';

UPDATE contract_sections
SET body_template = 'Security staffing shall consist of {{security_count}} licensed guard(s). {{security_terms}} Guards shall be on duty from doors through final load-out and clearance of the premises.'
WHERE module_key = 'security';

UPDATE contract_modules
SET body_template = 'Production support for this event: sound technician — {{sound_tech_terms}} Lighting technician included — {{lighting_tech_included}}. The Counterparty shall deliver a stage plot and input list no later than seven (7) days before the event. Any equipment beyond the Venue''s standard package is the Counterparty''s responsibility.'
WHERE module_key = 'production';

UPDATE contract_sections
SET body_template = 'Production support for this event: sound technician — {{sound_tech_terms}} Lighting technician included — {{lighting_tech_included}}. The Counterparty shall deliver a stage plot and input list no later than seven (7) days before the event. Any equipment beyond the Venue''s standard package is the Counterparty''s responsibility.'
WHERE module_key = 'production';
