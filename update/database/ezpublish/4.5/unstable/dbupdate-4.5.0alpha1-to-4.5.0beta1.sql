UPDATE ezsite_data SET value='4.5.0beta1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

CREATE TABLE ezpublishingqueueprocesses (
  created INTEGER,
  ezcontentobject_version_id INTEGER DEFAULT 0 NOT NULL,
  finished INTEGER,
  pid INTEGER,
  started INTEGER,
  status INTEGER,
  PRIMARY KEY ( ezcontentobject_version_id )
);

CREATE TABLE ezprest_token (
  client_id VARCHAR2(200) NOT NULL,
  expirytime INTEGER DEFAULT 0 NOT NULL,
  id VARCHAR2(200) NOT NULL,
  refresh_token VARCHAR2(200) NOT NULL,
  scope VARCHAR2(200),
  user_id VARCHAR2(200) NOT NULL,
  PRIMARY KEY ( id )
);

CREATE  INDEX token_client_id ON ezprest_token (client_id);

CREATE TABLE ezprest_authcode (
  client_id VARCHAR2(200) NOT NULL,
  expirytime INTEGER DEFAULT 0 NOT NULL,
  id VARCHAR2(200) NOT NULL,
  scope VARCHAR2(200),
  user_id VARCHAR2(200) NOT NULL,
  PRIMARY KEY (id)
);

CREATE  INDEX authcode_client_id ON ezprest_authcode (client_id);

CREATE TABLE ezprest_clients (
  client_id VARCHAR2(200),
  client_secret VARCHAR2(200),
  created INTEGER DEFAULT 0 NOT NULL,
  description CLOB,
  endpoint_uri VARCHAR2(200),
  id INTEGER NOT NULL,
  name VARCHAR2(100),
  owner_id INTEGER DEFAULT 0 NOT NULL,
  updated INTEGER DEFAULT 0 NOT NULL,
  version INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE SEQUENCE s_prest_clients;

CREATE OR REPLACE TRIGGER ezprest_clients_id_tr
BEFORE INSERT ON ezprest_clients FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_prest_clients.nextval INTO :new.id FROM dual;
END;
/

CREATE UNIQUE INDEX client_id_unique ON ezprest_clients (client_id, version);
CREATE  INDEX client_id ON ezprest_clients (client_id);

CREATE TABLE ezprest_authorized_clients (
  created INTEGER,
  id INTEGER NOT NULL,
  rest_client_id INTEGER,
  user_id INTEGER,
  PRIMARY KEY ( id )
);

CREATE SEQUENCE s_prest_authorized_clients;

CREATE OR REPLACE TRIGGER ezprest_authorized_cli00001_tr
BEFORE INSERT ON ezprest_authorized_clients FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_prest_authorized_clients.nextval INTO :new.id FROM dual;
END;
/

CREATE  INDEX client_user ON ezprest_authorized_clients (rest_client_id, user_id);
