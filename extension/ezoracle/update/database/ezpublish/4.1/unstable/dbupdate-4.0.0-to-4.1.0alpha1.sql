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

ALTER TABLE ezurlalias_ml ADD alias_redirects number(11) NOT NULL default 1;
