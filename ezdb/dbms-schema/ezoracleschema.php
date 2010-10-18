<?php
//
// Created on: <29-Oct-2004 12:04:28 vs>
//
// Copyright (C) 1999-2010 eZ Systems as. All rights reserved.
//
// This source file is part of the eZ Publish (tm) Open Source Content
// Management System.
//
// This file may be distributed and/or modified under the terms of the
// "GNU General Public License" version 2 as published by the Free
// Software Foundation and appearing in the file LICENSE included in
// the packaging of this file.
//
// Licencees holding a valid "eZ Publish professional licence" version 2
// may use this file in accordance with the "eZ Publish professional licence"
// version 2 Agreement provided with the Software.
//
// This file is provided AS IS with NO WARRANTY OF ANY KIND, INCLUDING
// THE WARRANTY OF DESIGN, MERCHANTABILITY AND FITNESS FOR A PARTICULAR
// PURPOSE.
//
// The "eZ Publish professional licence" version 2 is available at
// http://ez.no/ez_publish/licences/professional/ and in the file
// PROFESSIONAL_LICENCE included in the packaging of this file.
// For pricing of this licence please contact us via e-mail to licence@ez.no.
// Further contact information is available at http://ez.no/company/contact/.
//
// The "GNU General Public License" (GPL) is available at
// http://www.gnu.org/copyleft/gpl.html.
//
// Contact licence@ez.no if any conditions of this licencing isn't clear to
// you.
//

//include_once( 'lib/ezdbschema/classes/ezdbschemainterface.php' );

class eZOracleSchema extends eZDBSchemaInterface
{

    function eZOracleSchema( $params )
    {
        $this->eZDBSchemaInterface( $params );
    }

    function schema( $params = array() )
    {
        $params = array_merge( array( 'meta_data' => false,
                                      'format' => 'generic',
                                      'sort_columns' => true,
                                      'sort_indexes' => true,
                                      'force_autoincrement_rebuild' => false ),
                               $params );

        $schema = array();

        if ( $this->Schema === false )
        {
            $autoIncrementColumns = $this->detectAutoIncrements( $params );
            $tableArray = $this->DBInstance->arrayQuery( "SELECT table_name FROM user_tables" );

            // prevent PHP warning in the cycle below
            if ( !is_array( $tableArray ) )
                $tableArray = array();

            foreach( $tableArray as $tableNameArray )
            {
                $table_name    = current( $tableNameArray );
                $table_name_lc = strtolower( $table_name );
                if ( !isset( $params['table_include'] ) or
                    ( is_array( $params['table_include'] ) and
                    ( in_array( $table_name_lc, $params['table_include'] ) or in_array( $table_name, $params['table_include'] ) ) ) )
                {
                    $schema_table['name']    = $table_name_lc;
                    $schema_table['fields']  = $this->fetchTableFields( $table_name, array_merge( $params, array( 'autoIncrementColumns' => $autoIncrementColumns ) ) );
                    $schema_table['indexes'] = $this->fetchTableIndexes( $table_name, $params );

                    $schema[$table_name_lc] = $schema_table;
                }
            }
            $this->transformSchema( $schema, $params['format'] == 'local' );
            ksort( $schema );
            $this->Schema = $schema;
        }
        else
        {
            $this->transformSchema( $this->Schema, $params['format'] == 'local' );
            $schema = $this->Schema;
        }

        return $schema;
    }

