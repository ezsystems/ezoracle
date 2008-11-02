UPDATE ezsite_data SET value='4.1.0alpha1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

ALTER TABLE ezworkflow_event ADD data_text5 CLOB;

ALTER TABLE ezrss_export ADD node_id INT NULL;
ALTER TABLE ezrss_export_item ADD category VARCHAR2( 255 ) NULL;

-- START: from 4.0.1
CREATE INDEX ezcontent_language_name ON ezcontent_language (name);

CREATE INDEX ezcontentobject_owner ON ezcontentobject (owner_id);

CREATE UNIQUE INDEX ezcontentobject_remote_id ON ezcontentobject (remote_id);
-- END: from 4.0.1

CREATE UNIQUE INDEX ezgeneral_digest_user_sett_add on ezgeneral_digest_user_settings(address);
DELETE FROM ezgeneral_digest_user_settings WHERE address not in (SELECT email FROM ezuser);

-- START: from 3.10.1
ALTER TABLE ezurlalias_ml ADD alias_redirects number(11) default 1 NOT NULL;
-- END: from 3.10.1

ALTER TABLE ezbinaryfile MODIFY (mime_type VARCHAR2(255));

CREATE TABLE ezcontentobject_state (
    default_language_id integer DEFAULT 0 NOT NULL,
    group_id integer DEFAULT 0 NOT NULL,
    id integer NOT NULL,
    identifier varchar2(45) DEFAULT '' NOT NULL,
    language_mask integer DEFAULT 0 NOT NULL,
    priority integer DEFAULT 0 NOT NULL
);

CREATE TABLE ezcontentobject_state_group (
    default_language_id integer DEFAULT 0 NOT NULL,
    id integer NOT NULL,
    identifier varchar2(45) DEFAULT '' NOT NULL,
    language_mask integer DEFAULT 0 NOT NULL
);

CREATE TABLE ezcontentobject_state_group_language (
    contentobject_state_group_id integer DEFAULT 0 NOT NULL,
    description text NOT NULL,
    language_id integer DEFAULT 0 NOT NULL,
    name varchar2(45) DEFAULT '' NOT NULL
);

CREATE TABLE ezcontentobject_state_language (
    contentobject_state_id integer DEFAULT 0 NOT NULL,
    "default" integer,
    description clob NOT NULL,
    language_id integer DEFAULT 0 NOT NULL,
    name varchar2(45) DEFAULT '' NOT NULL
);

CREATE TABLE ezcontentobject_state_link (
    contentobject_id integer DEFAULT 0 NOT NULL,
    contentobject_state_id integer DEFAULT 0 NOT NULL
);

CREATE UNIQUE INDEX ezcontentobject_state_identifier ON ezcontentobject_state (group_id, identifier);

CREATE UNIQUE INDEX ezcontentobject_state_group_identifier ON ezcontentobject_state_group (identifier);

ALTER TABLE ezcontentobject_state
    ADD PRIMARY KEY (id);

ALTER TABLE ezcontentobject_state_group
    ADD PRIMARY KEY (id);

ALTER TABLE ezcontentobject_state_group_language
    ADD PRIMARY KEY (language_id, contentobject_state_group_id);

ALTER TABLE ezcontentobject_state_language
    ADD PRIMARY KEY (contentobject_state_id, language_id);

ALTER TABLE ezcontentobject_state_link
    ADD PRIMARY KEY (contentobject_id, contentobject_state_id);

CREATE SEQUENCE s_ezcontentobject_state;
CREATE OR REPLACE TRIGGER ezcontentobject_state_tr
BEFORE INSERT ON ezurlwildcard FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_ezcontentobject_state.nextval INTO :new.id FROM dual;
END;
/

CREATE SEQUENCE s_ezcontentobject_state_group;
CREATE OR REPLACE TRIGGER ezcontentobject_state_group_tr
BEFORE INSERT ON ezcontentobject_state_group FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_ezcontentobject_state_group.nextval INTO :new.id FROM dual;
END;
/
