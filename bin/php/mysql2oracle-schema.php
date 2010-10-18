#!/usr/bin/env php
<?php
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ 0racle
// SOFTWARE RELEASE: 4.4.0
// COPYRIGHT NOTICE: Copyright (C) 1999-2010 eZ Systems AS
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
// 
//   This program is distributed in the hope that it will be useful,
//    but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
// 
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//

# Connects to MySQL, retrieves DB schema and dumps it in a format understandable by Oracle
#
# KNOWN BUGS:
# - tries to create indexes on CLOB columns (Oracle is incapable of doing this)

// columns names translation table: oracle doesn't understand identifiers longer than 30 characters
$columnNameTransTable = array(
    'ezenumobjectvalue.contentobject_attribute_version' => 'contentobject_attr_version',
    'ezurl_object_link.contentobject_attribute_version' => 'contentobject_attr_version',
    'ezdbfile.size' => 'filesize'
    );

// column-specific type overrides
$columnTypeTransTable = array(
    'ezurlalias.source_url'      => 'VARCHAR2(3000)',
    'ezurl.url'      => 'VARCHAR2(3000)',
    'ezurlalias_ml.action' => 'VARCHAR2(3000)',
    'ezurlalias_ml.text' => 'VARCHAR2(3000)',
    'ezurlalias.destination_url' => 'VARCHAR2(3000)',
    'ezcontentobject_tree.path_identification_string' => 'VARCHAR2(3100)',
    'ezcontentobject_trash.path_identification_string' => 'VARCHAR2(3100)',
    'ezimagefile.filepath' => 'VARCHAR2(3000)',
    'eznotificationcollection.data_subject' => 'VARCHAR2(3100)',
    'ezrss_import.url' => 'VARCHAR2(3100)',
    'ezrss_import.import_description' => 'VARCHAR2(3100)',
    'ezcontentclass.serialized_name_list' => 'VARCHAR2(3100)',
    'ezcontentclass_attribute.serialized_name_list' => 'VARCHAR2(3100)',
    'ezpending_actions.param' => 'VARCHAR2(3000)',
    'ezcontentclass.serialized_description_list' => 'VARCHAR2(3000)'
    );

// columns that could not have default value
$columnsWithoutDefaultVal = array(
    'ezimagefile.filepath',
    'eznotificationcollection.data_subject',
    'ezurl.url',
    'ezurlalias.source_url',
    'ezurlalias.destination_url'
    );

// columns that could not have NOT NULL contraint
$columnsWithDefaultNullVal = array(
    'ezcontentobject_attribute.sort_key_string',
    'eznode_assignment.parent_remote_id',
    'ezsearch_object_word_link.identifier',
    'ezurlalias.source_url',
    'ezurlalias.destination_url',
    'ezurlalias_ml.text',
    'ezurlalias_ml.action',
    'eznotificationevent.data_text1',
    'eznotificationevent.data_text2',
    'eznotificationevent.data_text3',
    'eznotificationevent.data_text4',
    'ezuser.email',
    'ezuser.login',
    'ezuser_discountrule.name',
    'ezcontentclass.identifier',
    'ezcontentclass_attribute.identifier',
    'ezcontentclass_attribute.category',
    'ezworkflow_event.description',
    'ezcontentclass.remote_id',
    'ezcollab_group.path_string',
    'ezcollab_profile.data_text1',
    'ezcollab_item.data_text1',
    'ezcollab_item.data_text2',
    'ezcollab_item.data_text3',
    'ezgeneral_digest_user_settings.day',
    'ezgeneral_digest_user_settings.time',
    'ezproductcollection.currency_code',
    'ezmedia.filename',
    'ezmedia.original_filename',
    'ezmedia.mime_type',
    'ezrss_import.import_description',
    'ezisbn_registrant_range.registrant_from',
    'ezisbn_registrant_range.registrant_to',
    'ezsession.data',
    'ezcobj_state_group_language.description',
    'ezcobj_state_language.description',
    'ezsession.user_hash',
    'ezenumvalue.enumelement',
    'ezenumvalue.enumvalue',
    'ezenumobjectvalue.enumvalue',
    'ezcontentclass_name.name'
    );

