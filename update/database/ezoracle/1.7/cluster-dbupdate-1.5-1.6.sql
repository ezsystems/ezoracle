-- Please note: preferred method for upgrading clustered installations is described on
-- http://pubsvn.ez.no/nextgen/trunk/doc/features/3.10/cluster_enhancement.txt
--
-- this script is provided as a convenience for sites where clustered data would
-- take too much time and/or space to be dumped to disk and then reloaded

DROP TRIGGER ezdbfile_id_tr;

DROP SEQUENCE s_dbfile;

ALTER TABLE ezdbfile ADD ( expired CHAR(1) DEFAULT '0' NOT NULL );

ALTER TABLE ezdbfile DROP COLUMN id;

ALTER TABLE ezdbfile DROP UNIQUE ( name_hash );

ALTER TABLE ezdbfile ADD CONSTRAINT pk_ezdbfile PRIMARY KEY ( name_hash );

CREATE INDEX ezdbfile_mtime ON ezdbfile ( mtime );

CREATE UNIQUE INDEX ezdbfile_expired_name ON ezdbfile ( expired, name );