-- "Niet uitgelezen" is remembered per read, so reading a book again no longer
-- removes its earlier reads from the year stats and goal.
ALTER TABLE `reads` ADD COLUMN dnf TINYINT(1) NOT NULL DEFAULT 0;
UPDATE `reads` r JOIN books b ON b.id = r.book_id SET r.dnf = 1
WHERE b.status = 'dnf' AND r.finished IS NOT NULL;

-- Clearing out old login attempts shouldn't need to read the whole table.
CREATE INDEX login_attempts_time ON login_attempts (attempted_at);
