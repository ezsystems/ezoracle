-- Please note: preferred method for upgrading clustered installations is described on
-- http://pubsvn.ez.no/nextgen/trunk/doc/features/3.10/cluster_enhancement.txt
--
-- this script is provided as a convenience for sites where clustered data would
-- take too much time and/or space to be dumped to disk and then reloaded

DROP TRIGGER ezdbfile_id_tr;

DROP SEQUENCE s_dbfile;

ALTER TABLE ezdbfile ADD ( expired CHAR(1) DEFAULT '0' NOT NULL );

ALTER TABLE ezdbfile DROP COLUMN id;

ALTER TABLE ezdbfile DROP UNIQUE ( name );

ALTER TABLE ezdbfile DROP UNIQUE ( name_hash );

ALTER TABLE ezdbfile ADD CONSTRAINT pk_ezdbfile PRIMARY KEY ( name_hash );

CREATE INDEX ezdbfile_mtime ON ezdbfile ( mtime );

ALTER TABLE  EZDBFILE MODIFY ( NAME VARCHAR2(4000) );

CREATE INDEX ezdbfile_name ON ezdbfile ( name );

--CREATE UNIQUE INDEX ezdbfile_expired_name ON ezdbfile ( expired, name );

CREATE OR REPLACE PROCEDURE EZEXCLUSIVELOCK ( P_NAME IN VARCHAR2, P_NAME_HASH  IN VARCHAR2 ) AS
  -- Get exclusive lock on a table row (or die waiting!)
  --
  -- @todo use oracle MERGE statement instead of this poor man's version
  V_HASH EZDBFILE.NAME_HASH%TYPE;
BEGIN
  SELECT NAME_HASH
  INTO V_HASH
  FROM EZDBFILE
  WHERE NAME_HASH = P_NAME_HASH
  FOR UPDATE;
EXCEPTION
  WHEN NO_DATA_FOUND THEN
    BEGIN
      INSERT INTO EZDBFILE ( NAME, NAME_HASH, FILESIZE, MTIME ) VALUES ( P_NAME, P_NAME_HASH, -1, -1);
    EXCEPTION
      WHEN DUP_VAL_ON_INDEX THEN
        NULL;
    END;
    SELECT NAME_HASH
    INTO V_HASH
    FROM EZDBFILE
    WHERE NAME_HASH = P_NAME_HASH
    FOR UPDATE;
END;
/
