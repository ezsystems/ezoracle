<?php
//
// Definition of eZOracleDB class
//
// Created on: <25-Feb-2002 14:50:11 ce>
//
// Copyright (C) 1999-2005 eZ systems as. All rights reserved.
//
// This source file is part of the eZ publish (tm) Open Source Content
// Management System.
//
// This file may be distributed and/or modified under the terms of the
// "GNU General Public License" version 2 as published by the Free
// Software Foundation and appearing in the file LICENSE.GPL included in
// the packaging of this file.
//
// Licencees holding valid "eZ publish professional licences" may use this
// file in accordance with the "eZ publish professional licence" Agreement
// provided with the Software.
//
// This file is provided AS IS with NO WARRANTY OF ANY KIND, INCLUDING
// THE WARRANTY OF DESIGN, MERCHANTABILITY AND FITNESS FOR A PARTICULAR
// PURPOSE.
//
// The "eZ publish professional licence" is available at
// http://ez.no/products/licences/professional/. For pricing of this licence
// please contact us via e-mail to licence@ez.no. Further contact
// information is available at http://ez.no/home/contact/.
//
// The "GNU General Public License" (GPL) is available at
// http://www.gnu.org/copyleft/gpl.html.
//
// Contact licence@ez.no if any conditions of this licencing isn't clear to
// you.
//

//!
/*!
  \class eZOracleDB ezoracledb.php
  \ingroup eZDB
  \brief Provides Oracle database functions for eZDB subsystem

  eZOracleDB implements OracleDB spesific database code.
*/

include_once( "lib/ezutils/classes/ezdebug.php" );
include_once( "lib/ezdb/classes/ezdbinterface.php" );

class eZOracleDB extends eZDBInterface
{
    /*!
      Creates a new eZOracleDB object and connects to the database.
    */
    function eZOracleDB( $parameters )
    {
        $this->eZDBInterface( $parameters );

        $server = $this->Server;
        $user = $this->User;
        $password = $this->Password;
        $db = $this->DB;

        $this->ErrorMessage = false;
        $this->ErrorNumber = false;
        $this->IgnoreTriggerErrors = false;

        $ini =& eZINI::instance();

        if ( function_exists( "OCILogon" ) )
        {
            $this->Mode = OCI_COMMIT_ON_SUCCESS;

            // translate chosen charset to its Oracle analogue
            $oraCharset = null;
            if ( isset( $this->Charset ) && $this->Charset !== '' )
            {
                if ( array_key_exists( $this->Charset, $this->CharsetsMap ) )
                     $oraCharset = $this->CharsetsMap[$this->Charset];
            }

            $this->DBConnection = OCILogon( $user, $password, $db, $oraCharset );

//            OCIInternalDebug(1);

            if ( $this->DBConnection === false )
                $this->IsConnected = false;
            else
                $this->IsConnected = true;

            if ( $this->DBConnection === false )
            {
                $error = OCIError();

                // workaround for bug in PHP oci8 extension
                if ( $error === false && !getenv( "ORACLE_HOME" ) )
                    $error = array( 'code' => -1, 'message' => 'OCACLE_HOME environment variable is not set' );

                if ( $error['code'] != 0 )
                {
                    if ( $error['code'] == 12541 )
                        $error['message'] = 'No listener (probably the server is down).';
                    $this->ErrorMessage = $error['message'];
                    $this->ErrorNumber = $error['code'];
                    eZDebug::writeError( "Connection error(" . $error["code"] . "):\n". $error["message"] .  " ", "eZOracleDB" );
                }
            }
        }
        else
        {
            $this->ErrorMessage = "Oracle support not compiled in PHP";
            $this->ErrorNumber = -1;
            eZDebug::writeError( $this->ErrorMessage, "eZOracleDB" );
            $this->IsConnected = false;
        }
    }

    /*!
      \reimp
    */
    function databaseName()
    {
        return 'oracle';
    }

    /*!
      \reimp
    */
    function bindingType( )
    {
        return EZ_DB_BINDING_NAME;
    }

    /*!
      \reimp
    */
    function bindVariable( $value, $fieldDef = false )
    {
        if ( $this->InputTextCodec )
        {
            $value = $this->InputTextCodec->convertString( $value );
        }
        $this->BindVariableArray[] = array( 'name' => $fieldDef['name'],
                                            'dbname' => ':' . $fieldDef['name'],
                                            'value' => $value );
        return ':' . $fieldDef['name'];
    }