    /**
     * @access private
     * @param string $table name
     */
    function fetchTableFields( $table, $params )
    {
        // hack: we changed this function's prototype to be more compatible with other
        // db handlers. We expect an array but accept a string nonetheless
        $autoIncrementColumns = is_string( $params ) ? $params : $params['autoIncrementColumns'];

        $numericTypes = array( 'float', 'int' );                               // FIXME: const
        $oraNumericTypes = array( 'FLOAT', 'NUMBER' );                         // FIXME: const
        $oraStringTypes  = array( 'CHAR', 'VARCHAR2' );                        // FIXME: const
        $blobTypes    = array( 'tinytext', 'text', 'mediumtext', 'longtext' ); // FIXME: const
        $fields      = array();

        $query = "SELECT   a.column_name AS col_name, " .
                 "         decode (a.nullable, 'N', '1', 'Y', '0') AS not_null, " .
                 "         a.data_type AS col_type, " .
                 "         a.data_length AS col_size, " .
                 "         a.data_default AS default_val, " .
                 "         a.data_precision AS col_precision, " .
                 "         a.data_scale AS col_scale " .
                 "FROM     user_tab_columns a ".
                 "WHERE    upper(a.table_name) = '$table' " .
                 "ORDER BY a.column_id";

        $resultArray = $this->DBInstance->arrayQuery( $query );
        foreach( $resultArray as $row )
        {
            $colName     = strtolower( $row['col_name'] );
            $colLength   = $row['col_size'];
            $colType     = $row['col_type'];
            $colNotNull  = $row['not_null'];
            $colDefault  = $row['default_val'];

            $isAutoIncCol = isset( $autoIncrementColumns[strtolower( $table ) . '.' . $colName] );
            $field = array();

            if ( is_string( $colDefault ) )
            {
                // strip trailing spaces
                $colDefault = rtrim( $colDefault );
            }

            if ( $isAutoIncCol )
            {
                $field['type'] = 'auto_increment';
                $field['default'] = false;
            }
            elseif ( $colType == 'CLOB' )
            {
                // was: 'We do not want default for blobs.' ...
                $field['type']    = eZOracleSchema::parseType( $colType );
                if ( $colNotNull )
                    $field['not_null'] = $colNotNull;
                if ( $colDefault !== null && $colDefault !== 'NULL' )
                {
                    // strip leading and trailing quotes
                    $field['default'] = preg_replace( array( '/^\\\'/', '/\\\'$/' ), '', $colDefault );
                }
            }
            elseif ( in_array( $colType, $oraNumericTypes ) ) // number
            {
                if ( $colType != 'FLOAT' )
                    $field['length'] = eZOracleSchema::parseLength( $colType, $colLength, $row['col_precision'], $row['col_scale'] );
                $field['type']   = eZOracleSchema::parseType( $colType, isset( $field['length'] ) ? $field['length'] : '' );

                if ( $colNotNull )
                    $field['not_null'] = $colNotNull;

                if ( $colDefault !== null && $colDefault !== false )
                {
                    /// @todo: verify if changing NLS settings can give us back defaults with comma...
                    $field['default'] = /*(float)*/ $colDefault; // in ezdbschema defaults are always strings
                }
            }
            elseif ( in_array( $colType, $oraStringTypes ) ) // string
            {
                $field['length'] = (int) eZOracleSchema::parseLength( $colType, $colLength );
                $field['type']   = eZOracleSchema::parseType( $colType );

                if ( $colNotNull )
                    $field['not_null'] = $colNotNull;

                if ( $colDefault !== null && $colDefault !== 'NULL' )
                {
                    // strip leading and trailing quotes
                    $field['default'] = preg_replace( array( '/^\\\'/', '/\\\'$/' ), '', $colDefault );
                }
            }
            else // what else?
            {
                $field['length'] = eZOracleSchema::parseLength( $colType, $colLength, $row['col_precision'], $row['col_scale'] );
                $field['type']   = eZOracleSchema::parseType( $colType, $field['length'] );
                if ( $colNotNull )
                    $field['not_null'] = $colNotNull;

                if ( $colDefault !== null )
                {
                    // strip leading and trailing quotes
                    $field['default'] = preg_replace( array( '/^\\\'/', '/\\\'$/' ), '', $colDefault );
                }
                else
                    $field['default'] = false;
            }

            if( !array_key_exists( 'default', $field ) )
                $field['default'] = null;

            $fields[$colName] =& $field;
            unset( $field );
        }
        if ( $params['sort_columns'] )
        {
            ksort( $fields );
        }

        return $fields;
    }

    /**
     * @access private
     */
    function fetchTableIndexes( $table, $params=array() )
    {
        $indexes = array();
        $query = "SELECT ui.index_name AS name, " .
                 "       ui.index_type AS type, " .
                 "       decode( ui.uniqueness, 'NONUNIQUE', 0, 'UNIQUE', 1 ) AS is_unique, " .
                 "       uic.column_name AS col_name, " .
                 "       uic.column_position AS col_pos " .
                 "FROM user_indexes ui, user_ind_columns uic " .
                 "WHERE ui.index_name = uic.index_name AND ui.table_name = '$table'";
        $resultArray = $this->DBInstance->arrayQuery( $query );

        foreach( $resultArray as $row )
        {
            $idxName = strtolower( $row['name'] );
            if ( strpos( $idxName, 'sys_' ) === 0 )
            {
                $idxType = 'primary';
                $idxName = "PRIMARY";
            }
            else
            {
                $idxType = ( (int) $row['is_unique'] ) ? 'unique' : 'non-unique';
            }

            $indexes[$idxName]['type']     = $idxType;
            $indexes[$idxName]['fields'][$row['col_pos'] - 1] = strtolower( $row['col_name'] );
        }
        if ( $params['sort_indexes'] )
        {
            ksort( $indexes );
        }

        return $indexes;
    }

