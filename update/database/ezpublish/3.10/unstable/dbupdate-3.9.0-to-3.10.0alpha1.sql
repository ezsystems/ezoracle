UPDATE ezsite_data SET value='3.10.0alpha1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

-- extend length of 'serialized_name_list'
ALTER TABLE ezcontentclass  MODIFY (serialized_name_list VARCHAR2(3100) );
ALTER TABLE ezcontentclass_attribute MODIFY (serialized_name_list VARCHAR2(3100) );


-- Enhanced ISBN datatype.
CREATE TABLE ezisbn_group (
  id int NOT NULL,
  description varchar2(255) default '' NOT NULL ,
  group_number int default 0 NOT NULL,
  PRIMARY KEY  (id)
);
CREATE SEQUENCE s_isbn_group;
CREATE OR REPLACE TRIGGER ezisbn_group_tr
BEFORE INSERT ON ezisbn_group FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_isbn_group.nextval INTO :new.id FROM dual;
END;
/


CREATE TABLE ezisbn_group_range (
  id int NOT NULL,
  from_number int default 0 NOT NULL,
  to_number int default 0 NOT NULL,
  group_from varchar2(32) default '' NOT NULL,
  group_to varchar2(32) default '' NOT NULL,
  group_length int default 0 NOT NULL,
  PRIMARY KEY  (id)
);
CREATE SEQUENCE s_isbn_group_range;
CREATE OR REPLACE TRIGGER ezisbn_group_range_tr
BEFORE INSERT ON ezisbn_group_range FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_isbn_group_range.nextval INTO :new.id FROM dual;
END;
/


CREATE TABLE ezisbn_registrant_range (
  id int NOT NULL,
  from_number int default 0 NOT NULL,
  to_number int default 0 NOT NULL,
  registrant_from varchar2(32) default '' NOT NULL,
  registrant_to varchar2(32) default '' NOT NULL,
  registrant_length int default 0 NOT NULL,
  isbn_group_id int default 0 NOT NULL,
  PRIMARY KEY  (id)
);
CREATE SEQUENCE s_isbn_registrant_range;
CREATE OR REPLACE TRIGGER ezisbn_registrant_range_tr
BEFORE INSERT ON ezisbn_registrant_range FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_isbn_registrant_range.nextval INTO :new.id FROM dual;
END;
/



-- URL alias name pattern
ALTER TABLE ezcontentclass ADD url_alias_name VARCHAR2(255);

-- URL alias
CREATE TABLE ezurlalias_ml (
  id        integer DEFAULT 0 NOT NULL,
  link      integer DEFAULT 0 NOT NULL,
  parent    integer DEFAULT 0 NOT NULL,
  lang_mask integer DEFAULT 0 NOT NULL,
  text      varchar2(3000) DEFAULT '',
  text_md5  varchar2(32) DEFAULT '' NOT NULL,
  action    varchar2(3000) DEFAULT '' NOT NULL,
  action_type varchar2(32) DEFAULT '' NOT NULL,
  is_original integer DEFAULT 0 NOT NULL,
  is_alias    integer DEFAULT 0 NOT NULL,
  PRIMARY KEY(parent, text_md5)
  );


CREATE INDEX ezurlalias_ml_text_lang ON ezurlalias_ml( text, lang_mask, parent );
CREATE INDEX ezurlalias_ml_text ON ezurlalias_ml(text, id, link);
CREATE INDEX ezurlalias_ml_action ON ezurlalias_ml(action, id, link);
CREATE INDEX ezurlalias_ml_par_txt ON ezurlalias_ml(parent, text );
CREATE INDEX ezurlalias_ml_par_lnk_txt ON ezurlalias_ml(parent, link, text );
CREATE INDEX ezurlalias_ml_par_act_id_lnk ON ezurlalias_ml(parent, action, id, link);
CREATE INDEX ezurlalias_ml_id ON ezurlalias_ml(id);
CREATE INDEX ezurlalias_ml_act_org ON ezurlalias_ml(action,is_original);
CREATE INDEX ezurlalias_ml_actt_org_al ON ezurlalias_ml(action_type, is_original, is_alias);
CREATE INDEX ezurlalias_ml_actt ON ezurlalias_ml(action_type);


-- Update old urlalias table for the import
ALTER TABLE ezurlalias ADD is_imported integer DEFAULT 0 NOT NULL ;
CREATE INDEX ezurlalias_imp_wcard_fwd ON ezurlalias (is_imported, is_wildcard, forward_to_id);
CREATE INDEX ezurlalias_wcard_fwd ON ezurlalias (is_wildcard, forward_to_id);
DROP INDEX ezurlalias_is_wildcard;

ALTER TABLE ezvatrule RENAME COLUMN country TO country_code;
