<?php
//
// Created on: <29-Oct-2004 12:04:28 vs>
//
// Copyright (C) 1999-2008 eZ systems as. All rights reserved.
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

    /*!
     \reimp
     Constructor

     \param db instance
    */
    function eZOracleSchema( $params )
    {
        $this->eZDBSchemaInterface( $params );
    }

    /*!
     \reimp
    */
    function schema( $params = array() )
    {
        $params = array_merge( array( 'meta_data' => false,
                                      'format' => 'generic' ),
                               $params );

        $schema = array();

        if ( $this->Schema === false )
        {
            $autoIncrementColumns = $this->detectAutoIncrements();
            $tableArray = $this->DBInstance->arrayQuery( "SELECT table_name FROM user_tables" );

            // prevent PHP warning in the cycle below
            if ( !is_array( $tableArray ) )
                $tableArray = array();

            foreach( $tableArray as $tableNameArray )
            {
                $table_name    = current( $tableNameArray );
                $table_name_lc = strtolower( $table_name );
                $schema_table['name']    = $table_name_lc;
                $schema_table['fields']  = $this->fetchTableFields( $table_name, $autoIncrementColumns );
                $schema_table['indexes'] = $this->fetchTableIndexes( $table_name );

                $schema[$table_name_lc] = $schema_table;
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

    /*!
     \private

     \param table name
     */
    function fetchTableFields( $table, $autoIncrementColumns )
    {
        $numericTypes = array( 'float', 'int' );                               // FIXME: const
        $oraNumericTypes = array( 'FLOAT', 'NUMBER' );                         // FIXME: const
        $oraStringTypes  = array( 'CHAR', 'VARCHAR2' );                        // FIXME: const
        $blobTypes    = array( 'tinytext', 'text', 'mediumtext', 'longtext' ); // FIXME: const
        $fields      = array();

        $query = "SELECT   a.column_name AS col_name, " .
                 "         decode (a.nullable, 'N', 1, 'Y', 0) AS not_null, " .
                 "         a.data_type AS col_type, " .
                 "         a.data_length AS col_size, " .
                 "         a.data_default AS default_val " .
                 "FROM     user_tab_columns a ".
                 "WHERE    upper(a.table_name) = '$table' " .
                 "ORDER BY a.column_id";

        $resultArray = $this->DBInstance->arrayQuery( $query );
        foreach( $resultArray as $row )
        {
            $colName     = strtolower( $row['col_name'] );
            $colLength   = $row['col_size'];
            $colType     = $row['col_type'];
            $colNotNull  = (int) $row['not_null'];
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
                // We do not want default for blobs.
                $field['type']    = eZOracleSchema::parseType( $colType );
                if ( $colNotNull )
                    $field['not_null'] = (string) $colNotNull;
                $field['default'] = false;
            }
            elseif ( in_array( $colType, $oraNumericTypes ) ) // number
            {
                if ( $colType != 'FLOAT' )
                    $field['length'] = (int) eZOracleSchema::parseLength( $colType, $colLength );
                $field['type']   = eZOracleSchema::parseType( $colType );

                if ( $colNotNull )
                    $field['not_null'] = (string) $colNotNull;

                if ( $colDefault !== null && $colDefault !== false )
                {
                    $field['default'] = (int) $colDefault;
                }
            }
            elseif ( in_array( $colType, $oraStringTypes ) ) // string
            {
                $field['length'] = (int) eZOracleSchema::parseLength( $colType, $colLength );
                $field['type']   = eZOracleSchema::parseType( $colType );

                if ( $colNotNull )
                    $field['not_null'] = (string) $colNotNull;

                if ( $colDefault !== null )
                {
                    // strip leading and trailing quotes
                    $field['default'] = preg_replace( array( '/^\\\'/', '/\\\'$/' ), '', $colDefault );
                }
            }
            else // what else?
            {
                $field['length'] = (int) eZOracleSchema::parseLength( $colType, $colLength );
                $field['type']   = eZOracleSchema::parseType( $colType );
                if ( $colNotNull )
                    $field['not_null'] = (string) $colNotNull;

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
        ksort( $fields );

        return $fields;
    }

    /*!
     * \private
     */
    function fetchTableIndexes( $table )
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
        ksort( $indexes );

        return $indexes;
    }

    /*!
     * \private
     */
    function detectAutoIncrements()
    {
        $autoIncColumns = array();
        $query = "SELECT table_name, trigger_name, trigger_body FROM user_triggers WHERE table_name LIKE 'EZ%'";
        $resultArray = $this->DBInstance->arrayQuery( $query );
        foreach ( $resultArray as $row )
        {
            $triggerBody =& $row['trigger_body'];
            if ( !preg_match( '/SELECT\s+(\w+).nextval\s+INTO\s+:new.(\w+)\s+FROM\s+dual/', $triggerBody, $matches ) )
                continue;

            $seqName =& $matches[1];
            $colName =& $matches[2];
            $autoIncColumns[strtolower( $row['table_name'] ) . '.' . strtolower( $colName )] = true;
        }

        return $autoIncColumns;
    }

    /*!
     * \private
     */
    function parseType( $type )
    {
        switch ( $type )
        {
        case 'NUMBER':
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

    /*!
     * \private
     */
    function parseLength( $oraType, $oraLength )
    {
        if ( $oraType == 'NUMBER' )
            return 11;
        return $oraLength;
    }

    /*!
     * \private
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

        $sql .= '( ' . join ( ', ', $def['fields'] ) . ' )';

        return $sql . ( $withClosure ? ";\n" : '' );
    }

    /*!
     * \private
     */
    function generateDropIndexSql( $table_name, $index_name, $def )
    {
        $sql = "ALTER TABLE $table_name DROP ";

        if ( $def['type'] == 'primary' )
        {
            $sql .= 'PRIMARY KEY';
        }
        else
        {
            $sql .= "INDEX $index_name";
        }
        return $sql . ";\n";
    }

    /*!
    \return Oracle datatype matching the given MySQL type
    */
    function getOracleType( $mysqlType )
    {
        $rslt = $mysqlType;
        $rslt = ereg_replace( 'varchar', 'VARCHAR2', $rslt );
        $rslt = ereg_replace( 'char', 'CHAR', $rslt );
        $rslt = ereg_replace( 'int(eger)?(\([0-9]+\))?( +unsigned)?', 'INTEGER', $rslt );
        $rslt = ereg_replace( '^(medium|long)?text$', 'CLOB', $rslt );
        $rslt = ereg_replace( '^double$', 'DOUBLE PRECISION', $rslt );
        $rslt = ereg_replace( '^float$', 'FLOAT', $rslt );
        $rslt = ereg_replace( 'decimal', 'NUMBER', $rslt );
        return $rslt;
    }

    /*!
     * \private
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
            if ( isset( $def['length'] ) && ( !$isNumericField || $oraType == 'NUMBER' ) )
                $sql_def .= "({$def['length']})";

            // default
            if ( in_array( 'default', $optionsToDump ) && array_key_exists( 'default', $def ) )
            {
                if ( isset( $def['default'] ) && $def['default'] !== false )
                {
                    $quote = $isNumericField ? '' : '\'';
                    $sql_def .= " DEFAULT $quote{$def['default']}$quote";
                }
            }

            // not null
            if ( in_array( 'not_null', $optionsToDump ) && isset( $def['not_null'] ) && $def['not_null'] )
            {
                $sql_def .= ' NOT NULL';
            }

        }
        else
        {
            $sql_def .= 'INTEGER NOT NULL';
        }
        return $sql_def;
    }

    /*!
     * \private
     */
    function generateAddFieldSql( $table_name, $field_name, $def )
    {
        $sql = "ALTER TABLE $table_name ADD ";
        $sql .= eZOracleSchema::generateFieldDef ( $field_name, $def );

        return $sql . ";\n";
    }

    /*!
     * \private
     */
    function generateAlterFieldSql( $table_name, $field_name, $def, $params )
    {
        $sql = "ALTER TABLE $table_name MODIFY (";
        $sql .= eZOracleSchema::generateFieldDef ( $field_name, $def, $params['different-options'] );
        $sql .= ")";

        return $sql . ";\n";
    }


    /*!
    * \private
    *
    * Cuts given identifier to the specified length providing uniqueness of all
    * shortened identifiers.
    */
    function shorten( $identifier, $length = 30 )
    {
        static $cnt = 1;
        if( strlen( $identifier ) <= $length)
            return $identifier;
        return substr( $identifier, 0, $length-5 ) . sprintf( "%05d", $cnt++ );
    }

    /*!
     \private
     \return An array with trigger and sequence for auto increment field

     \code
     array( 'sequences' => array( 'CREATE SEQUENCE...' ),
            'triggers' => array( 'CREATE OR REPLACE TRIGGER...' ) );
     \encode
    */
    function generateAutoIncrement( $table_name, $field_name, $field_def, $params, $withClosure = true )
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

    /*!
     \reimp
    */
    function generateTableSchema( $tableName, $table_def )
    {
        $arrays = $this->generateTableSQL( $tableName, $table_def, $params = null, true );
        return ( join( "\n\n", $arrays['sequences'] ) . "\n" .
                 join( "\n\n", $arrays['tables'] ) . "\n" .
                 join( "\n\n", $arrays['triggers'] ) . "\n" .
                 join( "\n\n", $arrays['indexes'] ) . "\n" .
                 join( "\n\n", $arrays['constraints'] ) . "\n" );
    }

    /*!
     \reimp
    */
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
    /*!
     \private
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
                $sqlFields[] = "  PRIMARY KEY ( " . implode( ',', $index_def['fields'] ) . " )";
            else
                $sqlList['indexes'][] = eZOracleSchema::generateAddIndexSql( $table, $index_name, $index_def, $params, $withClosure );
        }

        // finish dumping table schema
        $sql .= join ( ",\n", $sqlFields ) . "\n)" . ( $withClosure ? ";\n" : '' );
        $sqlList['tables'][] = $sql;

        return $sqlList;
    }

    /*!
     * \private
     */
    function generateDropTable( $table )
    {
        return "DROP TABLE $table;\n";
    }


    /*!
    \reimp
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
                }
                $schema[$tableName]['indexes'] =& $tmpNewIndexes;
                unset( $tmpNewIndexes );
                ksort( $schema[$tableName]['indexes'] );

                // fix default values for CLOB fields: they should be false instead of null
                foreach ( $tableSchema['fields'] as $fieldName => $fieldSchema )
                {
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
                }
                $schema[$tableName]['indexes'] =& $tmpNewIndexes;
                unset( $tmpNewIndexes );
                ksort( $schema[$tableName]['indexes'] );
            }
        }

        return true;
    }

    /*!
     \reimp
    */
    function escapeSQLString( $value )
    {
        return str_replace( "'", "''", $value );
    }

    /*!
     \reimp
    */
    function schemaType()
    {
        return 'oracle';
    }

    /*!
     \reimp
    */
    function schemaName()
    {
        return 'Oracle';
    }
}
?>