    /**
     * @access private
     */
    function detectAutoIncrements( $params )
    {
        $autoIncColumns = array();
        $query = "SELECT table_name, trigger_name, trigger_body, status FROM user_triggers WHERE table_name LIKE 'EZ%'";
        $resultArray = $this->DBInstance->arrayQuery( $query );
        foreach ( $resultArray as $row )
        {
            $triggerBody =& $row['trigger_body'];
            if ( !preg_match( '/SELECT\s+(\w+).nextval\s+INTO\s+:new.(\w+)\s+FROM\s+dual/', $triggerBody, $matches ) )
                continue;

            $seqName =& $matches[1];
            $colName =& $matches[2];

            if ( isset( $params['force_autoincrement_rebuild'] ) && $params['force_autoincrement_rebuild'] )
            {
                // check that the sequence exists. If it does not, trigger cannot be enabled!
                // the column is thus technically an autoincrement, but it can never work...
                $query = "SELECT COUNT(*) AS ok FROM user_sequences WHERE sequence_name = '" . strtoupper( $seqName ) . "'";
                $resultArray2 = $this->DBInstance->arrayQuery( $query );
                if ( $resultArray2[0]['ok'] != 1 )
                {
                    continue;
                }
            }

            $autoIncColumns[strtolower( $row['table_name'] ) . '.' . strtolower( $colName )] = true;
        }

        return $autoIncColumns;
    }

    /**
     * @access private
     */
    function parseType( $type, $length = '' )
    {
        switch ( $type )
        {
        case 'NUMBER':
            if ( strpos( $length, ',' ) !== false )
            {
                return 'decimal';
            }
            return 'int';
        case 'FLOAT':
            return 'float';
        case 'VARCHAR2':
            return 'varchar';
        case 'CLOB':
            return 'longtext';
        case 'CHAR':
            return 'char';
        default:
            return $type;
        }
        return 'unknown';
    }

    /**
     * @access private
     */
    function parseLength( $oraType, $oraLength, $oraPrecision = '', $oraScale = '' )
    {
        // for NUMBER, we say default lenght is 11,0 unless there is more info in the db
        if ( $oraType == 'NUMBER' )
        {
            $length = 11;
            if ( $oraPrecision )
            {
                $length = $oraPrecision;
                if ( $oraScale )
                {
                    $length = $length . ',' . $oraScale;
                }
            }

            return $length;
        }
        return $oraLength;
    }

    /**
     * @access private
     */
    function generateAddIndexSql( $table_name, $index_name, $def, $params, $withClosure = true )
    {
        switch ( $def['type'] )
        {
            case 'primary':
            {
                $sql = "ALTER TABLE $table_name ADD PRIMARY KEY ";
            } break;

            case 'non-unique':
            {
                $sql = "CREATE INDEX $index_name ON $table_name ";
            } break;

            case 'unique':
            {
                $sql = "CREATE UNIQUE INDEX $index_name ON $table_name ";
            } break;

            default:
            {
                eZDebug::writeError( "Unknown index type: " . $def['type'] );
                return '';
            } break;
        }

        //$sql .= '( ' . join ( ', ', $def['fields'] ) . ' )';
        $fieldNames = array();
        foreach ( $def['fields'] as $fieldDef )
        {
            if ( is_array( $fieldDef ) )
            {
                $fieldNames[] = $fieldDef['name'];
            }
            else
            {
                $fieldNames[] = $fieldDef;
            }
        }
        $sql .= '( ' . join ( ', ', $fieldNames ) . ' )';

        return $sql . ( $withClosure ? ";\n" : '' );
    }