    /*!
      \reimp
    */
    function &query( $sql )
    {
        $this->ErrorMessage = false;
        $this->ErrorNumber = false;
        if ( !$this->isConnected() )
            return null;
        $result = true;

        if ( $this->OutputSQL )
            $this->startTimer();
        // The converted sql should not be output
        if ( $this->InputTextCodec )
        {
            $sql = $this->InputTextCodec->convertString( $sql );
        }

        eZDebug::accumulatorStart( 'oracle_query', 'oracle_total', 'Oracle_queries' );
        $statement = OCIParse( $this->DBConnection, $sql );

        foreach ( $this->BindVariableArray as $bindVar )
            OCIBindByName( $statement, $bindVar['dbname'], $bindVar['value'], -1 );

        if ( $statement )
        {
            $exec = @OCIExecute( $statement, OCI_DEFAULT );
            if ( !$exec )
            {
                $error = OCIError( $statement );
                $hasError = true;
                if ( !$error['code'] )
                {
                    $hasError = false;
                }
                if ( $hasError )
                {
                    $result = false;
                    $this->ErrorMessage = $error['message'];
                    $this->ErrorNumber = $error['code'];
                    if ( isset( $error['sqltext'] ) )
                        $sql = $error['sqltext'];
                    $offset = false;
                    if ( isset( $error['offset'] ) )
                        $offset = $error['offset'];
                    $offsetText = '';
                    if ( $offset !== false )
                    {
                        $offsetText = ' at offset ' . $offset;
                        $sqlOffsetText = "\n\nStart of error:\n" . substr( $sql, $offset );
                    }
                    eZDebug::writeError( "Error (" . $error['code'] . "): " . $error['message'] . "\n" .
                                         "Failed query$offsetText:\n" .
                                         $sql .
                                         $sqlOffsetText, "eZOracleDB" );
                    OCIFreeStatement( $statement );
                    return $result;
                }
            }

            // Commit when we are not in a transaction and we use an 'autocommit' mode.
            if ( $this->Mode != OCI_DEFAULT && $this->TransactionCounter == 0)
            {
                OCICommit( $this->DBConnection );
            }
            if ( $this->OutputSQL )
            {
                $this->endTimer();
                if ($this->timeTaken() > $this->SlowSQLTimeout)
                {
                    $this->reportQuery( 'eZOracleDB', $sql, false, $this->timeTaken() );
                }
            }
        }
        else
        {
            $error = OCIError( $this->DBConnection );
            $hasError = true;
            if ( !$error['code'] )
            {
                $hasError = false;
            }
            if ( $hasError )
            {
                $result = false;
                $this->ErrorMessage = $error['message'];
                $this->ErrorNumber = $error['code'];
                if ( isset( $error['sqltext'] ) )
                    $sql = $error['sqltext'];
                $offset = false;
                if ( isset( $error['offset'] ) )
                    $offset = $error['offset'];
                $offsetText = '';
                if ( $offset !== false )
                {
                    $offsetText = ' at offset ' . $offset;
                    $sqlOffsetText = "\n\nStart of error:\n" . substr( $sql, $offset );
                }
                eZDebug::writeError( "Error (" . $error['code'] . "): " . $error['message'] . "\n" .
                                     "Failed query$offsetText:\n" .
                                     $sql .
                                     $sqlOffsetText, "eZOracleDB" );
                OCIFreeStatement( $statement );
                return $result;
            }
        }

        eZDebug::accumulatorStop( 'oracle_query' );
        $this->BindVariableArray = array();
        OCIFreeStatement( $statement );

        return $result;
    }

