<?php
//
// Definition of eZDBFileHandlerOracleBackend class
//
// Created on: <03-May-2006 11:28:15 vs>
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ publish
// SOFTWARE RELEASE: 3.8.x
// COPYRIGHT NOTICE: Copyright (C) 1999-2006 eZ systems AS
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//

/*! \file ezdbfilehandleroraclebackend.php

NOTE: this backend requires PECL/oci8 extension to function.
You can download it here: http://pecl.php.net/package/oci8

*/

/*
CREATE TABLE ezdbfile (
  id        INT PRIMARY KEY,
  name      VARCHAR(255) NOT NULL UNIQUE,
  name_hash VARCHAR(34)  NOT NULL UNIQUE,
  datatype  VARCHAR(60)  DEFAULT 'application/octet-stream' NOT NULL,
  scope     VARCHAR(20)  DEFAULT 'UNKNOWN' NOT NULL ,
  filesize  INT          DEFAULT 0 NOT NULL ,
  mtime     INT          DEFAULT 0 NOT NULL ,
  lob       BLOB
);

CREATE SEQUENCE s_dbfile;

CREATE OR REPLACE TRIGGER ezdbfile_id_tr
BEFORE INSERT ON ezdbfile FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_dbfile.nextval INTO :new.id FROM dual;
END;
/


*/

define( 'TABLE_METADATA',     'ezdbfile' );

require_once( 'lib/ezutils/classes/ezdebugsetting.php' );
require_once( 'lib/ezutils/classes/ezdebug.php' );

class eZDBFileHandlerOracleBackend
{
    function _connect()
    {
        if ( !function_exists( 'oci_connect' ) )
            die( "PECL oci8 extension (http://pecl.php.net/package/oci8) is required to use Oracle clustering functionality.\n" );

        if ( !isset( $GLOBALS['eZDBFileHandlerOracleBackend_dbparams'] ) )
        {
            $fileINI = eZINI::instance( 'file.ini' );

            $params['host']       = $fileINI->variable( 'ClusteringSettings', 'DBHost' );
            $params['port']       = $fileINI->variable( 'ClusteringSettings', 'DBPort' );
            $params['dbname']     = $fileINI->variable( 'ClusteringSettings', 'DBName' );
            $params['user']       = $fileINI->variable( 'ClusteringSettings', 'DBUser' );
            $params['pass']       = $fileINI->variable( 'ClusteringSettings', 'DBPassword' );
            $params['chunk_size'] = $fileINI->variable( 'ClusteringSettings', 'DBChunkSize' );

            $GLOBALS['eZDBFileHandlerOracleBackend_dbparams'] = $params;
        }
        else
            $params = $GLOBALS['eZDBFileHandlerOracleBackend_dbparams'];

        $this->db = @oci_connect( $params['user'], $params['pass'], $params['dbname'] );
        if ( !$this->db )
            $this->_die( "Unable to connect to storage server" );
        $this->dbparams = $params;
        //ociinternaldebug( 1 );
    }

    function _delete( $filePath, $insideOfTransaction = false )
    {
        // If the file does not exists then do nothing.
        $metaData = $this->_fetchMetadata( $filePath );
        if ( !$metaData )
            return true;

        // Delete file (transaction is started implicitly).
        $result = true;
        $sql = "DELETE FROM " . TABLE_METADATA . " WHERE id=" . $metaData['id'];
        $statement = oci_parse( $this->db, $sql );
        if ( !@oci_execute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            $result = false;
        }

        oci_free_statement( $statement );

        if ( !$insideOfTransaction )
        {
            if ( $result )
                oci_commit( $this->db );
            else
                oci_rollback( $this->db );
        }

        return $result;
    }

    function _deleteByRegex( $regex )
    {
        $escapedRegex = $this->_escapeString( $regex );
        $sql = "DELETE FROM " . TABLE_METADATA . " WHERE REGEXP_LIKE( name, '$escapedRegex' )";
        $statement = oci_parse( $this->db, $sql );

        $result = true;
        if ( !@oci_execute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            $result = false;
        }

        if ( $result )
            oci_commit( $this->db );
        else
            oci_rollback( $this->db );
        oci_free_statement( $statement );
        return $result;
    }