    /**
     * @access private
     */
    function generateDropIndexSql( $table_name, $index_name, $def )
    {
        if ( $def['type'] == 'primary' )
        {
            $sql = "ALTER TABLE $table_name DROP PRIMARY KEY";
        }
        else
        {
            $sql = "DROP INDEX $index_name";
        }
        return $sql . ";\n";
    }

    /**
     * @return string Oracle datatype matching the given MySQL type
     */
    function getOracleType( $mysqlType )
    {
        $rslt = $mysqlType;
        $rslt = preg_replace( '/varchar/', 'VARCHAR2', $rslt );
        $rslt = preg_replace( '/char/', 'CHAR', $rslt );
        $rslt = preg_replace( '/int(eger)?(\([0-9]+\))?( +unsigned)?/', 'INTEGER', $rslt );
        $rslt = preg_replace( '/^(medium|long)?text$/', 'CLOB', $rslt );
        $rslt = preg_replace( '/^double$/', 'DOUBLE PRECISION', $rslt );
        $rslt = preg_replace( '/^float$/', 'FLOAT', $rslt );
        $rslt = preg_replace( '/decimal/', 'NUMBER', $rslt );
        return $rslt;
    }

    /**
     * @access private
     */
    function generateFieldDef( $field_name, $def, $optionsToDump = array( 'default', 'not_null' ) )
    {
        $oraNumericTypes = array( 'INTEGER', 'FLOAT', 'DOUBLE PRECISION', 'NUMBER' ); // const

        $sql_def = $field_name . ' ';

        $oraType = eZOracleSchema::getOracleType( $def['type'] );
        $isNumericField = in_array( $oraType, $oraNumericTypes );

        if ( $def['type'] != 'auto_increment' )
        {
            // type
            $sql_def .= $oraType;
            // note: mysql DECIMAL(X,Y) we convert to NUMBER(X,Y) while keeping default val unquoted
            // NB: the following code is not entirely correct, as it prevents us to generate INTEGER(x),
            //     but it has always been like this in ezoracle, so we do not change (yet)
            if ( isset( $def['length'] ) && ( !$isNumericField || $oraType == 'NUMBER' ) )
                $sql_def .= "({$def['length']})";

            // default
            if ( in_array( 'default', $optionsToDump ) /*&& array_key_exists( 'default', $def )*/ )
            {
                if ( isset( $def['default'] ) && $def['default'] !== false ) // not null, not false
                {
                    $quote = $isNumericField ? '' : '\'';
                    $sql_def .= " DEFAULT $quote{$def['default']}$quote";
                }
                else
                {
                    if ( in_array( 'force_default', $optionsToDump ) )
                    {
                        // reset to NULL the default value
                        $sql_def .= " DEFAULT NULL";
                    }
                }
            }

            // not null
            if ( in_array( 'not_null', $optionsToDump ) )
            {
                if ( isset( $def['not_null'] ) && $def['not_null'] )
                {
                    $sql_def .= ' NOT NULL';
                }
                else
                {
                    if ( in_array( 'force_null', $optionsToDump ) )
                    {
                        // reset to NULL the default value
                        $sql_def .= " NULL";
                    }
                }

            }

        }
        else
        {
            $sql_def .= 'INTEGER NOT NULL';
        }
        return $sql_def;
    }

    /**
     * @access private
     */
    function generateAddFieldSql( $table_name, $field_name, $def, $params )
    {
        $sql = "ALTER TABLE $table_name ADD ";
        $sql .= eZOracleSchema::generateFieldDef ( $field_name, $def );

        return $sql . ";\n";
    }

