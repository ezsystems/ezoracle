UPDATE ezsite_data SET value='5.3.3' WHERE name='ezpublish-version';

UPDATE ezcontentobject_attribute
    SET data_int = NULL
WHERE
    data_int = 0
    AND data_type_string IN ( 'ezdate', 'ezdatetime' );

UPDATE ezinfocollection_attribute
    SET ezinfocollection_attribute.data_int = NULL
WHERE
    ezinfocollection_attribute.contentclass_attribute_id IN (
        SELECT id FROM ezcontentclass_attribute WHERE data_type_string IN ( 'ezdate', 'ezdatetime' )
    )
    AND ezinfocollection_attribute.data_int = 0;