    /*!
      \reimp
    */
    function& arrayQuery( $sql, $params = array() )
    {
        $resultArray = array();

        if ( !$this->isConnected() )
            return $resultArray;

        $limit = -1;
        $offset = 0;
        $column = false;
        // check for array parameters
        if ( is_array( $params ) )
        {
            $column = false;
            if ( isset( $params["limit"] ) and is_numeric( $params["limit"] ) )
            {
                $limit = $params["limit"];
            }

            if ( isset( $params["offset"] ) and is_numeric( $params["offset"] ) )
            {
                $offset = $params["offset"];
            }
            if ( isset( $params["column"] ) and is_numeric( $params["column"] ) )
                $column = $params["column"];
        }

        if ( $this->OutputSQL )
            $this->startTimer();
        // The converted sql should not be output
        if ( $this->InputTextCodec )
        {
            $sql = $this->InputTextCodec->convertString( $sql );
        }
        eZDebug::accumulatorStart( 'oracle_query', 'oracle_total', 'Oracle_queries' );
        $statement = OCIParse( $this->DBConnection, $sql );
        //flush();
        if ( !@OCIExecute( $statement, $this->Mode ) )
        {
            $error = OCIError( $statement );
            $hasError = true;
            if ( !$error['code'] )
            {
                $hasError = false;
            }
            if ( $hasError )
            {
                $result = false;
                $this->ErrorMessage = $error['message'];
                $this->ErrorNumber = $error['code'];
                if ( isset( $error['sqltext'] ) )
                    $sql = $error['sqltext'];
                $offset = false;
                if ( isset( $error['offset'] ) )
                    $offset = $error['offset'];
                $offsetText = '';
                if ( $offset !== false )
                {
                    $offsetText = ' at offset ' . $offset;
                    $sqlOffsetText = "\n\nStart of error:\n" . substr( $sql, $offset );
                }
                eZDebug::writeError( "Error (" . $error['code'] . "): " . $error['message'] . "\n" .
                                     "Failed query$offsetText:\n" .
                                     $sql .
                                     $sqlOffsetText, "eZOracleDB" );
                OCIFreeStatement( $statement );
                eZDebug::accumulatorStop( 'oracle_query' );

                return $result;
            }
        }
        eZDebug::accumulatorStop( 'oracle_query' );

        if ( $this->OutputSQL )
        {
            $this->endTimer();
            if ($this->timeTaken() > $this->SlowSQLTimeout)
            {
                $this->reportQuery( 'eZOracleDB', $sql, false, $this->timeTaken() );
            }
        }

        $numCols = OCINumcols( $statement );
        $resultArray = array();
        $row = array();

        $results = array();

        eZDebug::accumulatorStart( 'looping_oracle_results', 'oracle_total', 'Oracle looping results' );
        if ( $limit != -1 )
        {
            if ( $column !== false )
            {
                OCIFetchStatement( $statement, $results, $offset, $limit, OCI_FETCHSTATEMENT_BY_ROW + OCI_NUM );
                $rowCount = count( $results );
                for ( $i = 0; $i < $rowCount; ++$i )
                {
                    $row =& $results[$i];
                    if ( $this->InputTextCodec )
                        $resultArray[$i + $offset] = $this->OutputTextCodec->convertString( $row[$column] );
                    else
                        $resultArray[$i + $offset] = $row[$column];
                }
            }
            else
            {
                OCIFetchStatement( $statement, $results, $offset, $limit, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC );
                $rowCount = count( $results );
                for ( $i = 0; $i < $rowCount; ++$i )
                {
                    $row =& $results[$i];
                    $newRow = array();
                    foreach ( $row as $key => $value )
                    {
                        if ( $this->InputTextCodec )
                            $newRow[strtolower( $key )] = $this->OutputTextCodec->convertString( $value );
                        else
                            $newRow[strtolower( $key )] = $value;
                    }
                    $resultArray[$i + $offset] = $newRow;
                }
            }
        }
        else
        {
            if ( $column !== false )
            {
                while ( OCIFetchInto( $statement, $row, OCI_NUM + OCI_RETURN_LOBS + OCI_RETURN_NULLS ) )
                {
                    if ( $this->InputTextCodec )
                        $resultArray[] = $this->OutputTextCodec->convertString( $row[$column] );
                    else
                        $resultArray[] = $row[$column];
                }
            }
            else
            {
                while ( OCIFetchInto( $statement, $row, OCI_ASSOC + OCI_RETURN_LOBS + OCI_RETURN_NULLS ) )
                {
                    $newRow = array();
                    foreach ( $row as $key => $value )
                    {
                        if ( $this->InputTextCodec )
                            $newRow[strtolower( $key )] = $this->OutputTextCodec->convertString( $value );
                        else
                            $newRow[strtolower( $key )] = $value;
                    }
                    $resultArray[] = $newRow;
                }
            }
        }
        eZDebug::accumulatorStop( 'looping_oracle_results' );
        OCIFreeStatement( $statement );
        unset( $statement );
        unset( $row );

        return $resultArray;
    }

