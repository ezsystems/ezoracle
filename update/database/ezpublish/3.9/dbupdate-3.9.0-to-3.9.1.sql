UPDATE ezsite_data SET value='3.9.1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='7' WHERE name='ezpublish-release';

-- extend length of 'serialized_name_list'
ALTER TABLE ezcontentclass  MODIFY (serialized_name_list VARCHAR2(3100) );
ALTER TABLE ezcontentclass_attribute MODIFY (serialized_name_list VARCHAR2(3100) );