// index names translation table: oracle doesn't understand identifiers longer than 30 characters
$indexNameTransTable = array(
    'ezcontentobject_attribute.ezcontentobject_attribute_contentobject_id'    => 'ezco_attr_co_id',
    'ezcontentobject_attribute.ezcontentobject_attribute_co_id_ver_lang_code' => 'ezco_attr_co_id_ver_lang_code',
    'ezcontentobject_attribute.ezcontentobject_attribute_language_code'       => 'ezco_attr_language_code',
    'ezcontentobject_tree.ezcontentobject_tree_path_ident' => 'ezco_tree_path_ident',
    'ezenumobjectvalue.ezenumobjectvalue_co_attr_id_co_attr_ver' => 'ezenov_co_attr_id_co_attr_ver',
    'ezenumvalue.ezenumvalue_co_cl_attr_id_co_class_att_ver' => 'ezenv_coc_attr_id_coc_attr_ver',
    'ezmodule_run.ezmodule_run_workflow_process_id_s' => 'ezmodule_run_wf_process_id_i',
    'ezoperation_memento.ezoperation_memento_memento_key_main' => 'ezoperation_memento_mkey_main',
    'ezproductcollection_item.ezproductcollection_item_contentobject_id'     => 'ezproductcollection_item_co_id',
    'ezproductcollection_item.ezproductcollection_item_productcollection_id' => 'ezpol_item_pcol_id',
    'ezproductcollection_item_opt.ezproductcollection_item_opt_item_id'      => 'ezpcol_item_opt_item_id',
    'ezsearch_object_word_link.ezsearch_object_word_link_frequency'     => 'ezsearch_object_word_l_freq',
    'ezsearch_object_word_link.ezsearch_object_word_link_identifier'    => 'ezsearch_object_word_l_ident',
    'ezsearch_object_word_link.ezsearch_object_word_link_integer_value' => 'ezsearch_object_word_l_intval',
    'ezsearch_object_word_link.ezsearch_object_word_link_object'        => 'ezsearch_object_word_l_object',
    'ezsearch_object_word_link.ezsearch_object_word_link_word'          => 'ezsearch_object_word_l_word',
    'ezsubtree_notification_rule.ezsubtree_notification_rule_user_id' => 'ezsubtree_notif_rule_user_id',
    'ezwaituntildatevalue.ezwaituntildateevalue_wf_ev_id_wf_ver' => 'ezwaituntdateval_wfeid_wfever',
    'ezgeneral_digest_user_settings.ezgeneral_digest_user_settings_address' => 'ezgen_digest_user_settings_add'
    );

/**
 Parses given MySQL login string of the following form:
 <dbname>:<user>/<pass>@<host>[:<port>]
 @param string $loginString (in) login string to parse
 @param string &$dbname (out) db name
 @param string &$user (out) db user
 @param string &$pass (out) db password
 @param string &$host (out) host mysql is running on
 @return bool true if the string was parsed successfully, false otherwise
*/
function parseMysqlLoginString( $loginString, &$dbname, &$user, &$pass, &$host )
{
    if ( !preg_match( '#(\S+):(\S+)/(\S*)@(\S+)#', $loginString, $matches ) )
        return false;

    array_shift( $matches );
    list( $dbname, $user, $pass, $host ) = $matches;

    return true;
}

/**
 @return string alias for the given table column if one was
         specified in the column name translation table
*/
function getColumnAlias( $table, $col )
{
    global $columnNameTransTable;
    if( array_key_exists( "$table.$col", $columnNameTransTable ) )
        return $columnNameTransTable["$table.$col"];
    return $col;
}

/**
 @return string alias for the given index if one was
         specified in the index name translation table
*/
function getIndexAlias( $table, $idx )
{
    global $indexNameTransTable;
    if( array_key_exists( "$table.$idx", $indexNameTransTable ) )
        return $indexNameTransTable["$table.$idx"];
    return $idx;
}

/**
 @return string|null datatype override for the given table column if one was
         specified in the type translation table
*/
function getColumnTypeOverride( $table, $col )
{
    global $columnTypeTransTable;
    if ( array_key_exists( "$table.$col", $columnTypeTransTable ) )
        return $columnTypeTransTable["$table.$col"];
    return null;
}

/**
 @return array list of tables in the given MySQL database
*/
function myFetchTablesList( $mydb )
{
    $tables = array();
    $result = mysql_query("SHOW TABLES");

    while ( $row = mysql_fetch_array( $result, MYSQL_NUM ) )
    {
        //printf ("ID: %s  Name: %s", $row["id"], $row["name"]);
        $array[] = $row[0];
    }
    mysql_free_result($result);
    return $array;
}