    /**
     * @access private
     */
    function generateAlterFieldSql( $table_name, $field_name, $def, $params )
    {
        // HACK! since ezdbschemachecker is not able to overcome the fact that
        // in oracle we store every INTEGER with a length of 38 instead of 11
        // (and we do not want to change that for historical compatibility)
        // we fix it here: if the field is int and all that is changed is its length
        // and the new one is shorter than 38, we ignore it.
        // NB: this might be changed into a fix in TransformSchema instead...
        if ( eZOracleSchema::getOracleType( $def['type'] ) == 'INTEGER' && $params['different-options'] == array( 'length' ) && $def['length'] < 38 )
        {
            return '';
        }

        // make sure that if a default null is specified in the diff, we reset it
        $params['different-options'][] = 'force_default';
        // also that we reset nullability
        $params['different-options'][] = 'force_null';

        $sql = '';

        if ( eZOracleSchema::getOracleType( $def['type'] ) == 'CLOB' )
        {
            // though case... oracle cannot change a varchar2 to a clob directly
            // but since 9i it can convert a long to a clob!
            // So we convert to long first then to lob
            // NB: using dbms_redefinition plsql package is probably much better
            // for performances and in clusteed/partitioned setups, but we go
            // for the low-hanging fruit (no need to check if the package is
            // compiled/available
            // see http://download.oracle.com/docs/cd/B10500_01/appdev.920/a96591/toc.htm
            // or http://download.oracle.com/docs/cd/B10500_01/appdev.920/a96591/adl08lon.htm#104177
            // in short: we need to rebuild all table indexes after switching type
            if ( in_array( 'type', $params['different-options'] ) )
            {
                $sql .= "ALTER TABLE $table_name MODIFY (". $field_name . " NULL);\n" .
                        "ALTER TABLE $table_name MODIFY (". $field_name . " LONG);\n" .
                        "ALTER TABLE $table_name MODIFY (" . $field_name . " CLOB);\n" .
                        "DECLARE\n" .
                        "  CURSOR idx_cur IS SELECT index_name FROM user_indexes WHERE table_name='" . strtoupper( $table_name ) . "' AND index_type <> 'LOB';\n" .
                        "BEGIN\n" .
                        "  FOR idx_cur_rec IN idx_cur LOOP\n" .
                        "    EXECUTE IMMEDIATE 'ALTER INDEX ' || idx_cur_rec.index_name || ' REBUILD';\n" .
                        "  END LOOP;\n" .
                        "END;\n/\n";
            }

            // easy part: we can use alter table for changing nullability or default
            // there's no length for blobs, that leaves us with a non-type change
            if ( $params['different-options'] != array( 'type' ) )
            {
                $sql .= "ALTER TABLE $table_name MODIFY (". str_replace( ' CLOB', '',  eZOracleSchema::generateFieldDef ( $field_name, $def, $params['different-options'] ) ). ");\n";
            }

            return $sql;
        }

        // this field was not recognized any more as auto_increment: it must have
        // lost its trigger or its sequence...
        // unluckily there is no 'create or replace sequence' statement
        if ( $def['type'] == 'auto_increment' && in_array( 'type', $params['different-options'] ) )
        {
            $defs = $this->generateAutoIncrement( $table_name, $field_name, $def );
            $seq_name = str_replace ( array( 'CREATE SEQUENCE ', ";\n" ), '', $defs['sequences'][0] );
            $sql = "\n" .
                "DECLARE\n" .
                "  maxval INTEGER;\n" .
                "  obj_exists EXCEPTION;\n" .
                "  PRAGMA EXCEPTION_INIT(obj_exists, -955);\n" .
                "BEGIN\n" .
                "  SELECT NVL(MAX($field_name), 0) INTO maxval FROM $table_name;\n" .
                "  maxval := maxval + 1;\n" . // takes care of 0 elements table too
                "  EXECUTE IMMEDIATE 'CREATE SEQUENCE $seq_name MINVALUE ' || maxval;\n" .
                "EXCEPTION WHEN obj_exists THEN\n" .
                "  NULL;\n" .
                "END;\n" .
                "/\n" . $defs['triggers'][0];
            // if there is some other difference than the presence of trigger/sequence, go on...
            // nb: nullable cannot be different, if we always put autoincrements on pks
            if ( $params['different-options'] == array( 'type' ) )
            {
                return $sql;
            }
        }

        $sql .= "ALTER TABLE $table_name MODIFY (";
        $sql .= eZOracleSchema::generateFieldDef ( $field_name, $def, $params['different-options'] );
        $sql .= ")";

        return $sql . ";\n";
    }


    /**
     * @access private
     *
     * Cuts given identifier to the specified length providing uniqueness of all
     * shortened identifiers.
     */
    function shorten( $identifier, $length = 30 )
    {
        static $cnt = 1;
        if( strlen( $identifier ) <= $length )
            return $identifier;
        return substr( $identifier, 0, $length-5 ) . sprintf( "%05d", $cnt++ );
    }

