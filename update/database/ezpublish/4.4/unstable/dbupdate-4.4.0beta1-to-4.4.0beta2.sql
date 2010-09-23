UPDATE ezsite_data SET value='4.4.0beta2' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';


ALTER TABLE ezpreferences RENAME COLUMN value TO value_temp;
ALTER TABLE ezpreferences ADD value CLOB NULL;
UPDATE ezpreferences set value=value_temp;
ALTER TABLE ezpreferences DROP COLUMN value_temp;

ALTER TABLE ezpolicy ADD original_id INTEGER DEFAULT 0 NOT NULL;
CREATE INDEX ezpolicy_original_id ON ezpolicy( original_id );

UPDATE ezcontentclass_attribute SET can_translate=0 WHERE data_type_string='ezuser';
