-- Birth and death dates now keep their precision (a year is no longer shown as
-- 1 January): forget what was looked up, so author pages fetch it again.
DELETE FROM authors;
