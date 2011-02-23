UPDATE ezsite_data SET value='4.5.0alpha1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

CREATE TABLE ezpublishingqueueprocesses (
    created INTEGER,
    ezcontentobject_version_id INTEGER DEFAULT 0 NOT NULL,
    finished INTEGER,
    pid INTEGER,
    started INTEGER,
    status INTEGER,
	PRIMARY KEY( ezcontentobject_version_id )
);
