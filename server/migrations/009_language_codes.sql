-- Store languages as two-letter codes; the KB lookup used to save "nederlands".
UPDATE books SET language = 'nl' WHERE LOWER(language) IN ('nederlands', 'dutch', 'dut', 'nld', 'vlaams');
UPDATE books SET language = 'en' WHERE LOWER(language) IN ('engels', 'english', 'eng');
UPDATE books SET language = 'de' WHERE LOWER(language) IN ('duits', 'german', 'deutsch', 'ger', 'deu');
UPDATE books SET language = 'fr' WHERE LOWER(language) IN ('frans', 'french', 'fre', 'fra');
