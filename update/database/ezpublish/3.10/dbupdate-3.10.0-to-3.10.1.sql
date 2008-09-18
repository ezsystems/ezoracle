UPDATE ezsite_data SET value='3.10.1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='5' WHERE name='ezpublish-release';

CREATE TABLE ezurlwildcard (
  id integer NOT NULL,
  source_url varchar2(3000) NOT NULL,
  destination_url varchar2(3000) NOT NULL,
  type integer default 0 NOT NULL,
  PRIMARY KEY  (id)
);

CREATE SEQUENCE s_urlwildcard;
CREATE OR REPLACE TRIGGER ezurlwildcard_tr
BEFORE INSERT ON ezurlwildcard FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_urlwildcard.nextval INTO :new.id FROM dual;
END;
/

-- START: from 3.9.5
CREATE INDEX ezcontent_language_name ON ezcontent_language (name);

CREATE INDEX ezcontentobject_owner ON ezcontentobject (owner_id);

CREATE UNIQUE INDEX ezcontentobject_remote_id ON ezcontentobject (remote_id);
-- END: from 3.9.5
