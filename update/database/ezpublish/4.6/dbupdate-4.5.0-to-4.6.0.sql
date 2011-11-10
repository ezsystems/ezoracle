
UPDATE ezsite_data SET value='4.6.0' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

CREATE TABLE ezorder_nr_incr (
  id integer NOT NULL,
  PRIMARY KEY (id)
);

CREATE SEQUENCE s_order_nr_incr;
CREATE OR REPLACE TRIGGER ezorder_nr_incr_id_tr
BEFORE INSERT ON ezorder_nr_incr FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_order_nr_incr.nextval INTO :new.id FROM dual;
END;
/


-- #18514 store affected class ids in data_text5 instead of data_text3 for multiplexer workflow event type
UPDATE ezworkflow_event SET data_text5 = data_text3, data_text3 = '' WHERE workflow_type_string = 'event_ezmultiplexer';