function myColHasNoDefaultValOverride( $tableName, $columnName )
{
    global $columnsWithoutDefaultVal;
    return in_array( "$tableName.$columnName", $columnsWithoutDefaultVal );
}

function myColHasNotNullOverride( $tableName, $columnName )
{
    global $columnsWithDefaultNullVal;
    return in_array( "$tableName.$columnName", $columnsWithDefaultNullVal );
}


/**
 Fetches columns info for the given table.
 @param resource mydb MySQL DB handle to work with
 @param string table table name to fetch columns for
 @return array columns info
*/
function myGetColumnsList( $mydb, $table )
{
    // create columns list
    $rsltCols = mysql_query("show columns from $table", $mydb);
    $columns = array();
    while ($row = mysql_fetch_array($rsltCols, MYSQL_ASSOC))
        $columns[] = $row;
    mysql_free_result($rsltCols);
    return $columns;
}

/**
 @return string Oracle datatype for the given MySQL table column
*/
function getOracleType( $table, /*const*/ &$col )
{
    if( ( $colTypeOverride = getColumnTypeOverride( $table, $col['Field'] ) ) )
        return $colTypeOverride;

    $rslt = $col['Type'];
    $rslt = preg_replace( '/varchar/', 'VARCHAR2', $rslt );
    $rslt = preg_replace( '/char/', 'CHAR', $rslt );
    $rslt = preg_replace( '/(tiny|small|medium|big)?int(eger)?(\([0-9]+\))?( +unsigned)?/', 'INTEGER', $rslt );
    $rslt = preg_replace( '/^(medium|long)?text$/', 'CLOB', $rslt );
    $rslt = preg_replace( '/^double$/', 'DOUBLE PRECISION', $rslt );
    $rslt = preg_replace( '/^float$/', 'FLOAT', $rslt );
    return $rslt;
}

/**
 Cuts given identifier to the specified length providing uniqueness of all
 shortened identifiers.
 @return string
*/
function shorten( $identifier, $length = 30 )
{
    static $cnt = 1;
    if( strlen( $identifier ) <= $length)
        return $identifier;
    return substr( $identifier, 0, $length-5 ) . sprintf( "%05d", $cnt++ );
}

/**
 @return string like "<col_name> <col_type> [options]" that will be a part of
         DDL query for table creation
*/
function dumpColumnSchema( $table, $col, &$primaryKey, &$autoIncrement )
{
    $colname = getColumnAlias( $table, $col['Field'] );
    $colOraType = getOracleType( $table, $col ); // Oracle type for the column
    $colDef  = trim( $colname ) . ' '. $colOraType;

    $isStringColumn = stristr( $colOraType, 'CHAR' );
    $isLOBColumn = stristr( $colOraType, 'LOB' );
    $colHasNoDefaultValOverride = myColHasNoDefaultValOverride( $table, $colname ) ? 1 : 0;
    $colHasNotNullOverride = myColHasNotNullOverride( $table, $colname ) ? 1 : 0;

    // NB: Oracle treats '' as NULL

    if ( $isStringColumn || $isLOBColumn )
    {
        // CLOBs cannot have a default value in Oracle
        // LONGTEXTS have no default value in MySQL
        if ( $isStringColumn && $col['Default'] !== null && !$colHasNoDefaultValOverride && $col['Type'] != 'longtext' )
            $colDef .= " DEFAULT '". $col['Default'] . "'";
        if ( $col['Null'] !== 'YES' &&
             ( $col['Default'] !== null || $isLOBColumn || $col['Type'] == 'longtext' ) &&
             !$colHasNotNullOverride )
        {
            $colDef .= ' NOT NULL';
        }
    }
    else // numeric column
    {
        if( "${col['Default']}" !== "" )
            $colDef .= " DEFAULT ". $col['Default'];  // strings should be enclosed in quotes
        if ( $col['Null'] !== 'YES' )
            $colDef .= ' NOT NULL';
    }

    $primaryKey = ( $col['Key'] == 'PRI' );
    $autoIncrement = ( $col['Extra'] == 'auto_increment' );

    return $colDef;
}

