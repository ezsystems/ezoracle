UPDATE ezsite_data SET value='4.3.0' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

ALTER TABLE ezrss_export_item ADD enclosure VARCHAR2( 255 ) NULL;
ALTER TABLE ezcontentclass ADD serialized_description_list VARCHAR2( 4000 ) NULL;
ALTER TABLE ezcontentclass_attribute ADD serialized_data_text CLOB NULL;
ALTER TABLE ezcontentclass_attribute ADD serialized_description_list CLOB NULL;
ALTER TABLE ezcontentclass_attribute ADD category VARCHAR2( 25 ) NULL;


CREATE TABLE ezscheduled_script (
  id INTEGER NOT NULL,
  process_id INTEGER DEFAULT 0 NOT NULL,
  name VARCHAR2(50) NOT NULL, -- default ''
  command VARCHAR2(255) NOT NULL, -- default ''
  last_report_timestamp INTEGER DEFAULT 0 NOT NULL,
  progress INTEGER DEFAULT 0,
  user_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY (id)
);
CREATE INDEX ezscheduled_script_timestamp ON ezscheduled_script (last_report_timestamp);


CREATE SEQUENCE s_scheduled_script;
CREATE OR REPLACE TRIGGER ezscheduled_script_id_tr
BEFORE INSERT ON ezscheduled_script FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_scheduled_script.nextval INTO :new.id FROM dual;
END;
/
