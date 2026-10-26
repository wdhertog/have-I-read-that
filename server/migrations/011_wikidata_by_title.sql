-- Wikidata now also searches by title and author: look books without a series up
-- again under Import/export → Gegevens aanvullen. Filled-in fields are never overwritten.
UPDATE books SET enriched_at = NULL WHERE (series IS NULL OR series = '') AND isbn IS NOT NULL AND isbn != '';
