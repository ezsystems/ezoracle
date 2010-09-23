UPDATE ezsite_data SET value='4.4.0beta1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

CREATE INDEX ezinfocollection_attr_cca_id ON ezinfocollection_attribute( contentclass_attribute_id );
CREATE INDEX ezinfocollection_attr_coa_id ON ezinfocollection_attribute( contentobject_attribute_id );
CREATE INDEX ezinfocollection_attr_ic_id ON ezinfocollection_attribute( informationcollection_id );

ALTER TABLE ezsection RENAME COLUMN section_identifier TO identifier;
