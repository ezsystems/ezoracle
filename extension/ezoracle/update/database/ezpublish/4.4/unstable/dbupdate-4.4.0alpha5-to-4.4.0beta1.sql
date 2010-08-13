UPDATE ezsite_data SET value='4.4.0beta1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

ALTER TABLE ezsection RENAME COLUMN section_identifier TO identifier;
