UPDATE ezsite_data SET value='4.0.2' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

ALTER TABLE ezorder_item MODIFY (vat_value FLOAT default 0);

CREATE TABLE ezurlalias_ml_incr (
  id integer NOT NULL,
  PRIMARY KEY  (id)
);

CREATE SEQUENCE s_urlalias_ml_incr;
CREATE OR REPLACE TRIGGER ezurlalias_ml_incr_id_tr
BEFORE INSERT ON ezurlalias_ml_incr FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_urlalias_ml_incr.nextval INTO :new.id FROM dual;
END;
/
