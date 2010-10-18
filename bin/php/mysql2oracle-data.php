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

# Transfers all data from a given MySQL DB to an Oracle DB.
# Run the script without arguments to see its usage.

/**
 Columns aliases table
*/
$columnNameTransTable = array(
        'ezurl_object_link' => array( 'contentobject_attribute_version' => 'contentobject_attr_version' ),
        'ezenumobjectvalue' => array( 'contentobject_attribute_version' => 'contentobject_attr_version' ),
        'ezdbfile'          => array( 'size' => 'filesize' )
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
 Parses given Oracle login string of the following form:
 <user>/<pass>@<db>
 @param string $loginString (in) login string to parse
 @param string &$oraUser (out) db user
 @param string &$oraPass (out) db password
 @param string &$oraInst (out) Oracle instance
 @return bool true if the string was parsed successfully, false otherwise
*/
function parseOracleLoginString( $loginString, &$oraUser, &$oraPass, &$oraInst )
{
    if ( !preg_match( '|^(\S+)/(\S+)@(\S+)$|', $loginString, $matches ) )
        return false;
    array_shift( $matches );
    list( $oraUser, $oraPass, $oraInst ) = $matches;
    return true;
}


/**
 Fetch single value using given query.
 @return mixed fetched value
*/
function mySelectOneVar( $mydb, $query )
{
    $result = mysql_query($query, $mydb);
    $row    = mysql_fetch_row( $result );
    $val    = $row[0];
    mysql_free_result($result);
    return $val;
}

/**
 @return string alias (if specified) for a given table column.
*/
function getColumnAlias( $table, $col )
{
    global $columnNameTransTable;
    return isset( $columnNameTransTable[$table][$col] ) ? $columnNameTransTable[$table][$col] : $col;
}

/**
 @return array list of all tables in a given MySQL database
*/
function myGetTablesList( $mydb )
{
    $tables = array();
    if( !( $result = mysql_query( "SHOW TABLES", $mydb ) ) )
    {
        echo mysql_error();
        return false;
    }

    while ( $row = mysql_fetch_row( $result ) )
        $tables[] = $row[0];

    mysql_free_result( $result );
    return $tables;
}

/**
 Deletes all data from a given table in an Oracle DB.
*/
function oraDeleteTableData( $oradb, $table )
{
    echo "Deleting old Oracle data from table $table.\n";
    // Truncate is faster than delete
    //$deleteStmt = OCIParse( $oradb, "DELETE FROM $table" );
    $deleteStmt = OCIParse( $oradb, "TRUNCATE TABLE $table" );
    OCIExecute( $deleteStmt );
    OCIFreeStatement( $deleteStmt );
}

/**
 @return array columns information for a given MySQL table
*/
function myGetTableColumnsList( $mydb, $table )
{
    $columns = array();
    $mysqlColumns = mysql_query( "SHOW COLUMNS FROM $table", $mydb );
    while ( $column = mysql_fetch_array($mysqlColumns) )
    {
        $colname = $column['Field'];
        $coltype = $column['Type'];
        $columns[$colname] = $coltype;
    }
    mysql_free_result($mysqlColumns);
    return $columns;
}

/**
 Generates INSERT query into a given Oracle DB table and columns information.
 The query will be used in multiple times with variable binding feature.
 @param array $oraColumns an array of column datatypes and column names as keys for Oracle DB.
 @return string generated query
*/
function createOracleInsertQuery( $tableName, &$columns, $oraColums = array() )
{
    $columnsAliases = array();
    $columnsTypes = array();
    foreach ( array_keys( $columns ) as $colName )
    {
        $columnsAliases[] = getColumnAlias( $tableName, $colName );
        $columnsTypes[] = $columns[$colName];
    }

    $insertQueryValues = array();
    $insertQueryColumns = array();

    $blobColumns = array();
    $clobColumns = array();

    for( $i=0; $i < count( $columnsAliases ); $i++ )
    {
        if ( $columnsTypes[$i] == 'blob' )
        {
            $insertQueryValues[] = "EMPTY_BLOB()";
            $insertQueryColumns[] = $columnsAliases[$i];
            $blobColumns[] = $columnsAliases[$i];

        }
        elseif ( isset( $oraColums[$columnsAliases[$i]] ) && $oraColums[$columnsAliases[$i]] == 'clob' )
        {
            /* If datatype of the current column alias is 'clob'
             * we should not add it to Insert Query at the moment but should store it afterwords,
             * i.e. we should add 'clob' columns to the end of Insert Query otherwise we'll get the error:
             * "ORA-24816: Expanded non LONG bind data supplied after actual LONG or LOB column" when we call OCIExecute()
             */
            $clobColumns[] = $columnsAliases[$i];
        }
        else
        {
            $insertQueryValues[] = ':' . $columnsAliases[$i];
            $insertQueryColumns[] = $columnsAliases[$i];
        }
    }

    // Add 'clob' columns to the end of Insert Query
    foreach ( $clobColumns as $clobColumn )
    {
        $insertQueryValues[] = ':' . $clobColumn;
        $insertQueryColumns[] = $clobColumn;
    }

    $insertQuery = 'INSERT INTO ' . $tableName . ' (' . implode( ',', $insertQueryColumns ) . ') VALUES (' . implode( ',', $insertQueryValues ) . ')';

    if ( $blobColumns )
    {
        $cols = array();
        $vars = array();
        foreach ( $blobColumns as $blobColumn )
        {
            $cols[] = $blobColumn;
            $vars[] = ":$blobColumn";
        }

        $insertQuery .=
            " RETURNING " . join( ',', $cols ) .
            " INTO " . join( ',', $vars );
    }

    return $insertQuery;
}

/**
 Copies all data from the given MySQL table to the Oracle one.
 @return bool true on success, false otherwise
*/
function copyData( $mydb, $oradb, $tableName )
{
    $columns = myGetTableColumnsList( $mydb, $tableName );
    oraDeleteTableData( $oradb, $tableName );
    $nRows = mySelectOneVar($mydb, "SELECT COUNT(*) FROM $tableName");
    echo "Copying $tableName data ($nRows rows) from MySQL to Oracle.\n";

    // Determine Oracle's table schema.
    $tableSchemaStmt = OCIParse(
        $oradb,
        "SELECT column_name,data_type,data_length " .
        "FROM user_tab_columns WHERE LOWER(table_name)='$tableName'"
        );

    if ( !$tableSchemaStmt ||
         !OCIExecute( $tableSchemaStmt ) ||
         !OCIFetchStatement( $tableSchemaStmt, $resTableSchema ) )
    {
        die( "Failed to get schema for table '$tableName'\n" );
    }

    $oraColums = array();
    // Create array of column names and datatypes for using in createOracleInsertQuery()
    for ( $i=0; $i < count( $resTableSchema['COLUMN_NAME'] ); $i++ )
    {
        $oraColums[strtolower( $resTableSchema['COLUMN_NAME'][$i] )] = strtolower( $resTableSchema['DATA_TYPE'][$i] );
    }
    $insertQuery = createOracleInsertQuery( $tableName, $columns, $oraColums );
    $insertStmt = OCIParse( $oradb, $insertQuery );

    // Perform initial binding.
    $oraRow = array();
    $oraSchema = array();
    for ( $i=0; $i < count( $resTableSchema['COLUMN_NAME'] ); $i++ )
    {
        $colSize = $resTableSchema['DATA_LENGTH'][$i];
        $colType = strtolower( $resTableSchema['DATA_TYPE'][$i] );
        $colName = strtolower( $resTableSchema['COLUMN_NAME'][$i] );
        $colAlias = getColumnAlias( $tableName, $colName );
        $colIsBlob = stristr( $colType, 'blob' );

        //echo "COL SCHEMA #$i: $colName => $colAlias $colType($colSize)\n";

        $oraSchema[$colAlias] = array( 'size' => $colSize,
                                       'type' => $colType,
                                       'is_blob' => $colIsBlob );
        if ( $colIsBlob )
        {
            $oraRow[$colAlias] = OCINewDescriptor( $oradb, OCI_D_LOB );
            OCIBindByName( $insertStmt, ":$colAlias", $oraRow[$colAlias], -1, OCI_B_BLOB );
        }
        elseif ( !strcasecmp( $colType, 'clob' ) )
        {
            OCIBindByName( $insertStmt, ":$colAlias", $oraRow[$colAlias], 2147483647 ); // 2^31 (2GB-1)
            //OCIBindByName( $insertStmt, ":$colAlias", $oraRow[$colAlias], 4294967296 ); // 2^32 (4GB)
            //OCIBindByName( $insertStmt, ":$colAlias", $oraRow[$colAlias], 2147483647 ); // 2^31 (4GB-1)
            //OCIBindByName( $insertStmt, ":$colAlias", $oraRow[$colAlias], $oraSchema[$colAlias]['size'] );
            //die( "CLOB size: " . $colSize . "($colName)\n" );
            //OCIBindByName( $insertStmt, ":$colAlias", $oraRow[$colAlias], -1 );
        }
        else
        {
            $oraRow[$colAlias] = 0;
            //OCIBindByName( $insertStmt, ":$colAlias", $oraRow[$colAlias], -1 );
            OCIBindByName( $insertStmt, ":$colAlias", $oraRow[$colAlias], $oraSchema[$colAlias]['size'] );
        }
    }

    // We don't fetch all the table data at once since its size might be huge.
    // Instead, we fetch data by small ($limit rows max) portions.
    $limit = 5000;
    $nRowsProcessed = 0;
    for( $offset = 0; $offset < $nRows; $offset += $limit )
    {
        $result = mysql_query("SELECT * FROM $tableName LIMIT $offset, $limit", $mydb);
        while ( $row1 = mysql_fetch_array( $result, MYSQL_ASSOC ) )
        {
            foreach ( array_keys($row1) as $col )
            {
                $colAlias = getColumnAlias( $tableName, $col );
                if ( $oraSchema[$colAlias]['is_blob'] )
                {
                    //OCIBindByName( $insertStmt, ":$colAlias", $oraRow[$colAlias], -1, OCI_B_BLOB );
                }
                else
                {
                    $oraRow[$colAlias] = $row1[$col];
                    //OCIBindByName( $insertStmt, ":$colAlias", $oraRow[$colAlias], $oraSchema[$colAlias]['size'] );
                    //OCIBindByName( $insertStmt, ":$colAlias", $oraRow[$colAlias], -1 );
                }
            }

            $rc = OCIExecute( $insertStmt, OCI_DEFAULT ); // don't commit automatically
            if ( $rc === false )
            {
                echo "Failed query: $insertQuery\n";
                echo "mysql row:\n";  var_dump( $row1 );
                echo "oracle row:\n"; var_dump( $oraRow  );
                exit;
            }

            if ( $oraSchema[$colAlias]['is_blob'] )
            {
                if ( $row1[$col] )
                     $oraRow[$colAlias]->save( $row1[$col] );
            }

            $nRowsProcessed++;

            if ( ( $nRowsProcessed % 1000 ) == 0 )
                printf( "%02d%%|", $nRowsProcessed/$nRows*100 );
        }
        OCICommit( $oradb ); // commit all uncommitted data (if any)
        mysql_free_result( $result );
    }

    echo "\n";

    OCIFreeStatement( $insertStmt );

    foreach ( $oraSchema as $colAlias => $colSchema )
    {
        if ( $colSchema['is_blob'] )
            $oraRow[$colAlias]->free();
    }

    return true;
}

##############################################################################

error_reporting( E_ALL|E_STRICT );

// parse command line parameters
if ( $argc < 3 )
{
    echo "Usage: $argv[0] <mysql_login_string> <oracle_login_string>\n";
    echo "mysql_login_string  :- <dbname>:<user>/<pass>@<host>[:<port>]\n";
    echo "oracle_login_string :- <user>/<pass>@<db>\n";
    exit(1);
}

if ( !parseMysqlLoginString( $argv[1], $myDBName, $myUser, $myPass, $myHost ) )
    die( "Malformed MySQL login string.\n" );

if ( !parseOracleLoginString( $argv[2], $oraUser, $oraPass, $oraInst ) )
    die( "Malformed Oracle login string.\n" );

// connect to mysql

if ( !function_exists( 'mysql_connect' )  )
    die( "MySQL extension not activated, cannot execute\n" );

if ( !( $mydb = mysql_connect ( $myHost, $myUser, $myPass ) ) )
    die( "cannot connect to MySQL\n" );

if( !mysql_select_db( $myDBName, $mydb ) )
    die( "Could not select database: " . mysql_error() . "\n" );

mysql_query("SET NAMES utf8", $mydb);

// connect to oracle

if ( !function_exists( 'OCILogon' )  )
    die( "Oci8 extension not activated, cannot execute\n" );

if ( !( $oradb = OCILogon( $oraUser, $oraPass, $oraInst, 'AL32UTF8' ) ) )
    die( "cannot connect to Oracle\n" );

$mysqlTables = myGetTablesList( $mydb );
foreach ( $mysqlTables as $mysqlTable )
    copyData( $mydb, $oradb, $mysqlTable );

OCILogOff( $oradb );
mysql_close($mydb);
echo "Finished.\n";

?>
