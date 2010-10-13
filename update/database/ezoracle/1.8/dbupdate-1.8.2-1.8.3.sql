CREATE OR REPLACE FUNCTION md5_digest (vin_string IN VARCHAR2)
RETURN VARCHAR2 IS
--
-- Return an MD5 hash of the input string.
--
BEGIN
    IF vin_string IS NULL THEN
        RETURN 'd41d8cd98f00b204e9800998ecf8427e';
    ELSE
        RETURN lower(dbms_obfuscation_toolkit.md5(input =>utl_raw.cast_to_raw(vin_string)));
    END IF;
END md5_digest;
/

ALTER TABLE ezcollab_simple_message MODIFY ( data_text1 NULL );
ALTER TABLE ezcollab_simple_message MODIFY ( data_text2 NULL );
ALTER TABLE ezcollab_simple_message MODIFY ( data_text3 NULL );

-- Follows: fixes for bad triggers/sequences 
-- need to be run only if the site was upgraded from 3.10 to 4.0 using extension 1.8.0 to 1.8.3

CREATE OR REPLACE TRIGGER ezurlwildcard_id_tr
BEFORE INSERT ON ezurlwildcard FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_urlwildcard.nextval INTO :new.id FROM dual;
END;
/

DROP TRIGGER ezurlwildcard_tr;