    function _deleteByWildcard( $wildcard )
    {
        // Convert wildcard to regexp.
        $wildcard = $this->_escapeString( $wildcard );
        $regex = '^' . $wildcard  . '$';

        $regex = str_replace( array( '.'  ),
                              array( '\.' ),
                              $regex );

        $regex = str_replace( array( '?', '*',  '{', '}', ',' ),
                              array( '.', '.*', '(', ')', '|' ),
                              $regex );

        $escapedRegex = $this->_escapeString( $regex );
        $sql = "DELETE FROM " . TABLE_METADATA . " WHERE REGEXP_LIKE( name, '$escapedRegex' )";
        $statement = oci_parse( $this->db, $sql );

        $result = true;
        if ( !@oci_execute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            $result = false;
        }
        if ( $result )
            oci_commit( $this->db );
        else
            oci_rollback( $this->db );
        oci_free_statement( $statement );
        return $result;
    }

    function _deleteByLike( $like )
    {
        $like = $this->_escapeString( $like );
        $sql = "DELETE FROM " . TABLE_METADATA . " WHERE name like '$like'" ;
        $statement = oci_parse( $this->db, $sql );

        $result = true;
        if ( !@oci_execute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            $result = false;
        }

        if ( $result )
            oci_commit( $this->db );
        else
            oci_rollback( $this->db );

        oci_free_statement( $statement );
        return true;
    }

    function _deleteByDirList( $dirList, $commonPath, $commonSuffix )
    {

        foreach ( $dirList as $dirItem )
        {
            $sql = "DELETE FROM " . TABLE_METADATA . " WHERE name like '$commonPath/$dirItem/$commonSuffix%'" ;
            $statement = oci_parse( $this->db, $sql );

            $result = true;
            if ( !@oci_execute( $statement, OCI_DEFAULT ) )
            {
                $this->_error( $statement, $sql );
                $result = false;
            }

            if ( $result )
                oci_commit( $this->db );
            else
                oci_rollback( $this->db );

            oci_free_statement( $statement );
        }
        return true;
    }


