CREATE TABLE ezdfsfile_cache (
  name VARCHAR2(4000) NOT NULL,
  --name_trunk VARCHAR2(4000) NOT NULL,
  name_hash VARCHAR2(34) PRIMARY KEY,
  datatype VARCHAR2(255) DEFAULT 'application/octet-stream',
  scope VARCHAR2(25)     DEFAULT '',
  filesize INT           DEFAULT 0 NOT NULL,
  mtime INT              DEFAULT 0 NOT NULL,
  expired CHAR(1)        DEFAULT '0' NOT NULL,
  status    char(1)      DEFAULT '0' NOT NULL
);
CREATE INDEX ezdfsfile_cache_name ON ezdfsfile_cache (name);
--CREATE INDEX ezdfsfile_cache_name_trunk ON ezdfsfile_cache (name_trunk);
CREATE INDEX ezdfsfile_cache_mtime ON ezdfsfile_cache (mtime);
--CREATE INDEX ezdfsfile_cache_expired_name ON ezdfsfile_cache (expired, name);
