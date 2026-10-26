-- Imported StoryGraph formats ("digital", "Paperback", ...) onto the fixed list, so
-- editing an imported book no longer wipes its format.
UPDATE books SET format = LOWER(TRIM(format)) WHERE format IS NOT NULL;
UPDATE books SET format = 'ebook' WHERE format IN ('digital', 'e-book');
UPDATE books SET format = 'audio' WHERE format = 'audiobook';
UPDATE books SET format = NULL WHERE format NOT IN ('paperback', 'hardcover', 'ebook', 'audio');