    /**
     * @access private
     * @return array An array with trigger and sequence for auto increment field
     * <code>
     * array( 'sequences' => array( 'CREATE SEQUENCE...' ),
     *        'triggers' => array( 'CREATE OR REPLACE TRIGGER...' ) );
     * </code>
     */
    function generateAutoIncrement( $table_name, $field_name, $field_def, $params=array(), $withClosure = true )
    {
        $seqName  = preg_replace( '/^ez/', 's_', $table_name );
        if ( $seqName == $table_name )
        {
            // table name does not start with 'ez': an extension, most likely
            $seqName = substr( 'se_' . $seqName, 0, 30 );
        }
        $trigName = eZOracleSchema::shorten( $table_name . '_' . $field_name, 30-3 ) .'_tr';
        $sqlSeq = "CREATE SEQUENCE $seqName";
        if ( $withClosure )
            $sqlSeq .= ";\n";
        // be kind to people that unwittingly save this file with CR-LF line endings
        // (PLSQL does not like that at all, so the trigger would not compile any more)
        $sqlTrig = "CREATE OR REPLACE TRIGGER $trigName
BEFORE INSERT ON $table_name FOR EACH ROW WHEN (new.$field_name IS NULL)
BEGIN\n".
"  SELECT $seqName.nextval INTO :new.$field_name FROM dual;\n".
"END;";
       if ( $withClosure )
            $sqlTrig .= "\n/\n";
        return array( 'sequences' => array( $sqlSeq ),
                      'triggers' => array( $sqlTrig ) );
    }

    function generateTableSchema( $tableName, $table_def, $params )
    {
        $arrays = $this->generateTableSQL( $tableName, $table_def, $params, true );
        return ( join( "\n\n", $arrays['sequences'] ) . "\n" .
                 join( "\n\n", $arrays['tables'] ) . "\n" .
                 join( "\n\n", $arrays['triggers'] ) . "\n" .
                 join( "\n\n", $arrays['indexes'] ) . "\n" .
                 join( "\n\n", $arrays['constraints'] ) . "\n" );
    }

    function generateTableSQLList( $tableName, $table, $params, $separateTypes )
    {
        $arrays = $this->generateTableSQL( $tableName, $table, $params, false );

        // If we have to separate the types the current array is sufficient
        if ( $separateTypes )
            return $arrays;
        return array_merge( $arrays['sequences'],
                            $arrays['tables'],
                            $arrays['triggers'],
                            $arrays['indexes'],
                            $arrays['constraints'] );
    }
    /**
     * @access private
     */
    function generateTableSQL( $table, $table_def, $params, $withClosure = true )
    {
        $sql_fields = array();
        $sqlAutoinc = false;

        $sqlList = array( 'sequences' => array(),
                          'tables' => array(),
                          'triggers' => array(),
                          'indexes' => array(),
                          'constraints' => array() );

        $sql = '';

        // dump table
        $sql .= "CREATE TABLE $table (\n";
        foreach ( $table_def['fields'] as $field_name => $field_def )
        {
            $sqlFields[] = "  ". eZOracleSchema::generateFieldDef( $field_name, $field_def );

            if ( $field_def['type'] == 'auto_increment' )
                $sqlAutoinc = eZOracleSchema::generateAutoIncrement( $table, $field_name, $field_def, $params, $withClosure );
        }

        // dump CREATE SEQUENCE and CREATE TRIGGER clauses implementing auto_increment feature
        if ( $sqlAutoinc )
        {
            $sqlList['sequences'] = array_merge( $sqlList['sequences'], $sqlAutoinc['sequences'] );
            $sqlList['triggers']  = array_merge( $sqlList['triggers'],  $sqlAutoinc['triggers']  );
        }

        // dump indexes
        foreach ( $table_def['indexes'] as $index_name => $index_def )
        {
            if ( ( $index_def['type'] == 'primary' )  )
            {
                //$sqlFields[] = "  PRIMARY KEY ( " . implode( ',', $index_def['fields'] ) . " )";
                // NB: it might be better to add a param to generateAddIndexSql rather than rely on its output being fixed...
                $sqlFields[] = str_replace( 'ALTER TABLE  ADD', ' ', eZOracleSchema::generateAddIndexSql( '', $index_def, $index_def, $params, false ) );
            }
            else
            {
                $sqlList['indexes'][] = eZOracleSchema::generateAddIndexSql( $table, $index_name, $index_def, $params, $withClosure );
            }
        }

        // finish dumping table schema
        $sql .= join ( ",\n", $sqlFields ) . "\n)" . ( $withClosure ? ";\n" : '' );
        $sqlList['tables'][] = $sql;

        return $sqlList;
    }

