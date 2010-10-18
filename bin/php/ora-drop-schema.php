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

/**
 Drops all objects (of types used by eZ Publish) in the current schema.
 Keeps user and associated tablespaces.
 Purges user's recycle bin.
*/

/**
 Parses given Oracle login string of the following form:
 <user>/<pass>@<db>
 @param string $loginString (in) login string to parse
 @param string &$oraUser (out) db user
 @param string &$oraPass (out) db password
 @param string &$oraInst (out) Oracle instance
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
 Shows command line arguments syntax and terminates script.
 */
function showUsage( $argv )
{
    echo "\n";
    echo "Usage: $argv[0] <login_string>\n";
    echo "login_string:\t<dbname>:<user>/<pass>@<host>[:<port>]\n";
    echo "\n";
    echo "Example:    corporate:ezuser/secret@localhost\n";
    exit( 1 );
}

##############################################################################

error_reporting( E_ALL|E_STRICT );

// parse command line parameters
if ( $argc < 2 )
    showUsage( $argv );

if ( !parseOracleLoginString( $argv[1], $oraUser, $oraPass, $oraInst ) )
    die( "Malformed login string.\n" );

// connect to oracle
if ( !( $oradb = oci_connect( $oraUser, $oraPass, $oraInst ) ) )
    die( "cannot connect to Oracle\n" );

$statements = array();

#select_seqs_sql="SELECT sequence_name FROM user_sequences where sequence_name LIKE 'EZ%' OR sequence_name LIKE 'S_%' OR sequence_name LIKE 'SIBANKLIST%';"
#select_tables_sql="SELECT table_name FROM user_tables WHERE table_name LIKE 'EZ%' OR table_name LIKE 'SIBANKLIST%';"

# select trigger_name from dba_triggers where trigger_name like 'EZ%';
# select index_name from dba_indexes where index_name like 'EZ%';

$select_tables_stmt = oci_parse( $oradb, "SELECT 'DROP TABLE ' || table_name AS statement FROM user_tables" );
if ( !$select_tables_stmt ||
     !oci_execute( $select_tables_stmt ) ||
     !oci_fetch_all( $select_tables_stmt, $res_tables_names ) )
{
    echo( "Failed to get tables list\n" );
}
else
{
    $statements = array_merge( $statements, $res_tables_names['STATEMENT'] );
}

$select_seqs_stmt = oci_parse( $oradb, "SELECT 'DROP SEQUENCE ' || sequence_name AS statement FROM user_sequences" );
if ( !$select_seqs_stmt ||
     !oci_execute( $select_seqs_stmt ) ||
     !oci_fetch_all( $select_seqs_stmt, $res_seqs_names ) )
{
    echo( "Failed to get sequences list\n" );
}
else
{
    $statements = array_merge( $statements, $res_seqs_names['STATEMENT'] );
}

$select_procs_stmt = oci_parse( $oradb, "SELECT 'DROP ' || object_type || ' ' || object_name AS statement FROM user_objects WHERE object_type in ('FUNCTION', 'PROCEDURE')" );
if ( !$select_procs_stmt ||
     !oci_execute( $select_procs_stmt ) ||
     !oci_fetch_all( $select_procs_stmt, $res_procs_names ) )
{
    echo( "Failed to get procedures list\n" );
}
else
{
    $statements = array_merge( $statements, $res_procs_names['STATEMENT'] );
}

foreach( $statements as $statement )
{
    $drop_stmt = oci_parse( $oradb, $statement );
    if ( !$drop_stmt ||
         !oci_execute( $drop_stmt ) )
    {
        echo "Could not execute statement: $statement\n";
    }
    else
    {
        echo "$statement\n";
    }
}

// Recycle bin does not exist in Oracle 8 - catch errors emptying it
if ( $purge_stmt = oci_parse( $oradb, "PURGE RECYCLEBIN" ) )
{
    @oci_execute( $purge_stmt );
}

oci_close( $oradb );
?>
