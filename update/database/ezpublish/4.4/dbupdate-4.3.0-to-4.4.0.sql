UPDATE ezsite_data SET value='4.4.0' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

ALTER TABLE ezcontentobject DROP COLUMN is_published;

ALTER TABLE ezsection ADD identifier VARCHAR2(255) NULL;

CREATE INDEX ezinfocollection_attr_cca_id ON ezinfocollection_attribute( contentclass_attribute_id );
CREATE INDEX ezinfocollection_attr_coa_id ON ezinfocollection_attribute( contentobject_attribute_id );
CREATE INDEX ezinfocollection_attr_ic_id ON ezinfocollection_attribute( informationcollection_id );

ALTER TABLE ezpreferences RENAME COLUMN value TO value_temp;
ALTER TABLE ezpreferences ADD value CLOB NULL;
UPDATE ezpreferences set value=value_temp;
ALTER TABLE ezpreferences DROP COLUMN value_temp;


ALTER TABLE ezpolicy ADD original_id INTEGER DEFAULT 0 NOT NULL;
CREATE INDEX ezpolicy_original_id ON ezpolicy( original_id );

UPDATE ezcontentclass_attribute SET can_translate=0 WHERE data_type_string='ezuser';