    /*!
     \private
    */
    function subString( $string, $from, $len = null )
    {
        if ( $len == null )
        {
            return " substr( $string, $from ) ";
        }else
        {
            return " substr( $string, $from, $len ) ";
        }
    }

    /*!
      \reimp
    */
    function beginQuery()
    {
        return true;
    }

    /*!
      \reimp
    */
    function useShortNames()
    {
        return true;
    }

    /*!
      \reimp
    */
    function commitQuery()
    {
        return OCICommit( $this->DBConnection );
    }

    /*!
      \reimp
    */
    function rollbackQuery()
    {
        return OCIRollback( $this->DBConnection );
    }

    /*!
      \reimp
    */
    function &lastSerialID( $table, $column )
    {
        $id = null;
        if ( $this->isConnected() )
        {
            $sequence = eregi_replace( '^ez', 's_', $table );
            $sql = "SELECT $sequence.currval from DUAL";
            $res =& $this->arrayQuery( $sql );
            $id = $res[0]["currval"];
        }

        return $id;
    }

    /*!
      \reimp
    */
    function &escapeString( $str )
    {
        $str = str_replace ("'", "''", $str );
//        $str = str_replace ("\"", "\\\"", $str );
        return $str;
    }

    /*!
      \reimp
    */
    function concatString( $strings = array() )
    {
        $str = implode( " || " , $strings );
        return "  $str   ";
    }

    /*!
      \reimp
    */
    function md5( $str )
    {
        return " md5_digest( $str ) ";
    }

    /*!
     \reimp
    */
    function supportedRelationTypeMask()
    {
        return ( EZ_DB_RELATION_TABLE_BIT |
                 EZ_DB_RELATION_SEQUENCE_BIT |
                 EZ_DB_RELATION_TRIGGER_BIT |
                 EZ_DB_RELATION_VIEW_BIT |
                 EZ_DB_RELATION_INDEX_BIT );
    }

    /*!
     \reimp
    */
    function supportedRelationTypes()
    {
        return array( EZ_DB_RELATION_TABLE,
                      EZ_DB_RELATION_SEQUENCE,
                      EZ_DB_RELATION_TRIGGER,
                      EZ_DB_RELATION_VIEW,
                      EZ_DB_RELATION_INDEX );
    }

    /*!
     \private
     \return Detailed information regarding a relation type.
             It will return an associative array containing:
             - table - The table which contains information on the relation type
             - field - The field that contains the name of the relation types
             - ignore_name - If the field starts with this (case-insensitive) string
                             the relation must be ignored. (optional)
             \c false is returned if it is an unknown relation type.
     \param $relationType One of the relation types defined in eZDBInterface
    */
    function relationInfo( $relationType )
    {
        $kind = array( EZ_DB_RELATION_TABLE => array( 'table' => 'user_tables',
                                                      'field' => 'table_name' ),
                       EZ_DB_RELATION_SEQUENCE => array( 'table' => 'user_sequences',
                                                         'field' => 'sequence_name' ),
                       EZ_DB_RELATION_TRIGGER => array( 'table' => 'user_triggers',
                                                        'field' => 'trigger_name' ),
                       EZ_DB_RELATION_VIEW => array( 'table' => 'user_views',
                                                     'field' => 'view_name' ),
                       EZ_DB_RELATION_INDEX => array( 'table' => 'user_indexes',
                                                      'field' => 'index_name',
                                                      'ignore_name' => 'sys' ) );
        if ( !isset( $kind[$relationType] ) )
            return false;
        return $kind[$relationType];
    }

