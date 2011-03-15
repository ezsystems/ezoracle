BEGIN
   DECLARE
      INDEX_NAME  VARCHAR2(50)  DEFAULT '';
   BEGIN
      select a.index_name into INDEX_NAME from user_ind_columns a, user_ind_columns b, user_ind_columns c where a.index_name=b.index_name and b.index_name=c.index_name and a.table_name='EZM_POOL' and a.column_name='BLOCK_ID' and a.column_position=1 and b.column_name='TS_PUBLICATION' and b.column_position=2 and c.column_name='PRIORITY' and c.column_position=3;
      BEGIN
        EXECUTE IMMEDIATE 'DROP INDEX '|| INDEX_NAME;
      END;
   END;
END;
/

CREATE INDEX ezm_pool_block_id_ts_publ_prio ON ezm_pool ( block_id, ts_publication, priority );