    function _exists( $filePath )
    {
        $filePathHash = md5( $filePath );
        $sql = "SELECT COUNT(*) AS count FROM " . TABLE_METADATA . " WHERE name_hash='$filePathHash'";
        $statement = oci_parse( $this->db, $sql );
        $result = true;
        if ( !oci_execute ( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            $result = false;
        }
        $row = oci_fetch_row( $statement );
        $count = $row[0];
        oci_free_statement( $statement );
        return $row[0];
    }

    function __mkdir_p( $dir )
    {
        // create parent directories
        $dirElements = explode( '/', $dir );
        if ( count( $dirElements ) == 0 )
            return true;

        $result = true;
        $currentDir = $dirElements[0];

        if ( $currentDir != '' && !file_exists( $currentDir ) && !mkdir( $currentDir, '0777' ))
            return false;

        for ( $i = 1; $i < count( $dirElements ); ++$i )
        {
            $dirElement = $dirElements[$i];
            if ( strlen( $dirElement ) == 0 )
                continue;

            $currentDir .= '/' . $dirElement;

            if ( !file_exists( $currentDir ) && !mkdir( $currentDir, 0777 ) )
                return false;

            $result = true;
        }

        return $result;
    }

    function _fetch( $filePath )
    {
        // Check if the file exists in db.
        if ( !$this->_exists( $filePath ) )
        {
            eZDebug::writeNotice( "File '$filePath' does not exists while trying to fetch." );
            return false;
        }

        // Fetch LOB.
        if ( !( $lob = $this->_fetchLob( $filePath ) ) )
            return false;

        // Create temporary file.
        $tmpFilePath = $filePath . getmypid() . 'tmp';
        $this->__mkdir_p( dirname( $tmpFilePath ) );
        if ( !( $fp = fopen( $tmpFilePath, 'wb' ) ) )
        {
            eZDebug::writeError( "Cannot write to '$tmpFilePath' while fetching file." );
            return false;
        }

        // Read large object contents and write them to file.
        $chunkSize = $this->dbparams['chunk_size'];
        while ( $chunk = $lob->read( $chunkSize ) )
            fwrite( $fp, $chunk );
        fclose( $fp );
        rename( $tmpFilePath, $filePath );

        $lob->free();
        return true;

    }

    function _fetchContents( $filePath )
    {
        // Check if the file exists.
        if ( !$this->_exists( $filePath ) )
        {
            eZDebug::writeNotice( "File '$filePath' does not exists while trying to fetch its contents." );
            return false;
        }

        // Fetch large object.
        if ( !( $lob = $this->_fetchLob( $filePath ) ) )
            return false;

        $contents = $lob->load();
        $lob->free();
        return $contents;
    }

    function _fetchMetadata( $filePath )
    {
        $sql  = "SELECT id,name,name_hash,datatype,scope,filesize,mtime ";
        $sql .= "FROM " . TABLE_METADATA . " WHERE name_hash='" . md5( $filePath ) . "'" ;

        if ( !( $statement = oci_parse ( $this->db, $sql ) ) || !oci_execute ( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            return false;
        }

        oci_fetch_all( $statement, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW );

        if ( ( $nrows = count( $rows ) ) > 1 )
            eZDebug::writeError( "Duplicate file '$filePath' found." );
        elseif ( $nrows == 0 )
            return false;

        oci_free_statement( $statement );
        $row = $rows[0];

        // Convert column names to lowercase.
        foreach ( $row as $key => $val )
        {
            $row[strtolower( $key )] = $val;
            unset( $row[$key] );
        }

        // Hide that Oracle cannot handle 'size' column.
        $row['size'] = $row['filesize'];
        unset( $row['filesize'] );

        return $row;
    }

    function _store( $filePath, $datatype, $scope )
    {
        if ( !is_readable( $filePath ) )
        {
            eZDebug::writeError( "Unable to store file '$filePath' since it is not readable.", 'ezdbfilehandleroraclebackend' );
            return false;
        }

        if ( !$fp = @fopen( $filePath, 'rb' ) )
        {
            eZDebug::writeError( "Cannot read '$filePath'.", 'ezdbfilehandleroraclebackend' );
            return false;
        }

        // Prepare file metadata for storing.
        $filePathHash = md5( $filePath );
        $filePathEscaped = $this->_escapeString( $filePath );
        $datatype = $this->_escapeString( $datatype );
        $scope = $this->_escapeString( $scope );
        $fileMTime = (int) filemtime( $filePath );
        $contentLength = (int) filesize( $filePath );

        // Transaction is started implicitly.

        // Check if a file with the same name already exists in db.
        if ( $row = $this->_fetchMetadata( $filePath ) ) // if it does
        {
            $sql  = "UPDATE " . TABLE_METADATA . " SET ";
            $sql .= "name='$filePathEscaped', name_hash='$filePathHash', ";
            $sql .= "datatype='$datatype', scope='$scope', ";
            $sql .= "filesize=$contentLength, mtime=$fileMTime, ";
            $sql .= "lob=EMPTY_BLOB() ";
            $sql .= "WHERE id=" . $row['id'];
        }
        else // else if it doesn't
        {
            // create file in db
            $sql  = "INSERT INTO " . TABLE_METADATA . " (name, name_hash, datatype, scope, filesize, mtime, lob) ";
            $sql .= "VALUES ('$filePathEscaped', '$filePathHash', '$datatype', '$scope', ";
            $sql .= "'$contentLength', '$fileMTime', EMPTY_BLOB())";
        }
        $sql .= " RETURNING lob INTO :lob";

        $statement = oci_parse( $this->db, $sql );
        $lob = oci_new_descriptor( $this->db, OCI_D_LOB );
        oci_bind_by_name( $statement, ":lob", $lob, -1, OCI_B_BLOB );
        if ( !@oci_execute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            return false;
        }

        oci_free_statement( $statement );

        // Save large object.
        $chunkSize = $this->dbparams['chunk_size'];
        while ( !feof( $fp ) )
        {
            $chunk = fread( $fp, $chunkSize );

            if ( $lob->write( $chunk ) === false )
            {
                eZDebug::writeNotice( "Failed to write data chunk while storing file: " . $sql );
                oci_rollback( $this->db );
                fclose( $fp );
                $lob->free();
                return false;
            }
        }
        fclose( $fp );
        $lob->free();

        // Commit DB transaction.
        oci_commit( $this->db );

        return true;
    }

    function _storeContents( $filePath, $contents, $scope, $datatype )
    {
        // Mostly cut&pasted from _store().

        // Prepare file metadata for storing.
        $filePathHash = md5( $filePath );
        $filePathEscaped = $this->_escapeString( $filePath );
        $datatype = $this->_escapeString( $datatype );
        $scope = $this->_escapeString( $scope );
        $fileMTime = time();
        $contentLength = strlen( $contents );

        // Transaction is started implicitly.

        // Check if a file with the same name already exists in db.
        if ( $row = $this->_fetchMetadata( $filePath ) ) // if it does
        {
            $sql  = "UPDATE " . TABLE_METADATA . " SET ";
            $sql .= "name='$filePathEscaped', name_hash='$filePathHash', ";
            $sql .= "datatype='$datatype', scope='$scope', ";
            $sql .= "filesize=$contentLength, mtime=$fileMTime, ";
            $sql .= "lob=EMPTY_BLOB() ";
            $sql .= "WHERE id=" . $row['id'];
        }
        else // else if it doesn't
        {
            // create file in db
            $sql  = "INSERT INTO " . TABLE_METADATA . " (name, name_hash, datatype, scope, filesize, mtime, lob) ";
            $sql .= "VALUES ('$filePathEscaped', '$filePathHash', '$datatype', '$scope', ";
            $sql .= "'$contentLength', '$fileMTime', EMPTY_BLOB())";
        }
        $sql .= " RETURNING lob INTO :lob";

        $statement = oci_parse( $this->db, $sql );
        $lob = oci_new_descriptor( $this->db, OCI_D_LOB );
        oci_bind_by_name( $statement, ":lob", $lob, -1, OCI_B_BLOB );
        if ( !@oci_execute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            $lob->free();
            oci_rollback( $conn );
            return false;
        }

        oci_free_statement( $statement );

        // Save large object.
        $chunkSize = $this->dbparams['chunk_size'];
        for ( $pos = 0; $pos < $contentLength; $pos += $chunkSize )
        {
            $chunk = substr( $contents, $pos, $chunkSize );
            if ( $lob->write( $chunk ) === false )
            {
                eZDebug::writeNotice( "Failed to write data chunk while storing file contents: " . $sql );
                $lob->free();
                oci_rollback( $this->db );
                return;
            }
        }
        $lob->free();

        // Commit DB transaction.
        oci_commit( $this->db );

        return true;
    }

    function _copy( $srcFilePath, $dstFilePath )
    {
        // Fetch source file metadata.
        $srcMetadata = $this->_fetchMetadata( $srcFilePath );
        if ( !$srcMetadata ) // if source file does not exist then do nothing.
            return false;

        // Delete destination file if exists.
        // NOTE: check for race conditions and deadlocks here.
        if ( $this->_exists( $dstFilePath ) )
            $this->_delete( $dstFilePath, true );

        // Fetch source large object.
        if ( !( $srcLob = $this->_fetchLob( $srcFilePath ) ) )
            return false;

        // Insert destination metadata.
        $sql  = "INSERT INTO " . TABLE_METADATA . " (name, name_hash, datatype, scope, filesize, mtime, lob) VALUES ";
        $sql .= sprintf( "('%s', '%s', '%s', '%s', %d, %d, EMPTY_BLOB()) RETURNING lob INTO :lob",
                         $this->_escapeString( $dstFilePath ), md5( $dstFilePath ),
                         $srcMetadata['datatype'], $srcMetadata['scope'], $srcMetadata['size'], $srcMetadata['mtime'] );
        $statement = oci_parse( $this->db, $sql );
        $dstLob = oci_new_descriptor( $this->db, OCI_D_LOB );
        oci_bind_by_name( $statement, ":lob", $dstLob, -1, OCI_B_BLOB );
        if ( !oci_execute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            $srcLob->free();
            $dstLob->free();
            oci_rollback( $this->db );
            return false;
        }

        oci_free_statement( $statement );

        // Copy source large object data.
        $chunkSize = $this->dbparams['chunk_size'];
        while ( $chunk = $srcLob->read( $chunkSize ) )
        {
            if ( $dstLob->write( $chunk ) === false )
            {
                eZDebug::writeNotice( "Failed to write data chunk while storing file contents: " . $sql );
                $srcLob->free();
                $dstLob->free();
                oci_rollback( $this->db );
                return false;
            }
        }

        $srcLob->free();
        $dstLob->free();

        // Commit DB transaction.
        oci_commit( $this->db );

        return true;
    }

    function _linkCopy( $srcPath, $dstPath )
    {
        return $this->_copy( $srcPath, $dstPath );
    }

    function _rename( $srcFilePath, $dstFilePath )
    {
        // Check if source file exists.
        $srcMetadata = $this->_fetchMetadata( $srcFilePath );
        if ( !$srcMetadata )
        {
            // if doesn't then do nothing
            eZDebug::writeWarning( "File '$srcFilePath' to rename does not exist",
                                   'ezdbfilehandleroraclebackend' );
            return false;
        }

        // Delete destination file if exists.
        $dstMetadata = $this->_fetchMetadata( $dstFilePath );
        if ( $dstMetadata ) // if destination file exists
            $this->_delete( $dstFilePath, true );

        // Update source file metadata.
        $sql = sprintf( "UPDATE %s SET name='%s', name_hash='%s' WHERE id=%d",
                        TABLE_METADATA,
                        $this->_escapeString( $dstFilePath ), md5( $dstFilePath ),
                        $srcMetadata['id'] );
        $statement = oci_parse( $this->db, $sql );
        if ( !oci_execute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            eZDebug::writeError( "Error renaming file '$srcFilePath'.", 'ezdbfilehandleroraclesqlbackend' );
            oci_rollback( $this->db );
            return false;
        }

        oci_commit( $this->db );
        return true;
    }

    function _passThrough( $filePath )
    {
        if ( !$this->_exists( $filePath ) )
            return false;

        if ( !( $lob = $this->_fetchLob( $filePath ) ) )
            return false;

        $chunkSize = $this->dbparams['chunk_size'];
        while ( $chunk = $lob->read( $chunkSize ) )
            echo $chunk;

        $lob->free();
        return true;
    }

    function _getFileList( $skipBinaryFiles, $skipImages )
    {
        $query = 'SELECT name FROM ' . TABLE_METADATA;

        // omit some file types if needed
        $filters = array();
        if ( $skipBinaryFiles )
            $filters[] = "'binaryfile'";
        if ( $skipImages )
            $filters[] = "'image'";
        if ( $filters )
            $query .= ' WHERE scope NOT IN (' . join( ', ', $filters ) . ')';

        $statement = oci_parse( $this->db, $query );
        if ( !@oci_execute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            return false;
        }

        $filePathList = array();
        while ( $row = oci_fetch_row( $statement ) )
            $filePathList[] = $row[0];

        oci_free_statement( $statement );
        return $filePathList;
    }

    function _die( $msg, $sql = null )
    {
        $error = oci_error( $this->db );
        eZDebug::writeError( $sql, "$msg: " . $error['message'] );

        if( @include_once( '../bt.php' ) )
        {
            bt();
        }
        die( $msg );
    }

    /**
     * \private
     * \static
     */
    function _fetchLob( $filePath )
    {
        $query = 'SELECT lob FROM ' . TABLE_METADATA . " WHERE name_hash = '" . md5( $filePath ) . "'";
        $statement = oci_parse( $this->db, $query );
        if ( !oci_execute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $query );
            return false;
        }
        if ( !( $row = oci_fetch_array( $statement, OCI_ASSOC ) ) )
        {
            eZDebug::writeNotice( "No data in file '$filePath'." );
            oci_free_statement( $statement );
            return false;
        }
        oci_free_statement( $statement );
        return $row['LOB'];
    }


    /**
     * \private
     * \static
     */
    function _error( $statement, $sql )
    {
        $error = oci_error( $statement );
        eZDebug::writeError( "Faied query was: <$sql>", "Error executing query: " . $error['message'] );
    }

    /**
     * \private
     * \static
     */
    function _escapeString( $str )
    {
        return str_replace ("'", "''", $str );
    }

    var $db = null;
    var $dbparams = null;
}

?>
