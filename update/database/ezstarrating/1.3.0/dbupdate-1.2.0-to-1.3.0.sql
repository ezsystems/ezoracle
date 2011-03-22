BEGIN
   DECLARE
      INDEX_NAME  VARCHAR2(50)  DEFAULT '';
   BEGIN
      select a.index_name into INDEX_NAME from user_ind_columns a, user_ind_columns b where a.index_name=b.index_name and a.table_name='EZSTARRATING_DATA' and a.column_name='CONTENTOBJECT_ID' and a.column_position=1 and b.column_name='CONTENTOBJECT_ATTRIBUTE_ID' and b.column_position=2;
      BEGIN
        EXECUTE IMMEDIATE 'DROP INDEX '|| INDEX_NAME;
      END;
   END;
END;
/

CREATE INDEX contentobject_id_co_attr_id ON ezstarrating_data ( contentobject_id, contentobject_attribute_id );
