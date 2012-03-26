UPDATE ezsite_data SET value='4.7.0beta1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

ALTER TABLE ezcontentobject_attribute ADD ( data_float_tmp BINARY_DOUBLE DEFAULT 0 NULL );
UPDATE ezcontentobject_attribute SET data_float_tmp = data_float;
ALTER TABLE ezcontentobject_attribute DROP ( data_float );
ALTER TABLE ezcontentobject_attribute RENAME COLUMN data_float_tmp TO data_float;