    /*!
     \reimp
    */
    function relationCounts( $relationMask )
    {
        $relationTypes = $this->supportedRelationTypes();
        $relationInfoList = array();
        foreach ( $relationTypes as $relationType )
        {
            $relationBit = (1 << $relationType );
            if ( $relationMask & $relationBit )
            {
                $relationInfo = $this->relationInfo( $relationType );
                if ( $relationInfo )
                    $relationInfoList[] = $relationInfo;
            }
        }
        if ( count( $relationInfoList ) == 0 )
            return 0;
        $count = false;
        if ( $this->isConnected() )
        {
            $count = 0;
            foreach ( $relationInfoList as $relationInfo )
            {
                $field = $relationInfo['field'];
                $table = $relationInfo['table'];
                $ignoreName = false;
                if ( isset( $relationInfo['ignore_name'] ) )
                    $ignoreName = $relationInfo['ignore_name'];

                $matchText = '';
                if ( $ignoreName )
                {
                    $matchText = "WHERE LOWER( SUBSTR( $field, 0, " . strlen( $ignoreName ) . " ) ) != '$ignoreName'";
                }
                $sql = "SELECT COUNT( $field ) as count FROM $table $matchText";
                $array = $this->arrayQuery( $sql, array( 'column' => '0' ) );
                $count += $array[0];
            }
        }
        return $count;
    }

    /*!
      \reimp
    */
    function relationCount( $relationType = EZ_DB_RELATION_TABLE )
    {
        $count = false;
        $relationInfo = $this->relationInfo( $relationType );
        if ( !$relationInfo )
        {
            eZDebug::writeError( "Unsupported relation type '$relationType'", 'eZOracleDB::relationCount' );
            return false;
        }

        if ( $this->isConnected() )
        {
            $field = $relationInfo['field'];
            $table = $relationInfo['table'];
            $ignoreName = false;
            if ( isset( $relationInfo['ignore_name'] ) )
                $ignoreName = $relationInfo['ignore_name'];

            $matchText = '';
            if ( $ignoreName )
            {
                $matchText = "WHERE LOWER( SUBSTR( $field, 0, " . strlen( $ignoreName) . " ) ) != '$ignoreName'";
            }
            $sql = "SELECT COUNT( $field ) as count FROM $table $matchText";
            $array = $this->arrayQuery( $sql, array( 'column' => '0' ) );
            $count = $array[0];
        }
        return $count;
    }

    /*!
      \reimp
    */
    function relationList( $relationType = EZ_DB_RELATION_TABLE )
    {
        $count = false;
        $relationInfo = $this->relationInfo( $relationType );
        if ( !$relationInfo )
        {
            eZDebug::writeError( "Unsupported relation type '$relationType'", 'eZOracleDB::relationList' );
            return false;
        }

        $array = array();
        if ( $this->isConnected() )
        {
            $field = $relationInfo['field'];
            $table = $relationInfo['table'];
            $ignoreName = false;
            if ( isset( $relationInfo['ignore_name'] ) )
                $ignoreName = $relationInfo['ignore_name'];

            $matchText = '';
            if ( $ignoreName )
            {
                $matchText = "WHERE LOWER( SUBSTR( $field, 0, " . strlen( $ignoreName ) . " ) ) != '$ignoreName'";
            }
            $sql = "SELECT LOWER( $field ) AS $field FROM $table $matchText";
            $array = $this->arrayQuery( $sql, array( 'column' => '0' ) );
        }
        return $array;
    }

    /*!
     \reimp
    */
    function eZTableList()
    {
        $array = array();
        if ( $this->isConnected() )
        {
            foreach ( array( EZ_DB_RELATION_TABLE, EZ_DB_RELATION_SEQUENCE ) as $relationType )
            {
                $relationInfo = $this->relationInfo( $relationType );
                $field = $relationInfo['field'];
                $table = $relationInfo['table'];
                $ignoreName = false;
                if ( isset( $relationInfo['ignore_name'] ) )
                    $ignoreName = $relationInfo['ignore_name'];

                $matchText = '';
                if ( $ignoreName )
                {
                    $matchText = "WHERE LOWER( SUBSTR( $field, 0, " . strlen( $ignoreName ) . " ) ) != '$ignoreName'";
                }
                $sql = "SELECT LOWER( $field ) AS $field FROM $table $matchText";
                foreach ( $this->arrayQuery( $sql, array( 'column' => '0' ) ) as $result )
                {
                    $array[$result] = $relationType;
                }
            }
        }
        return $array;
    }

    /*!
     \reimp
    */
    function relationMatchRegexp( $relationType )
    {
        if ( $relationType == EZ_DB_RELATION_SEQUENCE )
            return "#^(ez|s_)#";
        else
            return "#^ez#";
    }

