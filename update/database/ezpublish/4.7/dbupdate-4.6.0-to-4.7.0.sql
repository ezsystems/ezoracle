UPDATE ezsite_data SET value='4.7.0' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';


ALTER TABLE ezpending_actions ADD id integer;
UPDATE ezpending_actions SET id = ROWNUM;
ALTER TABLE ezpending_actions MODIFY id integer NOT NULL;

DECLARE
    pending_start INTEGER;
BEGIN
    SELECT COUNT(*)+1 INTO pending_start FROM ezpending_actions;
    EXECUTE IMMEDIATE 'CREATE SEQUENCE s_pending_actions_incr START WITH ' || pending_start;
END;
/

CREATE OR REPLACE TRIGGER ezpending_actions_id_tr
BEFORE INSERT ON ezpending_actions FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_pending_actions_incr.nextval INTO :new.id FROM dual;
END;
/


ALTER TABLE ezpending_actions ADD PRIMARY KEY ( id );


-- Cleanup for #18886
-- when a user is manually enabled through the admin interface,
-- the corresponding ezuser_accountkey record is not removed
DELETE FROM ezuser_accountkey WHERE user_id IN ( SELECT user_id FROM ezuser_setting WHERE is_enabled = 1 );


ALTER TABLE ezcontentobject_attribute ADD ( data_float_tmp BINARY_DOUBLE DEFAULT NULL );
UPDATE ezcontentobject_attribute SET data_float_tmp = data_float;
ALTER TABLE ezcontentobject_attribute DROP ( data_float );
ALTER TABLE ezcontentobject_attribute RENAME COLUMN data_float_tmp TO data_float;

ALTER TABLE ezcontentclass_attribute ADD ( data_float1_tmp BINARY_DOUBLE DEFAULT NULL );
ALTER TABLE ezcontentclass_attribute ADD ( data_float2_tmp BINARY_DOUBLE DEFAULT NULL );
ALTER TABLE ezcontentclass_attribute ADD ( data_float3_tmp BINARY_DOUBLE DEFAULT NULL );
ALTER TABLE ezcontentclass_attribute ADD ( data_float4_tmp BINARY_DOUBLE DEFAULT NULL );
UPDATE ezcontentclass_attribute SET data_float1_tmp = data_float1, data_float2_tmp = data_float2, data_float3_tmp = data_float3, data_float4_tmp = data_float4;
ALTER TABLE ezcontentclass_attribute DROP ( data_float1, data_float2, data_float3, data_float4 );
ALTER TABLE ezcontentclass_attribute RENAME COLUMN data_float1_tmp TO data_float1;
ALTER TABLE ezcontentclass_attribute RENAME COLUMN data_float2_tmp TO data_float2;
ALTER TABLE ezcontentclass_attribute RENAME COLUMN data_float3_tmp TO data_float3;
ALTER TABLE ezcontentclass_attribute RENAME COLUMN data_float4_tmp TO data_float4;
