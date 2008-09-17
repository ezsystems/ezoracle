UPDATE ezsite_data SET value='3.9.0' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='5' WHERE name='ezpublish-release';


-- START: from 3.8.1


CREATE INDEX ezkeyword_keyword_id ON ezkeyword ( keyword, id );
CREATE INDEX ezkeyword_attr_link_kid_oaid ON ezkeyword_attribute_link ( keyword_id, objectattribute_id );

CREATE INDEX ezurlalias_is_wildcard ON ezurlalias( is_wildcard );

CREATE INDEX eznode_assignment_coid_cov ON eznode_assignment( contentobject_id, contentobject_version );
CREATE INDEX eznode_assignment_is_main ON eznode_assignment( is_main );
CREATE INDEX eznode_assignment_parent_node ON eznode_assignment( parent_node );

-- END: from 3.8.1

ALTER TABLE ezuservisit ADD failed_login_attempts int DEFAULT 0 NOT NULL ;
ALTER TABLE ezcontentobject_link ADD relation_type int DEFAULT 1 NOT NULL;
UPDATE ezcontentobject_link SET relation_type=8 WHERE contentclassattribute_id<>0;


-- START: 'default sorting' attribute for ezcontentclass

ALTER TABLE ezcontentclass ADD sort_field int DEFAULT 1 NOT NULL;

ALTER TABLE ezcontentclass ADD sort_order int DEFAULT 1 NOT NULL;

-- END: 'default sorting' attribute for ezcontentclass

-- START: new table for trash
CREATE TABLE ezcontentobject_trash (
    contentobject_id integer,
    contentobject_version integer,
    depth integer DEFAULT 0 NOT NULL,
    is_hidden integer DEFAULT 0 NOT NULL,
    is_invisible integer DEFAULT 0 NOT NULL,
    main_node_id integer,
    modified_subnode integer DEFAULT 0,
    node_id integer DEFAULT 0 NOT NULL,
    parent_node_id integer DEFAULT 0 NOT NULL,
    path_identification_string VARCHAR2(3100),
    path_string VARCHAR2(255) DEFAULT '' NOT NULL,
    priority integer DEFAULT 0 NOT NULL,
    remote_id VARCHAR2(100) DEFAULT '' NOT NULL,
    sort_field integer DEFAULT 1,
    sort_order integer DEFAULT 1,
    PRIMARY KEY ( node_id )
);


CREATE INDEX ezcobj_trash_co_id ON ezcontentobject_trash  (contentobject_id);
CREATE INDEX ezcobj_trash_depth ON ezcontentobject_trash  (depth);
CREATE INDEX ezcobj_trash_p_node_id ON ezcontentobject_trash  (parent_node_id);
CREATE INDEX ezcobj_trash_path ON ezcontentobject_trash  (path_string);
CREATE INDEX ezcobj_trash_path_ident ON ezcontentobject_trash  (path_identification_string);
CREATE INDEX ezcobj_trash_modified_subnode ON ezcontentobject_trash  (modified_subnode);
-- END: new table for trash

-- START: ezcontentclass/ezcontentclass_attribute translations
ALTER TABLE ezcontentclass RENAME COLUMN name TO serialized_name_list;
ALTER TABLE ezcontentclass ADD language_mask integer DEFAULT 0 NOT NULL;
ALTER TABLE ezcontentclass ADD initial_language_id integer DEFAULT 0 NOT NULL;
ALTER TABLE ezcontentclass_attribute RENAME COLUMN name TO serialized_name_list;

CREATE TABLE ezcontentclass_name
(
    contentclass_id integer default 0 NOT NULL,
    contentclass_version integer default 0 NOT NULL,
    language_locale varchar2(20) default '' NOT NULL,
    language_id integer default 0 NOT NULL,
    name varchar2(255) default '' NOT NULL,
    PRIMARY KEY (contentclass_id, contentclass_version, language_id)
);

-- END: ezcontentclass/ezcontentclass_attribute translations

-- START: eztipafriend_counter, new column and primary key (new fetch function for tipafriend_top_list)
ALTER TABLE eztipafriend_counter ADD requested integer DEFAULT 0 NOT NULL;
ALTER TABLE eztipafriend_counter DROP PRIMARY KEY;
ALTER TABLE eztipafriend_counter ADD PRIMARY KEY (node_id, requested);
-- END: eztipafriend_counter, new column and primary key (new fetch function for tipafriend_top_list)

-- START: improvements in shop(better vat handling of order items, like shipping)
ALTER TABLE ezorder_item ADD is_vat_inc integer DEFAULT 0 NOT NULL;
-- END: improvements in shop(better vat handling of order items, like shipping)



-- START: from 3.8.5


-- ezcontentobject
CREATE INDEX ezcontentobject_pub ON ezcontentobject( published );
CREATE INDEX ezcontentobject_status ON ezcontentobject( status );
CREATE INDEX ezcontentobject_classid ON ezcontentobject( contentclass_id );
CREATE INDEX ezcontentobject_currentversion ON ezcontentobject( current_version );

-- ezcontentobject_name
CREATE INDEX ezcontentobject_name_lang_id ON ezcontentobject_name( language_id );
CREATE INDEX ezcontentobject_name_name ON ezcontentobject_name( name );
CREATE INDEX ezcontentobject_name_co_id ON ezcontentobject_name( contentobject_id );
CREATE INDEX ezcontentobject_name_cov_id ON ezcontentobject_name( content_version );

-- ezcontentobject_version
CREATE INDEX ezcobj_version_creator_id ON ezcontentobject_version( creator_id );
CREATE INDEX ezcobj_version_status ON ezcontentobject_version( status );

