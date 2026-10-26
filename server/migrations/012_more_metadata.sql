-- More about a book from Wikidata, Wikipedia, Open Library, the KB and Hardcover.
ALTER TABLE books
    ADD COLUMN description TEXT NULL,
    ADD COLUMN genre VARCHAR(255) NULL,
    ADD COLUMN original_title VARCHAR(500) NULL,
    ADD COLUMN original_language VARCHAR(20) NULL,
    ADD COLUMN translator VARCHAR(255) NULL,
    ADD COLUMN first_published SMALLINT NULL,
    ADD COLUMN awards VARCHAR(1000) NULL;

-- Look every book with an ISBN up again under Import/export → Gegevens aanvullen.
-- Filled-in fields are never overwritten.
UPDATE books SET enriched_at = NULL WHERE isbn IS NOT NULL AND isbn != '';
