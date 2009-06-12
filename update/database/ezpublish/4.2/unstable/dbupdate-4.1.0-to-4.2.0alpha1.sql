UPDATE ezsite_data SET value='4.2.0alpha1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

-- START: from 4.1.4
CREATE INDEX ezkeyword_attr_link_oaid ON ezkeyword_attribute_link (objectattribute_id);
CREATE INDEX ezinfocollection_co_id_created ON ezinfocollection( contentobject_id, created );
-- END: from 4.1.4

-- START: from 4.1.2
ALTER TABLE ezsession MODIFY (user_hash NULL);
-- END: from 4.1.2

-- START: from 4.1.1
ALTER TABLE ezworkflow_event MODIFY (
    data_text1 VARCHAR2(255),
    data_text2 VARCHAR2(255),
    data_text3 VARCHAR2(255),
    data_text4 VARCHAR2(255));
-- END: from 4.1.1

-- START: from 4.1.0
-- END: from 4.1.0
