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
