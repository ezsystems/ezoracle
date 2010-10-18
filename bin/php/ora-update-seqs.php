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

#
# Updates all sequences in a given DB to contain maximum value in
# related table column: seq.val = select max(col) from related_table;
#
# You might need to run this script after bulk data loads.
#
# Usage: ora-update-seqs [--exact] <login_string>
# For example,
#        ora-update-seqs scott/tiger@orcl
##############################################################################


define( 'TRIGGER_REGEXP_1',
        '/BEGIN\s+' .
        'IF :new.\S+ is null THEN\s+' .
        'SELECT (\S+).nextval INTO :new.(\S+) FROM dual;\s+' .
        'END IF;\s+' .
        'END;/' );

define( 'TRIGGER_REGEXP_2',
        '/BEGIN\s+' .
        'SELECT (\S+).nextval INTO :new.(\S+) FROM dual;\s+' .
        'END;/' );

##############################################################################
function oraParseLoginString( $loginString, &$oraUser, &$oraPass, &$oraInst )
{
    if ( !preg_match( '|^(\S+)/(\S+)@(\S+)$|', $loginString, $matches ) )
        return false;
    $oraUser = $matches[1];
    $oraPass = $matches[2];
    $oraInst   = $matches[3];
    return true;
}

##############################################################################
function oraFetchTriggersInfo( $oraDB )
{
    $triggers = array();
    $query = "SELECT trigger_name,table_name,trigger_body FROM user_triggers WHERE table_name NOT LIKE 'BIN$%'";
    $statement = OCIParse( $oraDB, $query );
    OCIExecute( $statement );
    while ( OCIFetchInto( $statement, $row,
                           OCI_ASSOC+OCI_RETURN_LOBS+OCI_RETURN_NULLS ) )
    {
        $triggers[] = array(
            'table_name'   => $row['TABLE_NAME'],
            'trigger_body' => $row['TRIGGER_BODY'],
            'trigger_name' => $row['TRIGGER_NAME'],
       );
    }
    OCIFreeStatement( $statement );
    return $triggers;
}

##############################################################################
function getSequences( $triggers )
{
    $seqs = array();
    foreach ( $triggers as $triggerParams )
    {
        //$tableName   = $triggerParams['table_name'];
        $triggerBody = $triggerParams['trigger_body'];
        //echo "$tableName - $triggerBody\n";
        if ( preg_match( TRIGGER_REGEXP_1, $triggerBody, $matches ) ||
             preg_match( TRIGGER_REGEXP_2, $triggerBody, $matches ) )
        {
            $sequenceName = $matches[1];
            $tableCol     = $matches[2];
        }
        else
            continue;
        $seqs[$sequenceName] = array( $triggerParams['table_name'], $tableCol, $triggerParams['trigger_name'] );
    }
    ksort($seqs);
    return $seqs;
}

##############################################################################
function oraSelectOneVar( $oraDB, $query )
{
    $val = false;

    if( !( $statement = OCIParse( $oraDB, $query ) ) )
        return false;

    if( OCIExecute( $statement ) )
    {
        OCIFetchInto( $statement, $row,
                      OCI_NUM+OCI_RETURN_LOBS+OCI_RETURN_NULLS );
        $val = $row[0];
    }

    OCIFreeStatement( $statement );
    return $val;

}

##############################################################################
function oraDoQuery( $oraDB, $query )
{
    if ( !( $statement = OCIParse( $oraDB, $query ) ) )
        return false;
    $rc = OCIExecute( $statement );
    OCIFreeStatement( $statement );
    return $rc;
}

##############################################################################
function oraUpdateSeqence( $oraDB, $seq, $table, $col, $trig = '', $exact = false )
{
    $curColVal = oraSelectOneVar( $oraDB, "SELECT MAX($col) FROM \"$table\"" );
    // nb: fetching CURRVAL will not work if the sequence was never used before (ora error)
    // this means that for empty tables we must first fetch the first value (1) of the sequence,
    // then reset it back to 0 - unless we skip resetting the sequence altogether
    if ( $curColVal === null && !$exact ) // empty table: no need to increment sequence
        return;
    $curColVal = (int)$curColVal;
    $curSeqVal = oraSelectOneVar( $oraDB, "SELECT $seq.nextval FROM DUAL" );
    $inc = $curColVal - $curSeqVal;

    if( (!$exact && $inc <= 0) || ($exact && $inc === 0) ) // no need to increment: sequence is above table or at same level
        return;

    // work around a php 5.2.5 segfault with oci instant client 10.1.0.4 connected to oracle xe 10.2.0.1:
    // sequences cannot be altered in any meaningful way
    oraDoQuery( $oraDB, "DROP sequence $seq" );
    $rslt = oraDoQuery( $oraDB, "CREATE SEQUENCE $seq MINVALUE ".($curColVal+1) );
    if ( $rslt && $trig != '' )
    {
        $rslt = oraDoQuery( $oraDB, "ALTER TRIGGER $trig COMPILE" );
    }
    $rslt = $rslt ? "ok" : "**FAILED**";
    printf( "updating %30s: %10s\n", $seq, $rslt );
}

##############################################################################
function oraUpdateSequences( $oraDB, $seqs, $exact )
{
    if ( count($seqs) == 0 )
    {
        echo "No auto_increment sequences found.\n";
        return;
    }
    foreach( $seqs as $seq => $tableData )
    {
        list( $table, $col, $trig ) = $tableData;
        oraUpdateSeqence( $oraDB, $seq, $table, $col, $trig, $exact );
    }
}

##############################################################################
/**
 Shows command line arguments syntax and terminates script.
 */
function showUsage( $argv )
{
    echo "\n";
    echo "Usage: $argv[0] [options] <login_string>\n";
    echo "Options:\n";
    echo "\t--exact: lower the next value of sequences that are above table max value, too\n";
    echo "\n";
    exit( 1 );
}

##############################################################################
error_reporting( E_ALL );

$loginString = '';
$optExact    = false;

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
            case 'exact':
                $optExact = true;
                break;
            default:
                echo "Unknown option: $option.\n";
                showUsage( $argv );
        }
    }
    elseif ( !$loginString )
            $loginString = $arg;
}

$oraUser = '';
$oraPass = '';
$oraInst = ''; // oracle instance

if ( !oraParseLoginString( $loginString, $oraUser, $oraPass, $oraInst ) )
    die( "Malformed login string: $argv[1]\n" );

if ( !function_exists( 'OCILogon' )  )
    die( "Oci8 extension not activated, cannot execute\n" );

if( !( $oraDB = OCILogon( $oraUser, $oraPass, $oraInst ) ) )
    die( "cannot connect to Oracle\n" );

$triggers = oraFetchTriggersInfo( $oraDB );
$seqs     = getSequences( $triggers );
oraUpdateSequences( $oraDB, $seqs, $optExact );

OCILogOff( $oraDB );

?>