    /*!
      \reimp
    */
    function removeRelation( $relationName, $relationType )
    {
        $relationTypeName = $this->relationName( $relationType );
        if ( !$relationTypeName )
        {
            eZDebug::writeError( "Unsupported relation type '$relationType'", 'eZOracleDB::removeRelation' );
            return false;
        }

        if ( $this->isConnected() )
        {
            $sql = "DROP $relationTypeName $relationName";
            return $this->query( $sql );
        }
        return false;
    }

    /*!
      \reimp
    */
    function createTempTable( $createTableQuery = '' )
    {
        $createTableQuery = preg_replace( '#CREATE\s+TEMPORARY\s+TABLE#', 'CREATE GLOBAL TEMPORARY TABLE', $createTableQuery );
        $createTableQuery .= " ON COMMIT PRESERVE ROWS";
        $this->query( $createTableQuery );
    }

    /*!
      \reimp
    */
    function dropTempTable( $dropTableQuery = '' )
    {
        if( preg_match( '#DROP\s+TABLE\s+(\S+)#', $dropTableQuery, $matches ) )
            $this->query( 'TRUNCATE TABLE ' . $matches[1] );

        $this->query( $dropTableQuery );
    }


    /*!
     Sets PostgreSQL sequence values to the maximum values used in the corresponding columns.
    */
    function correctSequenceValues()
    {
        if ( $this->isConnected() )
        {
            $triggers = array();
            $rows = $this->arrayQuery( "SELECT table_name, trigger_body FROM user_triggers" );
            foreach ( $rows as $row )
            {
                $triggers[] = array( 'table_name'   => $row['table_name'],
                                     'trigger_body' => $row['trigger_body'] );
            }

            $seqs = array();
            foreach ( $triggers as $triggerParams )
            {
                $tableName   = $triggerParams['table_name'];
                $triggerBody = $triggerParams['trigger_body'];

                if ( preg_match( "/BEGIN\n" .
                                 " *IF :new.\S+ is null THEN\n" .
                                 " *SELECT (\S+).nextval INTO :new.(\S+) FROM dual;\n" .
                                 " *END IF;\n" .
                                 "END;/" , $triggerBody, $matches ) or
                     preg_match( "/BEGIN\n" .
                                 " *SELECT (\S+).nextval INTO :new.(\S+) FROM dual;\n" .
                                 "END;/", $triggerBody, $matches ) )
                {
                    $sequenceName = $matches[1];
                    $tableCol     = $matches[2];
                }
                else
                    continue;
                $seqs[$sequenceName] = array( $tableName, $tableCol );
            }

            foreach ( $seqs as $seq => $tableData )
            {
                list( $table, $col ) = $tableData;

                $rows = $this->arrayQuery( "SELECT MAX($col) AS max FROM $table" );
                $curColVal = (int)$rows[0]['max'];
                $rows = $this->arrayQuery( "SELECT $seq.nextval AS nextval FROM DUAL" );
                $curSeqVal = (int)$rows[0]['nextval'];
                $inc = $curColVal - $curSeqVal;

                if ( !$inc ) // no need to increment
                    continue;

                if ( !$this->query( "ALTER SEQUENCE $seq MINVALUE 0" ) )
                    return false;
                if ( !$this->query( "ALTER SEQUENCE $seq INCREMENT BY $inc" ) )
                    return false;
                $rows = $this->arrayQuery( "SELECT $seq.nextval AS nextval FROM DUAL" );
                $finalSeqVal = (int)$rows[0]['nextval'];
                if ( !$this->query( "ALTER SEQUENCE $seq INCREMENT BY 1" ) )
                    return false;

                if ( $finalSeqVal != $curColVal )
                {
                    eZDebug::writeError( "Failed updating sequence $seq, final sequence value '$finalSeqVal' is different than max value '$curColVal'" );
                    return false;
                }
            }

            return true;
        }
        return false;
    }

    /*!
     \reimp
     */
    function generateUniqueTempTableName( $pattern )
    {
        $maxTries = 10;
        do
        {
            $num = rand( 10000000, 99999999 );
            $tableName = strtoupper( str_replace( '%', $num, $pattern ) );
            $cntResult = $this->arrayQuery( "SELECT count(*) AS cnt FROM user_tables WHERE table_name='$tableName'" );
            $maxTries--;
        } while( $cntResult && $cntResult[0]['cnt'] > 0 && $maxTries > 0 );

        if ( $maxTries == 0 )
            eZDebug::writeError( "Tried to generate an uninque temp table name for $maxTries time with no luck" );

        return $tableName;
    }


