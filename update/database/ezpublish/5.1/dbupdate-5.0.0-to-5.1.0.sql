UPDATE ezsite_data SET value='5.1.0alpha1' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

CREATE INDEX ezcontentclass_identifier ON ezcontentclass (identifier, version);
CREATE INDEX ezcontentobject_tree_remote_id ON ezcontentobject_tree (remote_id);
CREATE INDEX ezcontobj_version_obj_status ON ezcontentobject_version (contentobject_id, status);
CREATE INDEX ezpolicy_role_id ON ezpolicy (role_id);
CREATE INDEX ezpolicy_limit_value_limit_id ON ezpolicy_limitation_value (limitation_id);

-- See https://jira.ez.no/browse/EZP-20239
DELETE FROM ezcontentobject_link WHERE op_code <> 0;
DELETE FROM ezcontentobject_link WHERE relation_type = 0;
ALTER TABLE ezcontentobject_link DROP COLUMN op_code;
