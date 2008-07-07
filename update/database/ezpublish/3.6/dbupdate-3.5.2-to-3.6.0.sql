UPDATE ezsite_data SET value='3.6.0' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='3' WHERE name='ezpublish-release';

ALTER TABLE ezrss_export_item ADD subnodes INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE ezrss_export ADD number_of_objects INTEGER DEFAULT 0 NOT NULL;

-- Old behaviour of RSS was that it fed 5 items
UPDATE ezrss_export SET number_of_objects='5';

ALTER TABLE ezrss_export ADD main_node_only INTEGER DEFAULT 1 NOT NULL;
-- Old behaviour of RSS was that all nodes have been shown,
-- i.e. including those besides the main node
UPDATE ezrss_export SET main_node_only='1';

ALTER TABLE ezcontentobject_link ADD contentclassattribute_id INT DEFAULT 0 NOT NULL;
CREATE INDEX ezco_link_to_co_id ON ezcontentobject_link ( to_contentobject_id );
CREATE INDEX ezco_link_from     ON ezcontentobject_link ( from_contentobject_id,
                                                          from_contentobject_version,
                                                          contentclassattribute_id );



-- Add missing index for orders
CREATE INDEX ezorder_is_tmp ON ezorder (is_temporary);

ALTER TABLE ezorder ADD status_id          INTEGER DEFAULT 0;
ALTER TABLE ezorder ADD status_modified    INTEGER DEFAULT 0;
ALTER TABLE ezorder ADD status_modifier_id INTEGER DEFAULT 0;

CREATE SEQUENCE s_order_status;

CREATE TABLE ezorder_status (
    id        INTEGER                 NOT NULL,
    status_id INTEGER      DEFAULT 0  NOT NULL,
    name      VARCHAR(255) DEFAULT '' NOT NULL,
    is_active INTEGER      DEFAULT 1  NOT NULL,
    PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER ezorder_status_id_tr
BEFORE INSERT ON ezorder_status FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_order_status.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX ezorder_status_sid    ON ezorder_status (status_id);
CREATE INDEX ezorder_status_name   ON ezorder_status (name);
CREATE INDEX ezorder_status_active ON ezorder_status (is_active);

INSERT INTO ezorder_status (status_id, name, is_active)
VALUES( 1, 'Pending', 1 );
INSERT INTO ezorder_status (status_id, name, is_active)
VALUES( 2, 'Processing', 1 );
INSERT INTO ezorder_status (status_id, name, is_active)
VALUES( 3, 'Delivered', 1 );

CREATE SEQUENCE s_order_status_history;

CREATE TABLE ezorder_status_history (
    id          INTEGER NOT NULL,
    order_id    INTEGER DEFAULT 0 NOT NULL,
    status_id   INTEGER DEFAULT 0 NOT NULL,
    modifier_id INTEGER DEFAULT 0 NOT NULL,
    modified    INTEGER DEFAULT 0 NOT NULL,
    PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER ezorder_status_history_id_tr
BEFORE INSERT ON ezorder_status_history FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_order_status_history.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX ezorder_status_history_oid ON ezorder_status_history (order_id);
CREATE INDEX ezorder_status_history_sid ON ezorder_status_history (status_id);
CREATE INDEX ezorder_status_history_mod ON ezorder_status_history (modified);


-- Make sure each order has a history element with Pending status
INSERT INTO ezorder_status_history (order_id, status_id, modifier_id, modified)
SELECT order_nr AS order_id, 1 AS status_id, user_id AS modifier_id, created AS modified FROM ezorder WHERE status_id = 0;

-- Update status of all orders to Pending
UPDATE ezorder SET status_id = 1, status_modifier_id = user_id, status_modified = created WHERE status_id = 0;
