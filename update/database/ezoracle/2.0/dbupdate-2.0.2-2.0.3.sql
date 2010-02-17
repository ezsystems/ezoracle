-- fixes for bad triggers/sequences 
-- need to be run only if the site was upgraded from 4.0 to 4.1 using extension 2.0.0 to 2.0.2

CREATE SEQUENCE s_cobj_state;
CREATE SEQUENCE s_cobj_state_group;
CREATE SEQUENCE s_urlalias_ml_incr;

CREATE OR REPLACE TRIGGER ezcobj_state_id_tr
BEFORE INSERT ON ezurlwildcard FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_cobj_state.nextval INTO :new.id FROM dual;
END;
/

CREATE OR REPLACE TRIGGER ezcobj_state_group_id_tr
BEFORE INSERT ON ezcobj_state_group FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_cobj_state_group.nextval INTO :new.id FROM dual;
END;
/

CREATE OR REPLACE TRIGGER ezurlalias_ml_incr_id_tr
BEFORE INSERT ON ezurlalias_ml_incr FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_urlalias_ml_incr.nextval INTO :new.id FROM dual;
END;
/

DROP TRIGGER ezcobj_state_tr;
DROP TRIGGER ezcobj_state_group_tr;
DROP TRIGGER ezurlalias_ml_incr_tr;

DROP SEQUENCE s_ezcobj_state;
DROP SEQUENCE s_ezcobj_state_group;
DROP SEQUENCE s_ezurlalias_ml_incr;

-- this one too is only needed if the user previously upgraded the eZP installation
-- from 4.0 to 4.1 using an ezoracle version from 2.0.0 to 2.0.2
ALTER TABLE ezcobj_state_group_language MODIFY ( description NULL );
ALTER TABLE ezcobj_state_language MODIFY ( description NULL );
