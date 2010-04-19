UPDATE ezsite_data SET value='4.0.0' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='5' WHERE name='ezpublish-release';

DELETE FROM ezuser_setting where user_id not in (SELECT contentobject_id FROM ezuser);

DELETE FROM ezcontentclass_classgroup WHERE NOT EXISTS (SELECT * FROM ezcontentclass c WHERE c.id=contentclass_id AND c.version=contentclass_version);

-- START: from 3.10.1
CREATE TABLE ezurlwildcard (
  id integer NOT NULL,
  source_url varchar2(3000) NOT NULL,
  destination_url varchar2(3000) NOT NULL,
  type integer default 0 NOT NULL,
  PRIMARY KEY  (id)
);

CREATE SEQUENCE s_urlwildcard;
CREATE OR REPLACE TRIGGER ezurlwildcard_id_tr
BEFORE INSERT ON ezurlwildcard FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_urlwildcard.nextval INTO :new.id FROM dual;
END;
/
-- END: from 3.10.1