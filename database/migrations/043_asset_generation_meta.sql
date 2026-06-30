-- Add generation metadata columns to event_assets for AI-generated files.
-- generation_source: where the asset came from (e.g. 'codex-ai', 'upload')
-- generation_prompt: the prompt used to generate the asset (AI only)

ALTER TABLE event_assets
  ADD COLUMN generation_source varchar(50)  DEFAULT NULL AFTER notes,
  ADD COLUMN generation_prompt text          DEFAULT NULL AFTER generation_source;
