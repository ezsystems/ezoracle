UPDATE ezsite_data SET value='4.1.0alpha1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

ALTER TABLE ezworkflow_event ADD data_text5 CLOB;

ALTER TABLE ezrss_export ADD node_id INT NULL;
ALTER TABLE ezrss_export_item ADD category VARCHAR2( 255 ) NULL;

-- START: from 4.0.1
CREATE INDEX ezcontent_language_name ON ezcontent_language (name);

CREATE INDEX ezcobj_owner ON ezcontentobject (owner_id);

CREATE UNIQUE INDEX ezcobj_remote_id ON ezcontentobject (remote_id);
-- END: from 4.0.1

CREATE UNIQUE INDEX ezgeneral_digest_user_sett_add on ezgeneral_digest_user_settings(address);
DELETE FROM ezgeneral_digest_user_settings WHERE address not in (SELECT email FROM ezuser) OR address is null;

-- START: from 3.10.1
ALTER TABLE ezurlalias_ml ADD alias_redirects number(11) default 1 NOT NULL;
-- END: from 3.10.1

ALTER TABLE ezbinaryfile MODIFY (mime_type VARCHAR2(255));

CREATE TABLE ezcobj_state (
    default_language_id integer DEFAULT 0 NOT NULL,
    group_id integer DEFAULT 0 NOT NULL,
    id integer NOT NULL,
    identifier varchar2(45) DEFAULT '' NOT NULL,
    language_mask integer DEFAULT 0 NOT NULL,
    priority integer DEFAULT 0 NOT NULL
);
CREATE UNIQUE INDEX ezcobj_state_identifier ON ezcobj_state (group_id, identifier);
CREATE INDEX ezcobj_state_lmask ON ezcobj_state (language_mask);
CREATE INDEX ezcobj_state_priority ON ezcobj_state (priority);

CREATE TABLE ezcobj_state_group (
    default_language_id integer DEFAULT 0 NOT NULL,
    id integer NOT NULL,
    identifier varchar2(45) DEFAULT '' NOT NULL,
    language_mask integer DEFAULT 0 NOT NULL
);
CREATE UNIQUE INDEX ezcobj_state_group_identifier ON ezcobj_state_group (identifier);
CREATE INDEX ezcobj_state_group_lmask ON ezcobj_state_group (language_mask);

CREATE TABLE ezcobj_state_group_language (
    contentobject_state_group_id integer DEFAULT 0 NOT NULL,
    description clob NULL,
    language_id integer DEFAULT 0 NOT NULL,
    name varchar2(45) DEFAULT '' NOT NULL
);

CREATE TABLE ezcobj_state_language (
    contentobject_state_id integer DEFAULT 0 NOT NULL,
    description clob NULL,
    language_id integer DEFAULT 0 NOT NULL,
    name varchar2(45) DEFAULT '' NOT NULL
);

CREATE TABLE ezcobj_state_link (
    contentobject_id integer DEFAULT 0 NOT NULL,
    contentobject_state_id integer DEFAULT 0 NOT NULL
);

ALTER TABLE ezcobj_state
    ADD PRIMARY KEY (id);

ALTER TABLE ezcobj_state_group
    ADD PRIMARY KEY (id);

ALTER TABLE ezcobj_state_group_language
    ADD PRIMARY KEY (contentobject_state_group_id, language_id);

ALTER TABLE ezcobj_state_language
    ADD PRIMARY KEY (contentobject_state_id, language_id);

ALTER TABLE ezcobj_state_link
    ADD PRIMARY KEY (contentobject_id, contentobject_state_id);

CREATE SEQUENCE s_cobj_state;
CREATE OR REPLACE TRIGGER ezcobj_state_id_tr
BEFORE INSERT ON ezcobj_state FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_cobj_state.nextval INTO :new.id FROM dual;
END;
/

CREATE SEQUENCE s_cobj_state_group;
CREATE OR REPLACE TRIGGER ezcobj_state_group_id_tr
BEFORE INSERT ON ezcobj_state_group FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_cobj_state_group.nextval INTO :new.id FROM dual;
END;
/

ALTER TABLE ezuservisit ADD login_count number(11) default 0 NOT NULL;

CREATE INDEX ezuservisit_co_visit_count ON  ezuservisit ( current_visit_timestamp, login_count );

CREATE INDEX ezforgot_password_user ON ezforgot_password (user_id);

ALTER TABLE ezorder_item modify ( vat_value number default 0 );

CREATE TABLE ezurlalias_ml_incr (
    id integer NOT NULL
);

CREATE SEQUENCE s_urlalias_ml_incr;
CREATE OR REPLACE TRIGGER ezurlalias_ml_incr_id_tr
BEFORE INSERT ON ezurlalias_ml_incr FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_urlalias_ml_incr.nextval INTO :new.id FROM dual;
END;
/