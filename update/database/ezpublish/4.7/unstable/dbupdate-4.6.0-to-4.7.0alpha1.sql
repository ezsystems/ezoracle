UPDATE ezsite_data SET value='4.7.0alpha1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';


ALTER TABLE ezpending_actions ADD id integer NOT NULL;

CREATE SEQUENCE s_pending_actions_incr;
CREATE OR REPLACE TRIGGER ezpending_actions_id_tr
BEFORE INSERT ON ezpending_actions FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_pending_actions_incr.nextval INTO :new.id FROM dual;
END;


ALTER TABLE ezpending_actions ADD CONSTRAINT pk_ezpending_actions PRIMARY KEY (id);