/**
 Fetches indexes info for the given table
 @param resource $mydb handle of MySQL DB to work with
 @param string $table (in) table to fetch indexes info for
 @param array &$indexes (out) array containing indexes information
        for all the DB tables.
        Index info for $table will be appended to it.
*/
function appendTableIndexes( $mydb, $table, &$indexes )
{
    $rsltCols = mysql_query("show index from $table", $mydb);
    $tableIndexes = array();

    while ($row = mysql_fetch_array($rsltCols, MYSQL_ASSOC))
    {
        $idxRef =& $tableIndexes[ $table ][ $row['Key_name'] ];
        $idxRef['columns'][] = getColumnAlias( $table, $row['Column_name'] );
        $idxRef['name']      = $row['Key_name'];
        $idxRef['unique']    = !$row['Non_unique'];
    }

    $indexes = array_merge( $indexes,  $tableIndexes );
    mysql_free_result($rsltCols);
}

/**
 Dumps SQL query for creating table
 @param resource $mydb MySQL database handle to work with
 @param string $table table name
 @param array &$autoIncrementColumns (out) info about auto_increment columns is stored here
                              (used later to create sequences and triggers)
 @param array &$indexes (out) info about what indexes should be created on a table
                       is stored here
 @param $drop    If it's true we dump DROP TABLE clause before CREATE TABLE

 @return string DDL query for creating table
*/
function dumpTableSchema( $mydb, $table, &$autoIncrementColumns, &$indexes, $drop )
{
    $columns = myGetColumnsList( $mydb, $table );
    $firstCol = true;
    $create = "CREATE TABLE $table (\n";

    foreach( $columns as $col )
    {
        if( $firstCol )
            $firstCol = false;
        else
            $create .= ",\n";

        $create  .= '  ' . dumpColumnSchema( $table, $col, $pri, $autoIncr );
        $colAlias = getColumnAlias( $table, $col['Field'] );
        if( $autoIncr )
            $autoIncrementColumns[] = array( $table, $colAlias );
    }

    appendTableIndexes( $mydb, $table, $indexes );

    if ( isset( $indexes[$table] ) && array_key_exists( 'PRIMARY', $indexes[$table] ) )
    {
        $primaryKeyColumns = $indexes[$table]['PRIMARY']['columns'];
        $create .= ",\n  PRIMARY KEY ( " . implode( ', ', $primaryKeyColumns ) . " )";
    }

    $create .= "\n);\n";

    if ( $drop )
    {
        $drop = "DROP TABLE $table;\n";
        return $drop . $create;
    }

    return $create;
}

/**
 For given auto-increment columns list dumps queries for creating
 Oracle sequences and triggers implementing auto-increment feature.
 @param array $autoIncrementColumns (in) list of AI columns
 @param array &$seqs           (out) queries for creating sequences
                                    are stored here
 @param array &$triggers       (out) queries for creating triggers
 @param bool $drop                  if it's true we dump DROP SEQUENCE
                                    before CREATE SEQUENCE
*/
function dumpAutoIcrements( &$autoIncrementColumns, &$seqs, &$triggers, $drop )
{
    //var_dump( $autoIncrementColumns );
    $seqs = '';
    $triggers = '';
    foreach( $autoIncrementColumns as  $tableColumn )
    {
        list( $table, $col ) = $tableColumn;
        $cnt = 0;
        $trname  = shorten( $table.'_'.$col, 30-3 ) .'_tr';

        /* eZ Publish-specific hack for sequnce name to be always <= 30 characters
           (no longer that table name, at least)
         */
        $seqname = preg_replace( '/^ez/i', 's_', $table );
        if ( $seqname == $table )
        {
            // table name does not start with 'ez': an extension, most likely
            $seqname = substr( 'se_' . $seqname, 0, 30 );
        }

        if ( $drop )
            $seqs .= "DROP SEQUENCE $seqname;\n";

        $seqs .= "CREATE SEQUENCE $seqname;\n";

        // Here we make sure the semicolons that doesn't end the
        // statement is kept away from the end of the line
        // We need this for the SQL parser in eZDBInterface to work
        // The extra / (slash) is for SQLPlus which sees this as end of statement.
        $triggers .=
"CREATE OR REPLACE TRIGGER $trname
BEFORE INSERT ON $table FOR EACH ROW WHEN (new.$col IS NULL)
BEGIN
  SELECT $seqname.nextval INTO :new.$col FROM dual; " . "
END;
/\n\n";

    }
}

