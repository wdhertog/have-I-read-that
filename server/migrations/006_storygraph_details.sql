-- The rest of a StoryGraph export: contributors, content warnings, and the review
-- questions (pace, characters) plus any column we don't know, as JSON text.
ALTER TABLE books
    ADD COLUMN contributors VARCHAR(1000) NULL,
    ADD COLUMN content_warnings TEXT NULL,
    ADD COLUMN content_warning_description TEXT NULL,
    ADD COLUMN details TEXT NULL;
