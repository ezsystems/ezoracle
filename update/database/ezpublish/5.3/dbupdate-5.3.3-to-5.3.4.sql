UPDATE ezsite_data SET value='5.3.4' WHERE name='ezpublish-version';

-- See https://jira.ez.no/browse/EZP-23595 - cleanup extra lines in the ezuser_setting table
DELETE FROM ezuser_setting where user_id not in (SELECT contentobject_id FROM ezuser);
