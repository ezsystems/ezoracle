CREATE OR replace FUNCTION bitor( x IN NUMBER, y IN NUMBER )
RETURN NUMBER  AS
--
-- Return an bitwise 'or' value of the input arguments.
--
BEGIN
  RETURN x + y - bitand(x,y);
END bitor;
/

-- The following modifications are changes from the MySQL to Oracle schema that
-- are part of the database init scripts in eZ Oracle 1.6 but not in 1.5

-- simple cases
ALTER TABLE ezrss_import MODIFY (url varchar2(3100));
ALTER TABLE ezrss_import MODIFY (import_description NULL);
ALTER TABLE ezgeneral_digest_user_settings MODIFY (time NULL);
ALTER TABLE ezproductcollection MODIFY (currency_code NULL);
ALTER TABLE ezmedia MODIFY (filename NULL);
ALTER TABLE ezmedia MODIFY (original_filename NULL);
ALTER TABLE ezmedia MODIFY (mime_type NULL);

-- cases involving LOBs: we need to rebuild tables
ALTER TABLE ezurl RENAME TO ezurl_backup;
CREATE TABLE ezurl (
  CREATED INTEGER DEFAULT 0 NOT NULL,
  ID INTEGER NOT NULL,
  IS_VALID INTEGER DEFAULT 1 NOT NULL,
  LAST_CHECKED INTEGER DEFAULT 0 NOT NULL,
  MODIFIED INTEGER DEFAULT 0 NOT NULL,
  ORIGINAL_URL_MD5 VARCHAR2(32) DEFAULT '' NOT NULL,
  URL VARCHAR2(3000),
  PRIMARY KEY (ID) );
INSERT INTO ezurl SELECT * FROM ezurl_backup;
CREATE INDEX ezurl_url ON ezurl (url);
DROP TRIGGER ezurl_id_tr;
CREATE OR REPLACE TRIGGER ezurl_id_tr
BEFORE INSERT ON ezurl FOR EACH ROW  WHEN (new.id IS NULL) BEGIN
  SELECT s_url.nextval INTO :new.id FROM dual;
END;
/

ALTER TABLE ezpending_actions RENAME TO ezpending_actions_backup;
CREATE TABLE ezpending_actions (
  action VARCHAR2(64) DEFAULT '' NOT NULL,
  param VARCHAR2( 3000 )
);
INSERT INTO ezpending_actions SELECT * FROM ezpending_actions_backup;
DROP INDEX ezpending_actions_action;
CREATE INDEX ezpending_actions_action ON ezpending_actions (action);
