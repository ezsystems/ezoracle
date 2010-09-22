UPDATE ezsite_data SET value='4.4.0beta1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

CREATE INDEX ezinfocollection_att_ca_id ON ezinfocollection_attribute( contentclass_attribute_id );
CREATE INDEX ezinfocollection_att_oa_id ON ezinfocollection_attribute( contentobject_attribute_id );
CREATE INDEX ezinfocollection_att_in_id ON ezinfocollection_attribute( informationcollection_id );

ALTER TABLE ezsection RENAME COLUMN section_identifier TO identifier;