    /*!
      Checks if the requested character set matches the one used in the database.

      \return \c true if it matches or \c false if it differs.
      \param[out] $currentCharset The charset that the database uses.
                                  will only be set if the match fails.
                                  Note: This will be specific to the database.

    */
    function checkCharset( $charset, &$currentCharset )
    {
        // If we don't have a database yet we shouldn't check it
        if ( !$this->DB )
            return true;

        include_once( 'lib/ezi18n/classes/ezcharsetinfo.php' );

        if ( is_array( $charset ) )
        {
            foreach ( $charset as $charsetItem )
                $realCharset[] = eZCharsetInfo::realCharsetCode( $charsetItem );
        }
        else
            $realCharset = eZCharsetInfo::realCharsetCode( $charset );

        return $this->checkCharsetPriv( $realCharset, $currentCharset );
    }

    /*!
     \private
    */
    function checkCharsetPriv( $charset, &$currentCharset )
    {
        $query = "SELECT  VALUE FROM NLS_DATABASE_PARAMETERS WHERE PARAMETER = 'NLS_CHARACTERSET'";
        $rows = $this->arrayQuery( $query );
        $currentCharset = $rows[0]['value'];

//        include_once( 'lib/ezi18n/classes/ezcharsetinfo.php' );
//        $currentCharset = eZCharsetInfo::realCharsetCode( $currentCharset );

        $key = array_search( $currentCharset, $this->CharsetsMap );
        $unmappedCurrentCharset = ( $key === false ) ? $currentCharset : $key;

        if ( is_array( $charset ) )
        {
            if ( in_array( $unmappedCurrentCharset, $charset ) )
            {
                return $unmappedCurrentCharset;
            }
        }
        else if ( $unmappedCurrentCharset == $charset )
        {
            return true;
        }
        return false;
    }



    /*!
      \reimp
    */
    function close()
    {
        OCILogOff( $this->DBConnection );
    }

    /// \privatesection
    /// Database connection
    var $DBConnection;
    var $Mode;
    var $BindVariableArray = array();

    var $CharsetsMap = array(
        'big5' => 'ZHT16BIG5',
        'euc-jp' => 'JA16EUC',
        'EUC-TW1' => 'ZHT32EUC',
        'gb2312' => 'ZHS16CGB231280',
        'ibm850' => 'WE38PC850',
        'ibm852' => 'EE8PC852',
        'ibm866' => 'RU8PC866',
        'iso-2022-cn2' => 'ISO2022-CN',
        'iso-2022-jp' => 'ISO2022-JP',
        'iso-2022-kr' => 'ISO2022-KR',
        'iso-8859-1' => 'WE8ISO8859P1',
        'iso-8859-2' => 'EE8ISO8859P2',
        'iso-8859-3' => 'SE8ISO8859P3',
        'iso-8859-4' => 'NEE8ISO8859P4',
        'iso-8859-5' => 'CL8ISO8859P5',
        'iso-8859-6' => 'AR8ISO8859P6',
        'iso-8859-7' => 'EL8ISO8859P7',
        'iso-8859-8' => 'IW8ISO8859P8',
        'iso-8859-9' => 'WE8ISO8859P9',
        'koi8-r' => 'CL8KOI8R',
        'ks_c_5601-1987' => 'KO16KSC5601',
        'shift_jis' => 'JA16SJIS',
        'TIS-620' => 'TH8TISASCII',
        'utf-8' => 'AL32UTF8',
        'windows-1250' => 'EE8MSWIN1250',
        'windows-1251' => 'CL8MSWIN1251',
        'windows-1252' => 'WE8MSWIN1252',
        'windows-1253' => 'EL8MSWIN1253',
        'windows-1254' => 'TR8MSWIN1254',
        'windows-1255' => 'IW8MSWIN1255',
        'windows-1256' => 'AR8MSWIN1256',
        'windows-1257' => 'BLT8MSWIN1257',
        'windows-1258' => 'VN8MSWIN1258',
        'windows-9361' => 'ZHS16GBK',
        'windows-949' => 'KO16MSWIN949',
        'windows-950' => 'ZHT16MSWIN950',
        );
}

?>
