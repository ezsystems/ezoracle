-- Start EZP-21648:
-- Adding 'priority' and 'is_hidden' columns to the 'eznode_assignment' table
ALTER TABLE eznode_assignment ADD priority integer DEFAULT 0 NOT NULL;
ALTER TABLE eznode_assignment ADD is_hidden integer DEFAULT 0 NOT NULL;
-- End EZP-21648
