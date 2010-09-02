UPDATE ezsite_data SET value='3.5.0' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='5' WHERE name='ezpublish-release';

-- 3.4.2 to 3.5.0alpha1

-- We allow users from the "Editors" group
-- access only to "Root Folder" and "Media" trees.
-- If you want to fix this you need to figure out the ids of these roles and modify
-- the following SQLs
--
-- DELETE FROM ezuser_role WHERE id=30 AND role_id=3;
-- INSERT INTO ezuser_role
--        (role_id, contentobject_id, limit_identifier,limit_value)
--        VALUES (3,13,'Subtree','/1/2/');
-- INSERT INTO ezuser_role
--        (role_id, contentobject_id, limit_identifier,limit_value)
--        VALUES (3,13,'Subtree','/1/43/');

-- the support of redirect payment gateways
-- create table for eZPaymentObjects
CREATE TABLE ezpaymentobject (
    id                 INTEGER                NOT NULL PRIMARY KEY,
    workflowprocess_id INTEGER                NOT NULL,
    order_id           INTEGER      DEFAULT 0 NOT NULL,
    payment_string     VARCHAR(255) DEFAULT '' NOT NULL,
    status             INTEGER      DEFAULT 0 NOT NULL
);

CREATE SEQUENCE s_paymentobject;

CREATE OR REPLACE TRIGGER ezpaymentobject_id_tr
BEFORE INSERT ON ezpaymentobject FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_paymentobject.nextval INTO :new.id FROM dual;
END;
/

ALTER TABLE ezbinaryfile   ADD download_count     INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE ezbasket       ADD order_id           INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE ezcontentclass ADD is_container       INTEGER DEFAULT 0 NOT NULL;

-- New table for storing the users last visit

CREATE TABLE ezuservisit (
   user_id                 INTEGER DEFAULT 0 PRIMARY KEY NOT NULL,
   current_visit_timestamp INTEGER DEFAULT 0 NOT NULL,
   last_visit_timestamp    INTEGER DEFAULT 0 NOT NULL
);

-- New columns for the hiding functionality
ALTER TABLE ezcontentobject_tree ADD is_hidden    INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE ezcontentobject_tree ADD is_invisible INTEGER DEFAULT 0 NOT NULL;

-- 3.5.0alpha1 to 3.5.0beta1

-- fix for section based conditional assignment also in 3.4.3
UPDATE  ezuser_role SET limit_identifier='Section' WHERE limit_identifier='section';

-- fixes incorrect name of group in ezcontentclass_classgroup
UPDATE ezcontentclass_classgroup SET group_name='Users' WHERE group_id=2;

-- 3.5.0beta1 to 3.5.0rc1

ALTER TABLE ezrole ADD is_new INTEGER DEFAULT 0 NOT NULL;

-- New name for ezsearch index, the old one crashed with the table name ezsearch_word
DROP INDEX ezsearch_word;
CREATE INDEX ezsearch_word_word_i ON ezsearch_word ( word );

 -- ezpdf_export
 -- Added support for versioning (class-type)

ALTER TABLE ezpdf_export ADD version  INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE ezpdf_export DROP PRIMARY KEY;
ALTER TABLE ezpdf_export ADD PRIMARY KEY ( id, version );

 -- ezrss_import
 -- Added support for versioning (class-type) by reusing status attribute

ALTER TABLE ezrss_import MODIFY ( status INTEGER DEFAULT 0 NOT NULL );
ALTER TABLE ezrss_import DROP PRIMARY KEY;
ALTER TABLE ezrss_import ADD PRIMARY KEY (id, status);

 -- ezrss_export
 -- Added support for versioning (class-type) by reusing status attribute

ALTER TABLE ezrss_export MODIFY ( status INTEGER DEFAULT 0 NOT NULL );
ALTER TABLE ezrss_export DROP PRIMARY KEY;
ALTER TABLE ezrss_export ADD PRIMARY KEY (id, status);

 -- ezrss_export_item
 -- Added support for versioning (class-type) by introducing status attribute

ALTER TABLE ezrss_export_item ADD status INTEGER DEFAULT 0 NOT NULL;
UPDATE      ezrss_export_item SET status=1;
ALTER TABLE ezrss_export_item DROP PRIMARY KEY;
ALTER TABLE ezrss_export_item ADD  PRIMARY KEY (id, status);
--CREATE INDEX ezrss_export_rsseid ON ezrss_export_item (rssexport_id);
UPDATE ezrss_export_item SET status=1;

 -- ezproductcollection_item
 -- Added attribute name for storing a product name

ALTER TABLE ezproductcollection_item ADD name VARCHAR(255) DEFAULT '' NOT NULL;
UPDATE ezproductcollection_item SET name='Unknown product';

-- 3.5.0rc1 to 3.5.0rc2

-- 3.5.0rc2 to 3.5.0

