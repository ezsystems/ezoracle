UPDATE ezsite_data SET value='4.3.0alpha1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

ALTER TABLE ezrss_export_item ADD enclosure VARCHAR2( 255 ) NULL;
ALTER TABLE ezcontentclass ADD serialized_description_list longtext NULL;
ALTER TABLE ezcontentclass_attribute ADD serialized_data_text longtext NULL;
ALTER TABLE ezcontentclass_attribute ADD serialized_description_list longtext NULL;
ALTER TABLE ezcontentclass_attribute ADD category VARCHAR2( 25 ) NULL;