/**
 @param array $indexes indexes info
 @return string SQL queries for creating indexes
 */
function dumpIndexes( &$indexes )
{
    $rslt = '';
    foreach( $indexes as $table => $tableIndexes )
    {
        foreach( $tableIndexes as $idx )
        {
            $unique =& $idx['unique'];
            $cols   =& $idx['columns'];
            $idx_name =& $idx['name'];

            if ( $idx_name == 'PRIMARY' ) // skip primary key
                continue;

            if ( strlen( $idx_name ) > 30 ) // if index name is too long
            {
                // look up an alias
                $idx_alias = getIndexAlias( $table, $idx_name );
                if ( $idx_alias != $idx_name )
                    $idx_name = $idx_alias;
                else
                    // in absence of the alias we truncate index name
                    $idx_name = shorten( $table.'_'.implode('_', $cols), 30-2 ) . '_i';
            }
            $rslt .= "CREATE " .
                ($unique?"UNIQUE ":" ") .
                "INDEX $idx_name ON $table (". implode(', ', $cols) . ");\n";
        }
    }
    return $rslt;
}

/**
    Fetches schema from the given Mysql DB and dumps it in a format
    understandable by Oracle.
    @param resource $mydb MySQL database handle
    @param bool $drop     If it's true we drop sequences and trigger before creating them
    @return string        string containing set of generated SQL queries
*/
function dumpOracleSchema( $mydb, $drop )
{
    $aiColumns = array();
    $indexes = array();
    $aiSeqsSchema = '';
    $aiTriggersSchema = '';

    $mysql_tables = myFetchTablesList( $mydb );
    $tablesSchema = '';
    foreach ( $mysql_tables as $mysql_table )
        $tablesSchema .= dumpTableSchema( $mydb, $mysql_table, $aiColumns, $indexes, $drop );

    dumpAutoIcrements( $aiColumns, $aiSeqsSchema, $aiTriggersSchema, $drop );

    $schema = "-- Generated by mysql2oracle-schema.php\n\n";

    // dump sequences for auto_increment fields
    $schema .= $aiSeqsSchema . "\n";

    // dump tables
    $schema .= $tablesSchema;

    // dump triggers for auto_increment fields
    $schema .= $aiTriggersSchema;

    // dump indexes
    $schema .= dumpIndexes( $indexes );

    return $schema;
}

/**
 Shows command line arguments syntax and terminates script.
 */
function showUsage( $argv )
{
    echo "\n";
    echo "Usage: $argv[0] [options] <login_string>\n";
    echo "login_string:\t<dbname>:<user>/<pass>@<host>[:<port>]\n";
    echo "\n";
    echo "Options:\n";
    echo "\t--drop: drop tables/sequences before creating them.\n";
    echo "\n";
    echo "Example:    corporate:ezuser/secret@localhost\n";
    exit( 1 );
}

##############################################################################
error_reporting( E_ALL );

$loginString = '';
$optDrop     = false;

if ( $argc < 2 )
    showUsage( $argv );

// parse command line options
foreach ( array_slice( $argv, 1 ) as $arg )
{
    if ( preg_match ( '/^--(.*)$/', $arg, $matches ) ) // an option
    {
        $option = $matches[1];
        switch ( $option )
        {
        case 'drop':
            $optDrop = true;
            break;
        default:
            echo "Unknown option: $option.\n";
            showUsage( $argv );
        }
        if ( $option == 'drop' )
            $optDrop = true;
    }
    elseif ( !$loginString )
            $loginString = $arg;
}

if ( !parseMysqlLoginString( $loginString,
                            $myDBName, $myUser, $myPass, $myHost ) )
    die( "Malformed login string\n" );

if ( !function_exists( 'mysql_connect' )  )
    die( "MySQL extension not activated, cannot execute\n" );

if ( !( $mydb = @mysql_connect( $myHost, $myUser, $myPass ) ) )
    die( "cannot connect to MySQL: " . mysql_error() . "\n" );

if ( !@mysql_select_db( $myDBName, $mydb ) )
    die( "cannot select database `$myDBName': " . mysql_error() . "\n" );

echo dumpOracleSchema( $mydb, $optDrop );

mysql_close( $mydb );
?>
