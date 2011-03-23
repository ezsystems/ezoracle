DECLARE
    v_exists NUMBER;
    v_index_name VARCHAR2(50);
BEGIN

    SELECT count(*) INTO v_exists FROM user_tab_cols where table_name = 'EZCOLLAB_SIMPLE_MESSAGE' and column_name='DATA_TEXT_1' AND NULLABLE != 'Y';
    IF v_exists > 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezcollab_simple_message MODIFY ( data_text_1 NULL )';
    END IF;
    SELECT count(*) INTO v_exists FROM user_tab_cols where table_name = 'EZCOLLAB_SIMPLE_MESSAGE' and column_name='DATA_TEXT_2' AND NULLABLE != 'Y';
    IF v_exists > 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezcollab_simple_message MODIFY ( data_text_2 NULL )';
    END IF;
    SELECT count(*) INTO v_exists FROM user_tab_cols where table_name = 'EZCOLLAB_SIMPLE_MESSAGE' and column_name='DATA_TEXT_3' AND NULLABLE != 'Y';
    IF v_exists > 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezcollab_simple_message MODIFY ( data_text_3 NULL )';
    END IF;

    SELECT count(*) INTO v_exists FROM user_tables WHERE table_name = 'EZGMAPLOCATION';
    IF v_exists > 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezgmaplocation MODIFY ( latitude FLOAT )';
        EXECUTE IMMEDIATE 'ALTER TABLE ezgmaplocation MODIFY ( longitude FLOAT )';
    END IF;

    SELECT count(*) INTO v_exists FROM user_tab_cols where table_name = 'EZSURVEY' and column_name='TITLE' AND NULLABLE != 'Y';
    IF v_exists > 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezsurvey MODIFY ( title NULL )';
    END IF;
    SELECT count(*) INTO v_exists FROM user_tab_cols where table_name = 'EZSURVEY' and column_name='REDIRECT_CANCEL' AND NULLABLE != 'Y';
    IF v_exists > 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezsurvey MODIFY ( redirect_cancel NULL )';
    END IF;
    SELECT count(*) INTO v_exists FROM user_tab_cols where table_name = 'EZSURVEY' and column_name='REDIRECT_SUBMIT' AND NULLABLE != 'Y';
    IF v_exists > 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezsurvey MODIFY ( redirect_submit NULL )';
    END IF;

    SELECT count(*) INTO v_exists FROM user_tab_cols where table_name = 'EZSURVEYQUESTION' and column_name='TYPE' AND NULLABLE != 'Y';
    IF v_exists > 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezsurveyquestion MODIFY ( type NULL )';
    END IF;

    SELECT count(*) INTO v_exists FROM user_tab_cols where table_name = 'EZSURVEYRESULT' and column_name='USER_SESSION' AND NULLABLE != 'Y';
    IF v_exists > 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezsurveyresult MODIFY ( user_session_id NULL )';
    END IF;

    BEGIN
        SELECT index_name INTO v_index_name FROM user_indexes WHERE table_name='EZM_POOL' and index_name like 'EZM_POOL_BLOCK_ID_TS_PU0%';
        EXECUTE IMMEDIATE 'DROP INDEX ' || v_index_name;
        EXECUTE IMMEDIATE 'CREATE INDEX ezm_pool_block_id_ts_publ_prio ON ezm_pool ( block_id, ts_publication, priority )';
    EXCEPTION
        WHEN NO_DATA_FOUND THEN
            NULL;
    END;

    BEGIN
        SELECT index_name INTO v_index_name FROM user_indexes WHERE table_name='EZFIND_ELEVATE_CONFIGURATION' and index_name like 'EZFIND_ELEVATE_CONFIGUR0%';
        EXECUTE IMMEDIATE 'DROP INDEX ' || v_index_name;
        EXECUTE IMMEDIATE 'CREATE INDEX ezfind_elevate_config_sq ON ezfind_elevate_configuration ( search_query )';
    EXCEPTION
        WHEN NO_DATA_FOUND THEN
            NULL;
    END;

    BEGIN
        SELECT index_name INTO v_index_name FROM user_indexes WHERE table_name='EZSTARRATING_DATA' and index_name like 'EZSTARRATING_DATA_CONTE0%';
        EXECUTE IMMEDIATE 'DROP INDEX ' || v_index_name;
        EXECUTE IMMEDIATE 'CREATE INDEX contentobject_id_co_attr_id ON ezstarrating_data ( contentobject_id, contentobject_attribute_id )';
    EXCEPTION
        WHEN NO_DATA_FOUND THEN
            NULL;
    END;

END;
/
