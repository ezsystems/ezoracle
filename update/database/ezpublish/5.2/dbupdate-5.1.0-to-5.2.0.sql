
-- Start ezp-21465 : Cleanup extra lines in the ezurl_object_link table
BEGIN
  EXECUTE IMMEDIATE 'DROP TABLE ezurl_object_link_temp';
EXCEPTION
  WHEN OTHERS THEN NULL;
END;
/

CREATE GLOBAL TEMPORARY TABLE ezurl_object_link_temp ON COMMIT PRESERVE ROWS AS
SELECT DISTINCT contentobject_attribute_id, contentobject_attr_version, url_id
FROM ezurl_object_link T1 JOIN ezcontentobject_attribute ON T1.contentobject_attribute_id = ezcontentobject_attribute.id
WHERE ezcontentobject_attribute.data_type_string = 'ezurl'
AND T1.url_id < ANY
  (SELECT DISTINCT T2.url_id
  FROM ezurl_object_link T2
  WHERE T1.url_id < T2.url_id
  AND T1.contentobject_attribute_id = T2.contentobject_attribute_id
  AND T1.contentobject_attr_version = T2.contentobject_attr_version);

DELETE FROM ezurl_object_link
WHERE EXISTS (
  SELECT url_id
  FROM ezurl_object_link_temp
  WHERE ezurl_object_link.url_id = ezurl_object_link_temp.url_id
    AND ezurl_object_link.contentobject_attribute_id = ezurl_object_link_temp.contentobject_attribute_id
    AND ezurl_object_link.contentobject_attr_version = ezurl_object_link_temp.contentobject_attr_version
);

DROP TABLE ezurl_object_link_temp;
-- End ezp-21465

-- Start EZP-21469
-- While using the public API, ezcontentobject.language_mask was not updated correctly,
-- the UPDATE statement below fixes that based on the language_mask of the current version.
UPDATE ezcontentobject o
SET language_mask = (
  SELECT bitor(bitand(o.language_mask, 1), (v.language_mask - bitand(v.language_mask, 1)))
  FROM ezcontentobject_version v
  WHERE o.id = v.contentobject_id AND o.current_version = v.version
)
WHERE EXISTS(
  SELECT *
  FROM ezcontentobject_version
  WHERE o.id = ezcontentobject_version.contentobject_id
    AND o.current_version = ezcontentobject_version.version
);
-- End EZP-21469

-- Start EZP-21648:
-- Adding 'priority' and 'is_hidden' columns to the 'eznode_assignment' table
ALTER TABLE eznode_assignment ADD priority integer DEFAULT 0 NOT NULL;
ALTER TABLE eznode_assignment ADD is_hidden integer DEFAULT 0 NOT NULL;
-- End EZP-21648
