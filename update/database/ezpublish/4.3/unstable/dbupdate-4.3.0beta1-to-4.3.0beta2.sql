UPDATE ezsite_data SET value='4.3.0beta2' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

CREATE TABLE ezscheduled_script (
  id INTEGER NOT NULL,
  process_id INTEGER DEFAULT 0 NOT NULL,
  name VARCHAR2(50) NOT NULL, -- default ''
  command VARCHAR2(255) NOT NULL, -- default ''
  last_report_timestamp INTEGER DEFAULT 0 NOT NULL,
  progress INTEGER DEFAULT 0,
  user_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY (id),
  INDEX ezscheduled_script_timestamp (last_report_timestamp)
);

CREATE SEQUENCE s_scheduled_script
CREATE OR REPLACE TRIGGER ezscheduled_script_id_tr
BEFORE INSERT ON ezscheduled_script FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_scheduled_script.nextval INTO :new.id FROM dual;
END;
/