    /**
     * @access private
     */
    function generateDropTable( $table )
    {
        return "DROP TABLE $table;\n";
    }

    /**
    * Take care: this is NOT a fully reversible operation in many cases...
    * 1. we transform default === false into default === null for text cols,
    *    (char, varchar, clob), as there is no FALSE value in Oracle.
    * 2. also default '' to default
    * When we carry out such operations, we store info about the changes in extra
    * fields in the schema def, so that if we later want to revert using the
    * same schema object, we're able to do it - this won't work when eg. creating
    * the schema def starting out of an oracle db...
    */
    function transformSchema( &$schema, /* bool */ $toLocal )
    {
        if ( !eZDBSchemaInterface::transformSchema( $schema, $toLocal ) )
            return false;

        foreach ( $schema as $tableName => $tableSchema )
        {
            if ( $tableName == '_info' )
                continue;

            if ( !$toLocal )
            {
                // change name of primary keys from sys_* to PRIMARY
                $tmpNewIndexes = array();
                foreach ( $tableSchema['indexes'] as $idxName => $idxSchema )
                {
                    if ( strpos( $idxName, 'sys_' ) === 0 )
                        $tmpNewIndexes['PRIMARY'] =& $tableSchema['indexes'][$idxName];
                    else
                        $tmpNewIndexes[$idxName] =& $tableSchema['indexes'][$idxName];
                    eZDebugSetting::writeDebug( 'lib-dbschema-transformation', '',
                                                "renamed index $tableName.$idxName => PRIMARY" );

                    // restore the mysql-specific stuff, if it is found
                    if ( isset( $tmpNewIndexes[$idxName]['_original'] ) && isset( $tmpNewIndexes[$idxName]['_original']['fields'] ) )
                    {
                        foreach ( $tmpNewIndexes[$idxName]['_original']['fields'] as $field => $desc )
                        {
                            if ( is_string( $tmpNewIndexes[$idxName]['fields'][$field] ) )
                            {
                                $tmpNewIndexes[$idxName]['fields'][$field] = array ( 'name' => $tmpNewIndexes[$idxName]['fields'][$field] );
                            }
                            foreach( $desc as $key => $val )
                            {
                                $tmpNewIndexes[$idxName]['fields'][$field][$key] = $val;
                            }
                        }
                    }
                }
                $schema[$tableName]['indexes'] =& $tmpNewIndexes;
                unset( $tmpNewIndexes );
                ksort( $schema[$tableName]['indexes'] );


                foreach ( $tableSchema['fields'] as $fieldName => $fieldSchema )
                {
                    if ( isset( $tableSchema['_original'] ) && isset( $tableSchema['_original']['fields'][$fieldName] ) && isset( $tableSchema['_original']['fields'][$fieldName]['default'] ) )
                    {
                        $schema[$tableName]['fields'][$fieldName]['default'] = $tableSchema['_original']['fields'][$fieldName]['default'];
                    }

                    // always fix default values for CLOB fields: they should be false instead of null
                    // NB: this is a weird convention in eZP standard dba files that we should fix...
                    if ( $fieldSchema['type'] == 'longtext' && $fieldSchema['default'] === null )
                    {
                        $schema[$tableName]['fields'][$fieldName]['default'] = false;
                        eZDebugSetting::writeDebug( 'lib-dbschema-transformation', '',
                                                    "changed default value for $tableName.$fieldName from null to false" );

                    }
                }
            }
            else // if ( $toLocal )
            {
                $tmpNewIndexes = array();
                foreach ( $tableSchema['indexes'] as $idxName => $idxSchema )
                {
                    if ( strlen( $idxName ) > 30 )
                    {
                        $newIdxName = eZOracleSchema::shorten( $idxName, 28 ) . '_i';
                        $tmpNewIndexes[$newIdxName] =& $tableSchema['indexes'][$idxName];
                        eZDebugSetting::writeDebug( 'lib-dbschema-transformation', '',
                                                    "shortened index name $tableName.$idxName to $newIdxName" );
                    }
                    else
                        $tmpNewIndexes[$idxName] =& $tableSchema['indexes'][$idxName];

                    // remove the mysql-specific stuff, store it for later if we want to go back
                    foreach ( $tmpNewIndexes[$idxName]['fields'] as $field => $desc )
                    {
                        if ( is_array( $desc ) )
                        {
                            foreach( $desc as $key => $val )
                            {
                                if ( strpos( $key, 'mysql:' ) === 0 )
                                {
                                    $tmpNewIndexes[$idxName]['_original']['fields'][$field][$key] = $val;
                                    unset( $tmpNewIndexes[$idxName]['fields'][$field][$key] );
                                    eZDebugSetting::writeDebug( 'lib-dbschema-transformation', '',
                                                    "removed constraint $key from index $idxName, field $field" );
                                }
                                // if only 'name' field is left, rewrite array so that is easily checkable for diffs
                                if ( count( $tmpNewIndexes[$idxName]['fields'][$field] ) == 1 && isset( $tmpNewIndexes[$idxName]['fields'][$field]['name'] ) )
                                {
                                     $tmpNewIndexes[$idxName]['fields'][$field] = $tmpNewIndexes[$idxName]['fields'][$field]['name'];
                                }
                            }
                        }
                    }
                }
                $schema[$tableName]['indexes'] =& $tmpNewIndexes;
                unset( $tmpNewIndexes );
                ksort( $schema[$tableName]['indexes'] );

                foreach ( $tableSchema['fields'] as $fieldName => $fieldSchema )
                {
                    if ( ( $fieldSchema['type'] == 'longtext' && $fieldSchema['default'] === false ) ||
                         ( ( $fieldSchema['type'] == 'varchar' || $fieldSchema['type'] == 'char' ) && ( $fieldSchema['default'] === '' || $fieldSchema['default'] === false ) ) )
                    {
                        $schema[$tableName]['_original']['fields'][$fieldName]['default'] = $schema[$tableName]['fields'][$fieldName]['default'];
                        $schema[$tableName]['fields'][$fieldName]['default'] = null;
                        eZDebugSetting::writeDebug( 'lib-dbschema-transformation', '',
                                                    "changed default value for $tableName.$fieldName from null to false" );

                    }
                }
            }
        }

        return true;
    }

