SET SERVEROUTPUT ON

DECLARE
    CURSOR table_cur IS
        SELECT table_name, column_name, data_length
        FROM user_tab_cols
        WHERE data_type = 'VARCHAR2' AND ( char_used IS NULL OR char_used = 'B' );
BEGIN
    FOR t_rec IN table_cur LOOP
        IF ( t_rec.data_length > 1000 ) THEN
            DBMS_OUTPUT.PUT_LINE( 'Column ' || t_rec.table_name || '.' || t_rec.column_name || ' is too wide to fit utf8 VARCHAR: ' || t_rec.data_length || ' shortened to 1000' );
            t_rec.data_length := 1000;
        END IF;
        EXECUTE IMMEDIATE 'ALTER TABLE ' || t_rec.table_name || ' MODIFY ( ' || t_rec.column_name || ' VARCHAR2( ' || t_rec.data_length || ' CHAR ) )';
    END LOOP;
END;
/