UPDATE ezsite_data SET value='3.8.0' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='6' WHERE name='ezpublish-release';

ALTER TABLE ezorder ADD is_archived INT DEFAULT 0 NOT NULL;
CREATE INDEX ezorder_is_archived ON ezorder (is_archived);
ALTER TABLE ezorder_item ADD type VARCHAR(30);
CREATE INDEX ezorder_item_type ON ezorder_item( type ) ;

-- Improved Approval Workflow -- START --
UPDATE ezworkflow_event set data_text3=data_int1;
-- Improved Approval Workflow --  END  --

UPDATE ezpolicy SET function_name='administrate' WHERE module_name='shop' AND function_name='adminstrate';

-- Improved RSS import. -- START --
ALTER TABLE ezrss_import ADD import_description VARCHAR(3100) DEFAULT '';
-- Improved RSS import. -- END --

-- Multicurrency. -- START --
CREATE TABLE ezcurrencydata (
  id                INTEGER NOT NULL,
  code              VARCHAR2(4)  NOT NULL,
  symbol            VARCHAR2(255) DEFAULT '',
  locale            VARCHAR2(255) DEFAULT '',
  status            INTEGER DEFAULT 1 NOT NULL,
  auto_rate_value   decimal(10,5) DEFAULT 0.00000 NOT NULL,
  custom_rate_value decimal(10,5) DEFAULT 0.00000 NOT NULL,
  rate_factor decimal(10,5) DEFAULT 1.00000 NOT NULL,
  PRIMARY KEY ( id )
);
CREATE  INDEX ezcurrencydata_code ON ezcurrencydata (code);
CREATE SEQUENCE s_currencydata;
CREATE OR REPLACE TRIGGER ezcurrencydata_id_tr
BEFORE INSERT ON ezcurrencydata FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_currencydata.nextval INTO :new.id FROM dual;
END;
/

CREATE TABLE ezmultipricedata (
  id INTEGER NOT NULL,
  contentobject_attr_id INTEGER DEFAULT 0 NOT NULL,
  contentobject_attr_version INTEGER DEFAULT 0 NOT NULL,
  currency_code VARCHAR2(4) NOT NULL,
  value decimal(15,2) DEFAULT 0.00 NOT NULL,
  type INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);
CREATE  INDEX ezmultipricedata_coa_id ON ezmultipricedata (contentobject_attr_id);
CREATE  INDEX ezmultipricedata_coa_version ON ezmultipricedata (contentobject_attr_version);
CREATE  INDEX ezmultipricedata_currency_code ON ezmultipricedata (currency_code);
CREATE SEQUENCE s_multipricedata;
CREATE OR REPLACE TRIGGER ezmultipricedata_id_tr
BEFORE INSERT ON ezmultipricedata FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_multipricedata.nextval INTO :new.id FROM dual;
END;
/

ALTER TABLE ezproductcollection ADD currency_code VARCHAR(4) DEFAULT '';
-- Multicurrency. -- END --


-- Improved packages system -- START --
CREATE TABLE ezpackage (
  id INTEGER NOT NULL,
  name VARCHAR2(100) NOT NULL,
  version VARCHAR2(30) DEFAULT '0' NOT NULL,
  install_date INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);
CREATE SEQUENCE s_package;
CREATE OR REPLACE TRIGGER ezpackage_id_tr
BEFORE INSERT ON ezpackage FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_package.nextval INTO :new.id FROM dual;
END;
/
-- Improved packages system -- END --

-- VAT charging rules -- START --
CREATE TABLE ezproductcategory (
  id INTEGER NOT NULL,
  name VARCHAR2(255) NOT NULL,
  PRIMARY KEY ( id )
);
CREATE SEQUENCE s_productcategory;
CREATE OR REPLACE TRIGGER ezproductcategory_id_tr
BEFORE INSERT ON ezproductcategory FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_productcategory.nextval INTO :new.id FROM dual;
END;
/

CREATE TABLE ezvatrule (
  id INTEGER NOT NULL,
  country VARCHAR2(255) DEFAULT '',
  vat_type INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE SEQUENCE s_vatrule;
CREATE OR REPLACE TRIGGER ezvatrule_id_tr
BEFORE INSERT ON ezvatrule FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_vatrule.nextval INTO :new.id FROM dual;
END;
/

CREATE TABLE ezvatrule_product_category (
  vatrule_id INTEGER DEFAULT 0 NOT NULL,
  product_category_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( vatrule_id, product_category_id )
);
-- VAT charging rules -- END --

-- Multilanguage fixes
CREATE TABLE ezcontent_language (
  id INTEGER DEFAULT 0 NOT NULL,
  disabled INTEGER DEFAULT 0 NOT NULL,
  locale VARCHAR2(20) NOT NULL,
  name VARCHAR2(255)  NOT NULL,
  PRIMARY KEY ( id )
);

DROP TABLE ezcontent_translation;

ALTER TABLE ezcontentobject ADD language_mask int       DEFAULT 0 NOT NULL;
ALTER TABLE ezcontentobject ADD initial_language_id int DEFAULT 0 NOT NULL;

ALTER TABLE ezcontentobject_name ADD language_id int DEFAULT 0 NOT NULL;

ALTER TABLE ezcontentobject_attribute ADD language_id int DEFAULT 0 NOT NULL;

ALTER TABLE ezcontentobject_version ADD language_mask int DEFAULT 0 NOT NULL;
ALTER TABLE ezcontentobject_version ADD initial_language_id int DEFAULT 0 NOT NULL;

ALTER TABLE ezcontentclass ADD always_available int DEFAULT 0 NOT NULL;

ALTER TABLE ezcontentobject_link ADD op_code int DEFAULT 0 NOT NULL;

ALTER TABLE eznode_assignment ADD op_code int DEFAULT 0 NOT NULL;

-- updates
-- set correct op_code
-- mark as being moved
UPDATE eznode_assignment SET op_code=4 WHERE from_node_id > 0 AND op_code=0;
-- mark as being created
UPDATE eznode_assignment SET op_code=2 WHERE from_node_id <= 0 AND op_code=0;
-- mark as being set
UPDATE eznode_assignment SET op_code=2 WHERE remote_id != 0 AND op_code=0;

CREATE INDEX ezcontentobject_lmask ON ezcontentobject ( language_mask ) ;

-- Now remember to run ./update/common/scripts/updatemultilingual.php before using the site

-- Information collection improvments
ALTER TABLE ezinfocollection ADD creator_id int DEFAULT 0 NOT NULL;