    function escapeSQLString( $value )
    {
        return str_replace( "'", "''", $value );
    }

    function schemaType()
    {
        return 'oracle';
    }

    function schemaName()
    {
        return 'Oracle';
    }

    /**
       An array with keywords that are reserved by Oracle.
       From: http://oracle.su/appdev.111/b31231/appb.htm
       @return array
    */
    static function reservedKeywordList()
    {
        return array(
            'access ',
            'else ',
            'modify ',
            'start',
            'add ',
            'exclusive ',
            'noaudit ',
            'select',
            'all ',
            'exists ',
            'nocompress ',
            'session',
            'alter ',
            'file ',
            'not ',
            'set',
            'and ',
            'float ',
            'notfound ',
            'share',
            'any ',
            'for ',
            'nowait ',
            'size',
            'arraylen ',
            'from ',
            'null ',
            'smallint',
            'as ',
            'grant ',
            'number ',
            'sqlbuf',
            'asc ',
            'group ',
            'of ',
            'successful',
            'audit ',
            'having ',
            'offline ',
            'synonym',
            'between ',
            'identified ',
            'on ',
            'sysdate',
            'by ',
            'immediate ',
            'online ',
            'table',
            'char ',
            'in ',
            'option ',
            'then',
            'check ',
            'increment ',
            'or ',
            'to',
            'cluster ',
            'index ',
            'order ',
            'trigger',
            'column ',
            'initial ',
            'pctfree ',
            'uid',
            'comment ',
            'insert ',
            'prior ',
            'union',
            'compress ',

            'integer ',
            'privileges ',
            'unique',
            'connect ',
            'intersect ',
            'public ',
            'update',
            'create ',
            'into ',
            'raw ',
            'user',
            'current ',
            'is ',
            'rename ',
            'validate',
            'date ',
            'level ',
            'resource ',
            'values',
            'decimal ',
            'like ',
            'revoke ',
            'varchar',
            'default ',
            'lock ',
            'row ',
            'varchar2',
            'delete ',
            'long ',
            'rowid ',
            'view',
            'desc ',
            'maxextents ',
            'rowlabel ',
            'whenever',
            'distinct ',
            'minus ',
            'rownum ',
            'where',
            'drop ',
            'mode ',
            'rows ',
            'with'
        );
    }

}
?>
