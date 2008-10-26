#!/usr/bin/env php
<?
#
# Updates all sequences in a given DB to contain maximum value in
# related table column: seq.val = select max(col) from related_table;
#
# You might need to run this script after bulk data loads.
#
# Usage: ora-update-seqs <login_string>
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
function& oraFetchTriggersInfo( $oraDB )
{
    $triggers = array();
    $query = "SELECT table_name,trigger_body FROM user_triggers";
    $statement = OCIParse( $oraDB, $query );
    OCIExecute( $statement );
    while ( OCIFetchInto( $statement, $row,
                           OCI_ASSOC+OCI_RETURN_LOBS+OCI_RETURN_NULLS ) )
    {
        $triggers[] = array(
            'table_name'   => $row['TABLE_NAME'],
            'trigger_body' => $row['TRIGGER_BODY']
       );
    }
    OCIFreeStatement( $statement );
    return $triggers;
}

##############################################################################
function& getSequences( &$triggers )
{
    $seqs = array();
    foreach ( $triggers as $triggerParams )
    {
        $tableName   =& $triggerParams['table_name'];
        $triggerBody =& $triggerParams['trigger_body'];
        //echo "$tableName - $triggerBody\n";
        if ( preg_match( TRIGGER_REGEXP_1, $triggerBody, $matches ) ||
             preg_match( TRIGGER_REGEXP_2, $triggerBody, $matches ) )
        {
            $sequenceName = $matches[1];
            $tableCol     = $matches[2];
        }
        else
            continue;
        $seqs[$sequenceName] = array( $tableName, $tableCol );
    }
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
    return $val === null ? 0 : $val;

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
function oraUpdateSeqence( $oraDB, $seq, $table, $col )
{
    $curColVal = oraSelectOneVar( $oraDB, "SELECT MAX($col) FROM \"$table\"" );
    $curSeqVal = oraSelectOneVar( $oraDB, "SELECT $seq.nextval FROM DUAL" );
    $inc = $curColVal - $curSeqVal;

    if( !$inc ) // no need to increment
        return;

    oraDoQuery( $oraDB, "ALTER SEQUENCE $seq MINVALUE 0" );
    oraDoQuery( $oraDB, "ALTER SEQUENCE $seq INCREMENT BY $inc" );
    $finalSeqVal = oraSelectOneVar( $oraDB, "SELECT $seq.nextval FROM DUAL" );
    oraDoQuery( $oraDB, "ALTER SEQUENCE $seq INCREMENT BY 1" );
    $rslt = ( $finalSeqVal == $curColVal ) ? 'ok' : '**FAILED**';
    printf( "updating %30s: %10s\n", $seq, $rslt );
}

##############################################################################
function oraUpdateSequences( $oraDB, &$seqs )
{
    if ( count($seqs) == 0 )
    {
        echo "No auto_increment sequences found.\n";
        return;
    }
    foreach( $seqs as $seq => $tableData )
    {
        list( $table, $col ) = $tableData;
        oraUpdateSeqence( $oraDB, $seq, $table, $col );
    }
}

##############################################################################
error_reporting( E_ALL );

if ( $argc < 2 )
{
    echo "Usage: $argv[0] <login_string>\n";
    exit( 1 );
}

$oraUser = '';
$oraPass = '';
$oraInst = ''; // oracle instance

if ( !oraParseLoginString( $argv[1], $oraUser, $oraPass, $oraInst ) )
    die( "Malformed login string: $argv[1]\n" );

if( !( $oraDB = OCILogon( $oraUser, $oraPass, $oraInst ) ) )
    die( "cannot connect to Oracle\n" );

$triggers = oraFetchTriggersInfo( $oraDB );
$seqs     = getSequences( $triggers );
oraUpdateSequences( $oraDB, $seqs );

OCILogOff( $oraDB );

?>