-- ezpolicy_limitation_value
CREATE INDEX ezpolicy_limitation_value_val ON ezpolicy_limitation_value( value );

-- ezinfocollection_attribute
CREATE INDEX ezinfocollection_attr_co_id ON ezinfocollection_attribute( contentobject_id );

-- ezurlalias
CREATE INDEX ezurlalias_forward_to_id ON ezurlalias( forward_to_id );

-- ezkeyword
CREATE INDEX ezkeyword_keyword ON ezkeyword( keyword );

-- ezurl
CREATE INDEX ezurl_url ON ezurl( url );

-- ezcontentobject_attribute
CREATE INDEX ezcontentobject_attr_id ON ezcontentobject_attribute( id );

-- ezcontentoclass_attribute
CREATE INDEX ezcontentclass_attr_ccid ON ezcontentclass_attribute( contentclass_id );

-- eznode_assignment
CREATE INDEX eznode_assignment_co_id ON eznode_assignment( contentobject_id );
CREATE INDEX eznode_assignment_co_version ON eznode_assignment( contentobject_version );

-- ezkeyword_attribute_link
CREATE INDEX ezkeyword_attr_link_keyword_id ON ezkeyword_attribute_link( keyword_id );
-- END: from 3.8.5


CREATE INDEX  ezsrch_return_cnt_ph_id_count  ON   ezsearch_return_count ( phrase_id, count );
-- alter table ezsearch_return_count add key ( phrase_id, count );
CREATE INDEX ezsrch_search_phrase_phr ON ezsearch_search_phrase ( phrase );
-- alter table ezsearch_search_phrase add key ( phrase );


CREATE TABLE ezsearch_search_phrase_new (
  id int NOT NULL,
  phrase varchar2(250) default NULL,
  phrase_count int default 0,
  result_count int default 0,
  PRIMARY KEY( id )
);
CREATE UNIQUE INDEX ezsearch_search_phrase_phrase ON ezsearch_search_phrase_new ( phrase );
CREATE INDEX ezsearch_search_phrase_count ON ezsearch_search_phrase_new ( phrase_count );

CREATE SEQUENCE s_search_search_phrase_new;
CREATE OR REPLACE TRIGGER ezsearch_search_phrase_new_tr
BEFORE INSERT ON ezsearch_search_phrase_new FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_search_search_phrase_new.nextval INTO :new.id FROM dual;
END;
/




INSERT INTO ezsearch_search_phrase_new ( phrase, phrase_count, result_count )
SELECT   lower( phrase ), count(*), sum( ezsearch_return_count.count )
FROM     ezsearch_search_phrase,
         ezsearch_return_count
WHERE    ezsearch_search_phrase.id = ezsearch_return_count.phrase_id
GROUP BY lower( ezsearch_search_phrase.phrase );

-- ezsearch_return_count is of no (additional) use in a normal eZ Publish installation
-- but perhaps someone built something for himself, then it is not BC
-- to not break BC apply the CREATE and INSERT statements

CREATE TABLE ezsearch_return_count_new (
  id int NOT NULL,
  phrase_id int default 0 NOT NULL,
  time int default 0 NOT NULL,
  count int default 0 NOT NULL,
  PRIMARY KEY(id)
);
CREATE INDEX  ezsrch_ret_cnt_new_ph_id_cnt  ON  ezsearch_return_count_new ( phrase_id, count );

CREATE SEQUENCE s_search_return_count_new;
CREATE OR REPLACE TRIGGER ezsearch_return_count_new_tr
BEFORE INSERT ON ezsearch_return_count_new FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_search_return_count_new.nextval INTO :new.id FROM dual;
END;
/



INSERT INTO ezsearch_return_count_new ( phrase_id, time, count )
SELECT    ezsearch_search_phrase_new.id, time, count
FROM      ezsearch_search_phrase,
          ezsearch_search_phrase_new,
          ezsearch_return_count
WHERE     ezsearch_search_phrase_new.phrase = LOWER( ezsearch_search_phrase.phrase ) AND
          ezsearch_search_phrase.id = ezsearch_return_count.phrase_id;

-- final tasks with and without BC
DROP TABLE ezsearch_search_phrase;
--ALTER TABLE ezsearch_search_phrase RENAME TO ezsearch_search_phrase_old;
ALTER TABLE ezsearch_search_phrase_new RENAME TO ezsearch_search_phrase;

DROP SEQUENCE s_search_search_phrase;
RENAME s_search_search_phrase_new TO s_search_search_phrase;
DROP  TRIGGER ezsearch_search_phrase_new_tr;
CREATE OR REPLACE TRIGGER ezsearch_search_phrase_tr
BEFORE INSERT ON ezsearch_search_phrase FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_search_search_phrase.nextval INTO :new.id FROM dual;
END;
/


DROP TABLE ezsearch_return_count;
-- ALTER TABLE ezsearch_return_count RENAME TO ezsearch_return_count_old;
-- of course the next statement is only valid if you created `ezsearch_return_count_new`
ALTER TABLE ezsearch_return_count_new RENAME TO ezsearch_return_count;


DROP SEQUENCE s_search_return_count;
RENAME s_search_return_count_new TO s_search_return_count;
DROP  TRIGGER ezsearch_return_count_new_tr;
CREATE OR REPLACE TRIGGER ezsearch_return_count_tr
BEFORE INSERT ON ezsearch_return_count FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_search_return_count.nextval INTO :new.id FROM dual;
END;
/

DROP  INDEX ezsrch_ret_cnt_new_ph_id_cnt;
CREATE INDEX  ezsrch_ret_cnt_ph_id_cnt  ON   ezsearch_return_count ( phrase_id, count );


