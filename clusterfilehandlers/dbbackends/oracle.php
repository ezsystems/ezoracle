<?php
//
// Definition of eZDBFileHandlerOracleBackend class
//
// Created on: <03-May-2006 11:28:15 vs>
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ Oracle
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
@file ezdbfilehandleroraclebackend.php

Known differences from the mysql cluster file handler backend:
- since we use a single table for storing data, having distinct purge and delete functions is not of much use,
  hence we delete files immediately!
  Note that to realize 'logical deletes' the MERGE statement needs to be used,
  only available in oracle 9+ (and does it support lobs?)
- the locking mechanism used for cache processing is based on "select for update",
  not on inverting fime mtime
- after a file delete, _exists returns false, as well as getting content

@todo implement _sharedLock, _freeSharedLock
@todo some benchmarks of the performances of real deletes vs. simulated deletes
*/

/*
CREATE TABLE ezdbfile (
  name      VARCHAR2(4000) NOT NULL,
  name_hash VARCHAR2(34)  PRIMARY KEY,
  datatype  VARCHAR2(60)  DEFAULT 'application/octet-stream',
  scope     VARCHAR2(20)  DEFAULT 'UNKNOWN',
  filesize  INT           DEFAULT 0 NOT NULL,
  mtime     INT           DEFAULT 0 NOT NULL,
  lob       BLOB,
  expired   CHAR(1)       DEFAULT '0' NOT NULL
);
CREATE INDEX ezdbfile_name ON ezdbfile ( name );
CREATE INDEX ezdbfile_mtime ON ezdbfile ( mtime );
--CREATE UNIQUE INDEX ezdbfile_expired_name ON ezdbfile ( expired, name );

*/

//require_once( 'lib/ezutils/classes/ezdebugsetting.php' );
//require_once( 'lib/ezutils/classes/ezdebug.php' );

class eZDBFileHandlerOracleBackend
{
    const RETURN_BOOL = 0;
    const RETURN_COUNT = 1;
    const RETURN_DATA = 2;

    const TABLE_METADATA = 'ezdbfile';
    //static $deletequery = "UPDATE ezdbfile SET mtime=-ABS(mtime), expired='1' ";
    static $deletequery = "DELETE FROM ezdbfile ";

    function _connect( $newLink = false )
    {
        if ( !function_exists( 'oci_connect' ) )
            die( "PECL oci8 extension (http://pecl.php.net/package/oci8) is required to use Oracle clustering functionality.\n" );

        if ( !isset( $GLOBALS['eZDBFileHandlerOracleBackend_dbparams'] ) )
        {
            $siteINI = eZINI::instance( 'site.ini' );
            $fileINI = eZINI::instance( 'file.ini' );

            //$params['host']       = $fileINI->variable( 'ClusteringSettings', 'DBHost' );
            //$params['port']       = $fileINI->variable( 'ClusteringSettings', 'DBPort' );
            $params['dbname']     = $fileINI->variable( 'ClusteringSettings', 'DBName' );
            $params['user']       = $fileINI->variable( 'ClusteringSettings', 'DBUser' );
            $params['pass']       = $fileINI->variable( 'ClusteringSettings', 'DBPassword' );
            $params['chunk_size'] = $fileINI->variable( 'ClusteringSettings', 'DBChunkSize' );

            $params['max_connect_tries'] = $fileINI->variable( 'ClusteringSettings', 'DBConnectRetries' );
            $params['max_execute_tries'] = $fileINI->variable( 'ClusteringSettings', 'DBExecuteRetries' );

            $params['sql_output'] = $siteINI->variable( "DatabaseSettings", "SQLOutput" ) == "enabled";

            $params['cache_generation_timeout'] = $siteINI->variable( "ContentSettings", "CacheGenerationTimeout" );

            $params['persistent_connection'] = $fileINI->hasVariable( 'ClusteringSettings', 'DBPersistentConnection' ) ? ( $fileINI->variable( 'ClusteringSettings', 'DBPersistentConnection' ) == 'enabled' ) : false;

            $GLOBALS['eZDBFileHandlerOracleBackend_dbparams'] = $params;
        }
        else
            $params = $GLOBALS['eZDBFileHandlerOracleBackend_dbparams'];
        $this->dbparams = $params;

        $this->db = oci_connect( $params['user'], $params['pass'], $params['dbname'] );
        $maxTries = $params['max_connect_tries'];
        $tries = 0;
        while ( $tries < $maxTries )
        {
            if ( $newLink )
            {
                if ( $this->db = oci_new_connect( $params['user'], $params['pass'], $params['dbname'] ) )
                    break;
            }
            else
            {
                if ( $this->dbparams['persistent_connection'] )
                {
                    if ( $this->db = oci_pconnect( $params['user'], $params['pass'], $params['dbname'] ) )
                        break;
                }
                else
                {
                    if ( $this->db = oci_connect( $params['user'], $params['pass'], $params['dbname'] ) )
                        break;
                }
            }
            ++$tries;
        }

        if ( !$this->db )
            return $this->_die( "Unable to connect to storage server" );
        //ociinternaldebug( 1 );
    }

    function _copy( $srcFilePath, $dstFilePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_copy($srcFilePath, $dstFilePath)";
        else
            $fname = "_copy($srcFilePath, $dstFilePath)";

        // Fetch source file metadata.
        $metaData = $this->_fetchMetadata( $srcFilePath, $fname );
        if ( !$metaData ) // if source file does not exist then do nothing.
            return false;

        return $this->_protect( array( $this, "_copyInner" ), $fname,
                                $srcFilePath, $dstFilePath, $fname, $metaData );
    }

    function _copyInner( $srcFilePath, $dstFilePath, $fname, $metaData )
    {
        $this->_delete( $dstFilePath, true, $fname );

        // mysql version does a little trick: it might have files with negative timestamps (marked as expired while locked)
        // so it checks it at copy time, and mark as expired in case
        // with oracle we never have negative timestamps
        $name = $this->_escapeString( $dstFilePath );
        $hash = md5( $dstFilePath );
        $sql  = "INSERT INTO " . eZDBFileHandlerOracleBackend::TABLE_METADATA . " (datatype, name, name_hash, scope, filesize, mtime, expired, lob) ";
        //$sql .= "SELECT datatype, '$name', '$hash', scope, filesize, mtime, decode(least(mtime, 0), 0, '0', '1'), lob FROM " . eZDBFileHandlerOracleBackend::TABLE_METADATA . " WHERE name_hash=:name_hash";
        $sql .= "SELECT datatype, '$name', '$hash', scope, filesize, mtime, expired, lob FROM " . eZDBFileHandlerOracleBackend::TABLE_METADATA . " WHERE name_hash=:name_hash";

        $return = $this->_query( $sql, $fname, true, array(
            //':name' => $dstFilePath,
            //':hash' => md5( $dstFilePath ),
            ':name_hash' => $metaData['name_hash'] ), eZDBFileHandlerOracleBackend::RETURN_COUNT );
        // if the copy affects 0 rows, somebody else deleted the source file
        // just after we checked it was there in _copy(). Then we have to rollback
        if ( $return === 0 )
        {
            $return = false;
        }
        return $return;
    }

    /**
     Purges all data for the file entry named $filePath from the database.
     */
    function _purge( $filePath, $onlyExpired = false, $expiry = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_purge($filePath)";
        else
            $fname = "_purge($filePath)";
        $sql = "DELETE FROM " . eZDBFileHandlerOracleBackend::TABLE_METADATA . " WHERE name_hash=:hash";
        $params = array( ':hash' => md5( $filePath ) );
        if ( $expiry !== false )
        {
            $sql .= " AND mtime < :expiry";
            $params[':expiry'] = $expiry;
        }
        elseif ( $onlyExpired )
            $sql .= " AND expired = '1'";
        if ( !$this->_query( $sql, $fname, true, $params ) )
            return $this->_fail( "Purging file metadata for $filePath failed" );
        return true;
    }

    /**
     Purges meta-data and file-data for the matching files.
     Matching is done by passing the string $like to the LIKE statement in the SQL.
     */
    function _purgeByLike( $like, $onlyExpired = false, $limit = 50, $expiry = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_purgeByLike($like, $onlyExpired)";
        else
            $fname = "_purgeByLike($like, $onlyExpired)";
        $sql = "DELETE FROM " . eZDBFileHandlerOracleBackend::TABLE_METADATA . " WHERE name LIKE :alike";
        $params = array ( ':alike' => $like );
        if ( $expiry !== false )
        {
            $sql .= " AND mtime < :expiry";
            $params[':expiry'] = $expiry;
        }
        elseif ( $onlyExpired )
            $sql .= " AND expired = '1'";
        if ( $limit )
            $sql .= " and ROWNUM <= $limit";
        if ( ($numrows = $this->_query( $sql, $fname, true, $params, eZDBFileHandlerOracleBackend::RETURN_COUNT ) ) === false )
            return $this->_fail( "Purging file metadata by like statement $like failed" );
        return $numrows;
    }

    function _delete( $filePath, $insideOfTransaction = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_delete($filePath)";
        else
            $fname = "_delete($filePath)";
        if ( $insideOfTransaction )
        {
            $res = $this->_deleteInner( $filePath, $fname );
            if ( !$res || $res instanceof eZMySQLBackendError )
            {
                $this->_handleErrorType( $res );
            }
        }
        else
            return $this->_protect( array( $this, '_deleteInner' ), $fname,
                                    $filePath, $fname );
    }

    function _deleteInner( $filePath, $fname )
    {
        $hash = md5( $filePath );
        $sql = eZDBFileHandlerOracleBackend::$deletequery . "WHERE name_hash=:hash";
        if ( !$this->_query( $sql, $fname, true, array( ':hash' => $hash ) ) )
        {
            return $this->_fail( "Deleting file $filePath failed" );
        }
        return true;
    }

    function _deleteByLike( $like, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByLike($like)";
        else
            $fname = "_deleteByLike($like)";
        return $this->_protect( array( $this, '_deleteByLikeInner' ), $fname,
                                $like, $fname );
    }

    function _deleteByLikeInner( $like, $fname )
    {
        $sql = eZDBFileHandlerOracleBackend::$deletequery . "WHERE name LIKE :alike" ;
        if ( !$res = $this->_query( $sql, $fname, true, array ( ':alike' => $like ) ) )
        {
            return $this->_fail( "Failed to delete files by like: '$like'" );
        }
        return true;
    }

    function _deleteByRegex( $regex, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByRegex($regex)";
        else
            $fname = "_deleteByRegex($regex)";
        return $this->_protect( array( $this, '_deleteByRegexInner' ), $fname,
                                $regex, $fname );
    }

    function _deleteByRegexInner( $regex, $fname )
    {
        $sql = eZDBFileHandlerOracleBackend::$deletequery . "WHERE REGEXP_LIKE( name, :escapedRegex )";
        if ( !$res = $this->_query( $sql, $fname, true, array( ':escapedRegex' => $regex ) ) )
        {
            return $this->_fail( "Failed to delete files by regex: '$regex'" );
        }
        return true;
    }

    function _deleteByWildcard( $wildcard, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByWildcard($wildcard)";
        else
            $fname = "_deleteByWildcard($wildcard)";
        return $this->_protect( array( $this, '_deleteByWildcardInner' ), $fname,
                                $wildcard, $fname );
    }

    function _deleteByWildcardInner( $wildcard, $fname )
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

        $sql = eZDBFileHandlerOracleBackend::$deletequery . "WHERE REGEXP_LIKE( name, :escapedRegex )";
        if ( !$res = $this->_query( $sql, $fname, true, array( ':escapedRegex' => $regex ) ) )
        {
            return $this->_fail( "Failed to delete files by regex: '$regex'" );
        }
        return true;
    }

    function _deleteByDirList( $dirList, $commonPath, $commonSuffix, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByDirList($dirList, $commonPath, $commonSuffix)";
        else
            $fname = "_deleteByDirList($dirList, $commonPath, $commonSuffix)";
        return $this->_protect( array( $this, '_deleteByDirListInner' ), $fname,
                                $dirList, $commonPath, $commonSuffix, $fname );
    }

    /**
    * @todo wrap this call in time measuring functions
    */
    function _deleteByDirListInner( $dirList, $commonPath, $commonSuffix, $fname )
    {
        $result = true;
        $this->error = false;
        $like = ''; // not sure it is necessary to initialize, but in case...
        $sql = eZDBFileHandlerOracleBackend::$deletequery . "WHERE name LIKE :alike" ;
        $statement = oci_parse( $this->db, $sql );
        oci_bind_by_name($statement, ':alike', $like, 4000);

        foreach ( $dirList as $dirItem )
        {
            $like = "$commonPath/$dirItem/$commonSuffix%";

            if ( !@oci_execute( $statement, OCI_DEFAULT ) )
            {
                $this->error = oci_error( $statement );
                $this->_error( $sql, $fname, false );
                $result = false;
                break;
            }
        }

        oci_free_statement( $statement );

        /*if ( $result )
            oci_commit( $this->db );
        else
            oci_rollback( $this->db );*/

        return $result;
    }

    function _exists( $filePath, $fname = false, $ignoreExpiredFiles = true )
    {
        if ( $fname )
            $fname .= "::_exists($filePath)";
        else
            $fname = "_exists($filePath)";

        $hash = md5( $filePath );
        $sql = "SELECT mtime, expired FROM " . eZDBFileHandlerOracleBackend::TABLE_METADATA . " WHERE name_hash=:hash";

        if ( ! $row = $this->_selectOneAssoc( $sql, $fname, false, false, array( ':hash' => $hash ) ) )
        {
            return false;
        }
        /// @todo should not we check 'expired', too?
        /// @todo fix this before enabling logical deletes: only test if $ignoreExpiredFiles
        return $row['mtime'] >= 0;
    }

    function __mkdir_p( $dir )
    {
        // create parent directories
        $dirElements = explode( '/', $dir );
        if ( count( $dirElements ) == 0 )
            return true;

        $result = true;
        $currentDir = $dirElements[0];

        if ( $currentDir != '' && !file_exists( $currentDir ) && !eZDir::mkdir( $currentDir, false ) )
            return false;

        for ( $i = 1; $i < count( $dirElements ); ++$i )
        {
            $dirElement = $dirElements[$i];
            if ( strlen( $dirElement ) == 0 )
                continue;

            $currentDir .= '/' . $dirElement;

            if ( !file_exists( $currentDir ) && !eZDir::mkdir( $currentDir, false ) )
                return false;

            $result = true;
        }

        return $result;
    }

    /**
    * Fetches the file $filePath from the database, saving it locally with its
    * original name, or $uniqueName if given
    *
    * looks like when uniqueName is set to true, we are not using atomic writing,
    * and a corruption might occur... so we make sure we have server ip+pid+timestamp
    *
    * @param string $filePath
    * @param string $uniqueName
    * @return the file physical path, or false if fetch failed
    **/
    function _fetch( $filePath, $uniqueName = false, $fname = false )
    {
        // NOTE: useless check, since _fetchlob does it anyway. Spare some cycles
        //if ( !$this->_exists( $filePath ) )
        //{
        //    eZDebug::writeNotice( "File '$filePath' does not exists while trying to fetch." );
        //    return false;
        //}
        if ( $fname )
            $fname .= "::_fetch($filePath, $uniqueName)";
        else
            $fname = "_fetch($filePath, $uniqueName)";
        // Fetch LOB pointer
        if ( !( $lob = $this->_fetchLob( $filePath ) ) )
            return false;
        $metaData = $lob;
        // nb: a NULL lob means an empty file
        $lob = $metaData['lob'];

        // Create temporary file.
        /// @todo improve unique name generation: $_SERVER["SERVER_ADDR"] is not avail on cli sapi
        if ( strrpos( $filePath, '.' ) > 0 )
            $tmpFilePath = substr_replace( $filePath, '.' . getmypid() . '.tmp', strrpos( $filePath, '.' ), 0  );
        else
            $tmpFilePath = $filePath . '.' . getmypid(). '.tmp';
//        $tmpFilePath = $filePath.getmypid().'tmp';
        $this->__mkdir_p( dirname( $tmpFilePath ) );

        if ( !( $fp = fopen( $tmpFilePath, 'wb' ) ) )
        {
            eZDebug::writeError( "Cannot write to '$tmpFilePath' while fetching file.", __METHOD__ );
            if ( is_object( $lob ) )
            {
                $lob->free();
            }
            return false;
        }

        if ( is_object( $lob ) )
        {
            // Read large object contents and write them to file.
            $chunkSize = $this->dbparams['chunk_size'];
            while ( $chunk = $lob->read( $chunkSize ) )
                fwrite( $fp, $chunk );
        }
        fclose( $fp );

        // Make sure all data is written correctly
        clearstatcache();
        $tmpSize = filesize( $tmpFilePath );
        if ( $tmpSize != $metaData['filesize'] )
        {
            eZDebug::writeError( "Size ($tmpSize) of written data for file '$tmpFilePath' does not match expected size " . $metaData['size'], __METHOD__ );
            if ( is_object( $lob ) )
            {
                $lob->free();
            }
            return false;
        }

        if ( !$uniqueName === true )
        {
            //include_once( 'lib/ezfile/classes/ezfile.php' );
            eZFile::rename( $tmpFilePath, $filePath );
        }
        else
        {
            $filePath = $tmpFilePath;
        }
        if ( is_object( $lob ) )
        {
            $lob->free();
        }
        return $filePath;
    }

    function _fetchContents( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_fetchContents($filePath)";
        else
            $fname = "_fetchContents($filePath)";

        // NOTE: useless check, since _fetchlob does it anyway. Spare some cycles
        // Check if the file exists.
        //if ( !$this->_exists( $filePath ) )
        //{
        //    eZDebug::writeNotice( "File '$filePath' does not exists while trying to fetch its contents." );
        //    return false;
        //}

        // Fetch large object.
        if ( !( $lob = $this->_fetchLob( $filePath, $fname ) ) )
            return false;

        $lob = $lob['lob'];
        if ( is_object( $lob ) )
        {
            $contents = $lob->load();
            $lob->free();
        }
        else
        {
            // zero-length file
            $contents = '';
        }
        return $contents;
    }

    function _fetchMetadata( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_fetchMetadata($filePath)";
        else
            $fname = "_fetchMetadata($filePath)";
        $hash = md5( $filePath );
        $sql  = "SELECT datatype,name,name_hash,scope,filesize,mtime,expired " .
                "FROM " . eZDBFileHandlerOracleBackend::TABLE_METADATA . " WHERE name_hash=:hash" ;

        $row = $this->_selectOneAssoc( $sql, $fname, false, false, array( ':hash' => $hash ) );

        // Hide that Oracle cannot handle 'size' column.
        if ( $row )
        {
            $row['size'] = $row['filesize'];
            unset( $row['filesize'] );
        }

        return $row;
    }

    function _linkCopy( $srcPath, $dstPath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_linkCopy($srcPath,$dstPath)";
        else
            $fname = "_linkCopy($srcPath,$dstPath)";
        return $this->_copy( $srcPath, $dstPath, $fname );
    }

    /**
     * @deprecated This function should not be used since it cannot handle reading errors.
     *             For the PHP 5 port this should be removed.
     */
    function _passThrough( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_passThrough($filePath)";
        else
            $fname = "_passThrough($filePath)";

        // NOTE: useless check, since _fetchlob does it anyway. Spare some cycles
        //if ( !$this->_exists( $filePath ) )
        //    return false;

        if ( !( $lob = $this->_fetchLob( $filePath, $fname ) ) )
            return false;
        $lob = $lob['lob'];

        if ( is_object( $lob ) )
        {
            $chunkSize = $this->dbparams['chunk_size'];
            while ( $chunk = $lob->read( $chunkSize ) )
                echo $chunk;

            $lob->free();
        }
        else
        {
            // zero-byte file
            echo '';
        }
        return true;
    }

    /// @todo it would be faster than fetching metadata first to do a SELECT FOR UPDATE directly
    function _rename( $srcFilePath, $dstFilePath, $fname = false )
    {
        if ( strcmp( $srcFilePath, $dstFilePath ) == 0 )
            return;
        if ( $fname )
            $fname .= "::_rename($srcFilePath, $dstFilePath)";
        else
            $fname = "_rename($srcFilePath, $dstFilePath)";
        // Fetch source file metadata.
        $metaData = $this->_fetchMetadata( $srcFilePath, $fname );
        if ( !$metaData ) // if source file does not exist then do nothing.
            return false;

        return $this->_protect( array( $this, "_renameInner" ), $fname,
                                $srcFilePath, $dstFilePath, $fname, $metaData );
    }

    function _renameInner( $srcFilePath, $dstFilePath, $fname, $metaData )
    {
        // Delete destination file if exists.
        // NOTE: no use in fetching before deleting it...
        $this->_delete( $dstFilePath, true, $fname );

        // Update source file metadata.
        $name = $this->_escapeString( $dstFilePath );
        $hash = md5( $dstFilePath );
        $sql = "UPDATE " . eZDBFileHandlerOracleBackend::TABLE_METADATA . " SET name='$name', name_hash='$hash' WHERE name_hash=:name_hash";

        // we count the rows update: if zero, it means another transaction
        // removed the src file after we fetched metadata above, so we rollback
        $return = $this->_query( $sql, "_rename($srcFilePath, $dstFilePath)", true, array(
            /*':name' => $name, ':hash' => $hash,*/ ':name_hash' => $metaData['name_hash'] ), eZDBFileHandlerOracleBackend::RETURN_COUNT );
        if ( $return === 0 )
        {
            $return = false;
        }
        return $return;
    }

    /*
     Note that scope 'images' and 'binaryfiles' are used by this class. Other
     scopes are free...
     */
    function _store( $filePath, $datatype, $scope, $fname = false )
    {
        if ( !is_readable( $filePath ) )
        {
            eZDebug::writeError( "Unable to store file '$filePath' since it is not readable.", __METHOD__ );
            return;
        }
        if ( $fname )
            $fname .= "::_store($filePath, $datatype, $scope)";
        else
            $fname = "_store($filePath, $datatype, $scope)";

        $this->_protect( array( $this, '_storeInner' ), $fname,
                         $filePath, $datatype, $scope, $fname );
    }

    /// @todo add time measurements around this
    function _storeInner( $filePath, $datatype, $scope, $fname )
    {
        // Prepare file metadata for storing.
        clearstatcache();
        $fileMTime = (int) filemtime( $filePath );
        $contentLength = (int) filesize( $filePath );
        $filePathHash = md5( $filePath );
        $filePathEscaped = $this->_escapeString( $filePath );
        $datatype = $this->_escapeString( $datatype );
        $scope = $this->_escapeString( $scope );

        if ( !$fp = @fopen( $filePath, 'rb' ) )
        {
            eZDebug::writeError( "Cannot read '$filePath'.", __METHOD__ );
            return false;
        }

        // Check if a file with the same name already exists in db.
        if ( $row = $this->_fetchMetadata( $filePath ) ) // if it does
        {
            $sql  = "UPDATE " . eZDBFileHandlerOracleBackend::TABLE_METADATA . " SET " .
                    //"name='$filePathEscaped', name_hash='$filePathHash', " .
                    "datatype='$datatype', scope='$scope', " .
                    "filesize=$contentLength, mtime=$fileMTime, expired='0', " .
                    "lob=EMPTY_BLOB() " .
                    "WHERE name_hash='$filePathHash'";
        }
        else // else if it doesn't
        {
            // create file in db
            $sql  = "INSERT INTO " . eZDBFileHandlerOracleBackend::TABLE_METADATA . " (name, name_hash, datatype, scope, filesize, mtime, expired, lob) " .
                    "VALUES ('$filePathEscaped', '$filePathHash', '$datatype', '$scope', " .
                    "$contentLength, $fileMTime, '0', EMPTY_BLOB())";
        }
        $sql .= " RETURNING lob INTO :lob";

        $this->error = false;
        $statement = oci_parse( $this->db, $sql );
        $lob = oci_new_descriptor( $this->db, OCI_D_LOB );
        oci_bind_by_name( $statement, ":lob", $lob, -1, OCI_B_BLOB );
        if ( !@oci_execute( $statement, OCI_DEFAULT ) )
        {
            $this->error = oci_error( $statement );
            $this->_error( $sql, $fname, false );
            if ( $lob )
            {
                $lob->free();
            }
            oci_free_statement( $statement );
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
                eZDebug::writeNotice( "Failed to write data chunk while storing file: " . $sql, __METHOD__ );
                fclose( $fp );
                $lob->free();
                //oci_rollback( $this->db );
                return false;
            }
        }
        fclose( $fp );
        $lob->free();

        return true;
    }

    function _storeContents( $filePath, $contents, $scope, $datatype, $mtime = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_storeContents($filePath, ..., $scope, $datatype)";
        else
            $fname = "_storeContents($filePath, ..., $scope, $datatype)";

        $this->_protect( array( $this, '_storeContentsInner' ), $fname,
                         $filePath, $contents, $scope, $datatype, $mtime, $fname );
    }

    /// @todo add time measurements around this
    function _storeContentsInner( $filePath, $contents, $scope, $datatype, $mtime, $fname )
    {
        // Mostly cut&pasted from _store().
        if ( $fname )
            $fname .= "::_storeContents($filePath, ..., $scope, $datatype)";
        else
            $fname = "_storeContents($filePath, ..., $scope, $datatype)";

        // Prepare file metadata for storing.
        $filePathHash = md5( $filePath );
        $filePathEscaped = $this->_escapeString( $filePath );
        $datatype = $this->_escapeString( $datatype );
        $scope = $this->_escapeString( $scope );
        if ( $mtime === false )
        {
            $mtime = time();
        }
        else
        {
            $mtime = (int)$mtime;
        }
        $expired = ($mtime < 0) ? '1' : '0';
        $contentLength = strlen( $contents );

        // Transaction is started implicitly.

        // Check if a file with the same name already exists in db.
        if ( $row = $this->_fetchMetadata( $filePath ) ) // if it does
        {
            $sql  = "UPDATE " . eZDBFileHandlerOracleBackend::TABLE_METADATA . " SET " .
                //"name='$filePathEscaped', name_hash='$filePathHash', " .
                "datatype='$datatype', scope='$scope', " .
                "filesize=$contentLength, mtime=$mtime, expired='$expired', " .
                "lob=EMPTY_BLOB() " .
                "WHERE name_hash='$filePathHash'";
        }
        else // else if it doesn't
        {
            // create file in db
            $sql  = "INSERT INTO " . eZDBFileHandlerOracleBackend::TABLE_METADATA . " (name, name_hash, datatype, scope, filesize, mtime, expired, lob) " .
                    "VALUES ('$filePathEscaped', '$filePathHash', '$datatype', '$scope', " .
                    "'$contentLength', $mtime, '$expired', EMPTY_BLOB())";
        }
        $sql .= " RETURNING lob INTO :lob";

        $this->error = false;
        $statement = oci_parse( $this->db, $sql );
        $lob = oci_new_descriptor( $this->db, OCI_D_LOB );
        oci_bind_by_name( $statement, ":lob", $lob, -1, OCI_B_BLOB );
        if ( !@oci_execute( $statement, OCI_DEFAULT ) )
        {
            $this->error = oci_error( $statement );
            $this->_error( $sql, $fname, false );
            if ( $lob )
            {
                $lob->free();
            }
            //oci_rollback( $conn );
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
                eZDebug::writeNotice( "Failed to write data chunk while storing file contents: " . $sql, __METHOD__ );
                $lob->free();
                //oci_rollback( $this->db );
                return;
            }
        }
        $lob->free();

        // Commit DB transaction.
        //oci_commit( $this->db );

        return true;
    }

    function _getFileList( $scopes = false, $excludeScopes = false )
    {
        $query = 'SELECT name FROM ' . eZDBFileHandlerOracleBackend::TABLE_METADATA;

        if ( is_array( $scopes ) && count( $scopes ) > 0 )
        {
            $query .= ' WHERE scope ';
            if ( $excludeScopes )
                $query .= 'NOT ';
            $query .= "IN ('" . implode( "', '", $scopes ) . "')";
        }

        $rows = $this->_query( $query, "_getFileList( array( " . implode( ', ', $scopes ) . " ), $excludeScopes )", true, array(), eZDBFileHandlerOracleBackend::RETURN_DATA );
        if ( $rows === false )
        {
            eZDebug::writeDebug( 'Unable to get file list', __METHOD__ );
            return false;
        }

        $filePathList = array();
        foreach( $rows as $row )
            $filePathList[] = $row['NAME'];

        return $filePathList;
    }

//////////////////////////////////////
//         Helper methods

//////////////////////////////////////

    // note: the mysql equivalent does not die anymore with this call...
    function _die( $msg, $sql = null )
    {
        if ( $this->db )
        {
            $error = oci_error( $this->db );
        }
        else
        {
            $error = oci_error();
        }
        eZDebug::writeError( $sql, "$msg: " . $error['message'] );
        eZDebug::writeError( $this->dbparams, "$msg: " . $error['message'] );

        /*if( @include_once( '../bt.php' ) )
        {
            bt();
        }*/
        //die( $msg );
    }

    /**
     Common select method for doing a SELECT query which is passed in $query and
     fetching one row from the result.
     If there are more than one row it will fail and exit, if 0 it returns false.
     The returned row is a numerical array.

     @param string $fname The function name that started the query, should contain relevant arguments in the text.
     @param mixed $error Sent to _error() in case of errors
     @param bool $debug If true it will display the fetched row in addition to the SQL.
     */
    function _selectOneRow( $query, $fname, $error = false, $debug = false, $bindparams = array() )
    {
        return $this->_selectOne( $query, $fname, $error, $debug, $bindparams, OCI_NUM+OCI_RETURN_NULLS );
    }

    /**
     Common select method for doing a SELECT query which is passed in $query and
     fetching one row from the result.
     If there are more than one row it will fail and exit, if 0 it returns false.
     The returned row is an associative array.

     @param string $fname The function name that started the query, should contain relevant arguments in the text.
     @param mixed $error Sent to _error() in case of errors
     @param bool $debug If true it will display the fetched row in addition to the SQL.
     */
    function _selectOneAssoc( $query, $fname, $error = false, $debug = false, $bindparams = array() )
    {
        return $this->_selectOne( $query, $fname, $error, $debug, $bindparams, OCI_ASSOC+OCI_RETURN_NULLS );
    }

    /**
     Common select method for doing a SELECT query which is passed in $query and
     fetching one row from the result.
     If there are more than one row it will fail and exit, if 0 it returns false.

     \param $fname The function name that started the query, should contain relevant arguments in the text.
     \param $error Sent to _error() in case of errors
     \param $debug If true it will display the fetched row in addition to the SQL.
     \param $fetchCall The callback to fetch the row.
     */
    function _selectOne( $query, $fname, $error = false, $debug = false, $bindparams = array(), $fetchOpts=OCI_BOTH )
    {
        eZDebug::accumulatorStart( 'oracle_cluster_query', 'oracle_cluster_total', 'Oracle_cluster_queries' );
        $time = microtime( true );

        $res = false;
        $this->error = false;
        if ( $statement = oci_parse( $this->db, $query ) )
        {
            foreach( $bindparams as $name => $val )
            {
                if ( !oci_bind_by_name( $statement, $name, $val, -1 ) )
                {
                    $this->error = oci_error( $statement );
                    $this->_error( $query, $fname, $error );
                }
            }
            if ( $res = oci_execute( $statement, OCI_DEFAULT ) )
            {
                $row = oci_fetch_array( $statement, $fetchOpts );
                $row2 = $row ? oci_fetch_array( $statement, $fetchOpts ) : false;
            }

            oci_free_statement( $statement );
        }
        else
        {
            // trick used for error reporting
            $statement = $this->db;
        }
        eZDebug::accumulatorStop( 'oracle_cluster_query' );
        if ( !$res )
        {
            $this->error = oci_error( $statement );
            $this->_error( $query, $fname, $error );
            return false;
        }

        if ( $row2 !== false )
        {
            $this->_error( $query, $fname, "Duplicate entries found." );
            // For PHP 5 throw an exception.
        }

        // Convert column names to lowercase.
        if ( $row && ( $fetchOpts & OCI_ASSOC ) )
        {
            foreach ( $row as $key => $val )
            {
                $row[strtolower( $key )] = $val;
                unset( $row[$key] );
            }
        }

        if ( $debug )
            $query = "SQL for _selectOne:\n" . $query . "\n\nRESULT:\n" . var_export( $row, true );

        $time = microtime( true ) - $time;

        $this->_report( $query, $fname, $time );
        return $row;
    }

    /**
      Starts a new transaction
      If a transaction is already started nothing is executed.
     */
    function _begin( $fname = false )
    {
        if ( $fname )
            $fname .= "::_begin";
        else
            $fname = "_begin";
        $this->transactionCount++;
        /// @todo set savepoint
        //if ( $this->transactionCount == 1 )
        //    $this->_query( "BEGIN", $fname );
    }

    /**
      Stops a current transaction and commits the changes by executing a COMMIT call.
      If the current transaction is a sub-transaction nothing is executed.
     */
    function _commit( $fname = false )
    {
        if ( $fname )
            $fname .= "::_commit";
        else
            $fname = "_commit";
        $this->transactionCount--;
        if ( $this->transactionCount == 0 )
            oci_commit( $this->db );
    }

    /**
      Stops a current transaction and discards all changes by executing a ROLLBACK call.
      If the current transaction is a sub-transaction nothing is executed.
     */
    function _rollback( $fname = false )
    {
        if ( $fname )
            $fname .= "::_rollback";
        else
            $fname = "_rollback";
        $this->transactionCount--;
        if ( $this->transactionCount == 0 )
            oci_rollback( $this->db );
        /*
        // for lack of UPSERTS, we commit file locking ASAP, so we must roll it back by hand
        if ( $this->lockedfile !== null )
        {

        }*/
    }

    /**
     Frees a previously open exclusive-lock by commiting the current transaction.

     Note: There is not checking to see if a lock is started, and if
           locking was done in an existing transaction nothing will happen.
     */
    function _freeExclusiveLock( $fname = false )
    {
        if ( $fname )
            $fname .= "::_freeExclusiveLock";
        else
            $fname = "_freeExclusiveLock";
        $this->_commit( $fname );
        // we delete any file still locked and yet unwritten
        //$this->lockedfile = null;
    }

    /**
     Locks the file entry for exclusive write access.

     The locking is performed by usage of SELECT FOR UPDATE

     Note: All reads of the row must be done with LOCK IN SHARE MODE.
     */
    function _exclusiveLock( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_exclusiveLock($filePath)";
        else
            $fname = "_exclusiveLock($filePath)";
        $tries = 0;
        $maxTries = $this->dbparams['max_execute_tries'];

        $name = $this->_escapeString( $filePath );
        $hash = md5( $filePath );
        //$sql = "BEGIN EZEXCLUSIVELOCK( :name, :hash ); END;";
        $sql = "BEGIN EZEXCLUSIVELOCK( '$name', '$hash' ); END;";
        while ( $tries < $maxTries )
        {
            // turn off error reporting
            //if ( $this->_query( $sql, $fname, false, array( ':name' => $filePath, ':hash' => md5( $filePath ) ) ) )
            if ( $this->_query( $sql, $fname, false ) )
                return true;
            $errno = $this->error['code'];
            // ORA 00001 dup val on index: in racy situations, we might get it...
            // ORA 00060 deadlock detected
            if ( $errno ==  1 || $errno == 60 )
            {
                $tries++;
                continue;
            }
            break;
        }
        return $this->_fail( "Failed to perform exclusive lock on file $filePath" );
    }


    /**
     A kind of two-phse-commit lock verification process - oracle does not need this,
     since _exclusiveLock works for good (locking is not advisory....)
     Ugly API needed for backward compatibility with code that was in ezDBFileHandler

     @return true if file is locked correctly, false otherwise
     */
    function _verifyExclusiveLock( $filePath, $expiry, $curtime, $ttl, $fname = false )
    {
        return true;
    }

    /**
     Creates an error object which can be read by some backend functions.

     \param $value The value which is sent to the debug system.
     \param $text The text/header for the value.
     */
    function _fail( $value, $text = false )
    {
        if ( $this->error )
        {
            $value .= "\n" . $this->error['code'] . ": " . $this->error['message'];
        }

        //include_once( 'kernel/classes/clusterfilehandlers/dbbackends/mysqlbackenderror.php' );
        return new eZMySQLBackendError( $value, $text );
    }

    /**
     Performs query and returns result/boolean/nr of rows.
     Times the sql execution, adds accumulator timings and reports SQL to debug.

     @param string $fname The function name that started the query, should contain relevant arguments in the text.
     */
    function _query( $query, $fname = false, $reportError = true, $bindparams = array(), $return_type = eZDBFileHandlerOracleBackend::RETURN_BOOL )
    {
        eZDebug::accumulatorStart( 'oracle_cluster_query', 'oracle_cluster_total', 'Oracle_cluster_queries' );
        $time = microtime( true );

        $this->error = null;
        $res = false;
        if ( $statement = oci_parse( $this->db, $query ) )
        {
            foreach( $bindparams as $name => $val )
            {
                if ( !oci_bind_by_name( $statement, $name, $val, -1 ) )
                {
                    $this->error = oci_error( $statement );
                    $this->_error( $query, $fname, $error );
                }
            }

            if ( ! $res = oci_execute( $statement, OCI_DEFAULT ) )
            {
                $this->error = oci_error( $statement );
            }
            else
            {
                if ( $return_type == eZDBFileHandlerOracleBackend::RETURN_COUNT )
                {
                    $res = oci_num_rows( $statement );
                }
                else if ( $return_type == eZDBFileHandlerOracleBackend::RETURN_DATA )
                {
                    oci_fetch_all( $statement, $res, 0, 0, OCI_FETCHSTATEMENT_BY_ROW+OCI_ASSOC );
                }
            }

            oci_free_statement( $statement );
        }
        else
        {
            $this->error = oci_error( $this->db );
        }

        // take care: 0 might be a valid result if RETURN_COUNT is used
        if ( $res === false && $reportError )
        {
            $this->_error( $query, $fname, false, $statement );
        }


        //$numRows = mysql_affected_rows( $this->db );

        $time = microtime( true ) - $time;
        eZDebug::accumulatorStop( 'oracle_cluster_query' );

        $this->_report( $query, $fname, $time, 0 );
        return $res;
    }

    /**
     Protects a custom function with SQL queries in a database transaction,
     if the function reports an error the transaction is ROLLBACKed.

     The first argument to the _protect() is the callback and the second is the name of the function (for query reporting). The remainder of arguments are sent to the callback.

     A return value of false from the callback is considered a failure, any other value is returned from _protect(). For extended error handling call _fail() and return the value.
     */
    function _protect()
    {
        $args = func_get_args();
        $callback = array_shift( $args );
        $fname    = array_shift( $args );

        $maxTries = $this->dbparams['max_execute_tries'];
        $tries = 0;
        while ( $tries < $maxTries )
        {
            /// @todo set a SAVEPOINT so that we can rollback to here only.
            /// NB: we need to setup a unique tid...
            //if ( $this->transactionCount == 0 )
            //    $this->_query( "SAVEPOINT " . $fname );
            $this->_begin();

            $result = call_user_func_array( $callback, $args );

            /// @todo find out all oracle error codes for locks / timeouts
            /// eg: 00060, 04020 ?, 00104 ?, 04027 ?, ...
            if ( $this->error )
            {
                $errno = $this->error['code'];
                if ( $errno == 60 ) //  ORA-00060: deadlock detected while waiting for resource
                {
                    $tries++;
                    $this->_rollback( $fname );
                    continue;
                }
            }

            if ( $result === false )
            {
                $this->transactionCount--;
                $this->_rollback( $fname );
                return false;
            }
            elseif ( $result instanceof eZMySQLBackendError )
            {
                eZDebug::writeError( $result->errorValue, $result->errorText );
                $this->_rollback( $fname );
                return false;
            }

            break; // All is good, so break out of loop
        }

        $this->_commit();
        return $result;
    }

    protected function _handleErrorType( $res )
    {
        if ( $res === false )
        {
            eZDebug::writeError( "SQL failed" );
        }
        elseif ( $res instanceof eZMySQLBackendError )
        {
            eZDebug::writeError( $res->errorValue, $res->errorText );
        }
    }

    /**
     * @private
     * @static
     */
    function _fetchLob( $filePath, $fname = '' )
    {
        $hash = md5( $filePath );
        $query = 'SELECT filesize, lob FROM ' . eZDBFileHandlerOracleBackend::TABLE_METADATA . " WHERE name_hash=:hash";
        $row = $this->_selectOneAssoc( $query, $fname, false, false, array( ':hash' => $hash ) );
        if ( $row )
        {
            $row['size'] = $row['filesize'];
            unset($row['size']);
        }
        return $row;
    }

    /**
     Make sure that $value is escaped and qouted and turned into and MD5.
     The returned value can directly be put into SQLs.
     */
    function _md5( $value )
    {
        return "'" . md5( $value ) . "'";
    }

    /**
     Prints error message to debug system.

     @param string $query The query that was attempted, will be printed if $error is \c false
     @param string $fname The function name that started the query, should contain relevant arguments in the text.
     @param mixed $error The error message, if this is an array the first element is the value to dump and the second the error header (for eZDebug::writeNotice). If this is \c false a generic message is shown.
     */
    function _error( $query, $fname, $error )
    {
        if ( $error === false )
        {
            eZDebug::writeError( "Failed to execute SQL for function:\n $query\n" . $this->error['code'] . " : " . $this->error['message'], "$fname" );
        }
        else if ( is_array( $error ) )
        {
            eZDebug::writeError( $error[0] . "\n" . $this->error['code'] . " : " . $this->error['message'], $error[1] );
        }
        else
        {
            eZDebug::writeError( $error . "\n" . $this->error['code'] . " : " . $this->error['message'], "$fname" );
        }
    }

    /**
     * @private
     * @static
     */
    function _escapeString( $str )
    {
        return str_replace ( "'", "''", $str );
    }

    /**
     Report SQL $query to debug system.

     @param string $fname The function name that started the query, should contain relevant arguments in the text.
     @param int $timeTaken Number of seconds the query + related operations took (as float).
     @param int $numRows Number of affected rows.
     */
    function _report( $query, $fname, $timeTaken, $numRows = false )
    {
        if ( !$this->dbparams['sql_output'] )
            return;

        $rowText = '';
        if ( $numRows !== false )
            $rowText = "$numRows rows, ";
        static $numQueries = 0;
        if ( strlen( $fname ) == 0 )
            $fname = "_query";
        $backgroundClass = ($this->transactionCount > 0  ? "debugtransaction transactionlevel-$this->transactionCount" : "");
        eZDebug::writeNotice( "$query", "cluster::oracle::{$fname}[{$rowText}" . number_format( $timeTaken, 3 ) . " ms] query number per page:" . $numQueries++, $backgroundClass );
    }

    /**
    * Attempts to begin cache generation by creating a new file named as the
    * given filepath, suffixed with .generating. If the file already exists,
    * insertion is not performed and false is returned (means that the file
    * is already being generated)
    * @param string $filePath
    * @return array array with 2 indexes: 'result', containing either ok or ko,
    *         and another index that depends on the result:
    *         - if result == 'ok', the 'mtime' index contains the generating
    *           file's mtime
    *         - if result == 'ko', the 'remaining' index contains the remaining
    *           generation time (time until timeout) in seconds
    **/
    function _startCacheGeneration( $filePath, $generatingFilePath )
    {
        $fname = "_startCacheGeneration( {$filePath} )";

        $nameHash = "'" . md5( $generatingFilePath ) . "'";
        $mtime = time();

        $insertData = array( 'name' => "'" . $this->_escapeString( $generatingFilePath ) . "'",
                             //'name_trunk' => "'" . $this->_escapeString( $generatingFilePath ) . "'",
                             'name_hash' => $nameHash,
                             'scope' => "''",
                             'datatype' => "''",
                             'mtime' => $mtime,
                             'expired' => 0 );
        $query = 'INSERT INTO ' . self::TABLE_METADATA . ' ( '. implode(', ', array_keys( $insertData ) ) . ' ) ' .
                 "VALUES(" . implode( ', ', $insertData ) . ")";

        if ( !$this->_query( $query, "_startCacheGeneration( $filePath )", false ) )
        {
            $errno = $this->error['code'];
            if ( $errno != 1 )
            {
                eZDebug::writeError( "Unexpected error #$errno when trying to start cache generation on $filePath ($errno)", __METHOD__ );
                eZDebug::writeDebug( $query, '$query' );

                // @todo Make this an actual error, maybe an exception
                return array( 'res' => 'ko' );
            }
            // error 00001 is expected, since it means duplicate key (file is being generated)
            else
            {
                // generation timout check
                $query = "SELECT mtime FROM " . self::TABLE_METADATA . " WHERE name_hash = $nameHash";
                $row = $this->_selectOneRow( $query, $fname, false, false );

                // file has been renamed, i.e it is no longer a .generating file
                if( $row and !isset( $row[0] ) )
                    return array( 'result' => 'ok', 'mtime' => $mtime );

                $remainingGenerationTime = $this->remainingCacheGenerationTime( $row );
                if ( $remainingGenerationTime < 0 )
                {
                    $previousMTime = $row[0];

                    eZDebugSetting::writeDebug( 'kernel-clustering', "$filePath generation has timedout (timeout={$this->dbparams['cache_generation_timeout']}), taking over", __METHOD__ );
                    $updateQuery = "UPDATE " . self::TABLE_METADATA . " SET mtime = {$mtime} WHERE name_hash = {$nameHash} AND mtime = {$previousMTime}";
                    eZDebug::writeDebug( $updateQuery, '$updateQuery' );

                    // we run the query manually since the default _query won't
                    // report affected rows
                    //$stmt = oci_parse( $this->db, $updateQuery );
                    //$res = oci_execute( $stmt );
                    $res = $this->_query( $updateQuery, $fname, false, array(), eZDBFileHandlerOracleBackend::RETURN_COUNT );
                    if ( $res === 1 )
                    {
                        return array( 'result' => 'ok', 'mtime' => $mtime );
                    }
                    else
                    {
                        // @todo This would require an actual error handling
                        $errno = $this->error['code'];
                        eZDebug::writeError( "An error occured taking over timedout generating cache file $generatingFilePath ($errno)", __METHOD__ );
                        return array( 'result' => 'error' );
                    }
                }
                else
                {
                    return array( 'result' => 'ko', 'remaining' => $remainingGenerationTime );
                }
            }
        }
        else
        {
            return array( 'result' => 'ok', 'mtime' => $mtime );
        }
    }

    /**
    * Ends the cache generation for the current file: moves the (meta)data for
    * the .generating file to the actual file, and removes the .generating
    * @param string $filePath
    * @return bool
    **/
    function _endCacheGeneration( $filePath, $generatingFilePath, $rename=true )
    {
        $fname = "_endCacheGeneration( $filePath )";

        eZDebugSetting::writeDebug( 'kernel-clustering', $filePath, __METHOD__ );

        $nameHash = "'" . md5( $generatingFilePath ) . "'";

        // if no rename is asked, the .generating file is just removed
        if ( $rename === false )
        {
            if ( !$this->_query( "DELETE FROM " . self::TABLE_METADATA . " WHERE name_hash=$nameHash" ) )
            {
                eZDebug::writeError( "Failed removing metadata entry for '$generatingFilePath'", $fname );
                return false;
            }
            else
            {
                return true;
            }
        }
        else
        {
            $this->_begin( $fname );

            $newPath = "'" . md5( $filePath ) . "'";

            // both files are locked for update
            if ( !$generatingMetaData = $this->_query( "SELECT * FROM " . self::TABLE_METADATA . " WHERE name_hash=$nameHash FOR UPDATE", $fname, true, array(), eZDBFileHandlerOracleBackend::RETURN_DATA ) )
            {
                $this->_rollback( $fname );
                return false;
            }
            //$generatingMetaData = mysql_fetch_assoc( $res );

            // we cannot use RETURN COUNT here, as it does not work with selects
            $res = $this->_query( "SELECT * FROM " . self::TABLE_METADATA . " WHERE name_hash=$newPath FOR UPDATE", $fname, false, array(), eZDBFileHandlerOracleBackend::RETURN_DATA );
            if ( $res && count( $res ) === 1 )
            {
                // the original file exists: we remove it before updating the .generating file
                if ( !$this->_query( "DELETE FROM " . self::TABLE_METADATA . " WHERE name_hash=$newPath", $fname, true ) )
                {
                    $this->_rollback( $fname );
                    return false;
                }
            }

            if ( !$this->_query( "UPDATE " . self::TABLE_METADATA . " SET name = '" . $this->_escapeString( $filePath ) . "', name_hash=$newPath WHERE name_hash=$nameHash", $fname, true ) )
            {
                $this->_rollback( $fname );
                return false;
            }

            $this->_commit( $fname );
        }

        return true;
    }

    /**
    * Checks if generation has timed out by looking for the .generating file
    * and comparing its timestamp to the one assigned when the file was created
    *
    * @param string $generatingFilePath
    * @param int    $generatingFileMtime
    *
    * @return bool true if the file didn't timeout, false otherwise
    **/
    function _checkCacheGenerationTimeout( $generatingFilePath, $generatingFileMtime )
    {
        $fname = "_checkCacheGenerationTimeout( $generatingFilePath, $generatingFileMtime )";
        eZDebugSetting::writeDebug( 'kernel-clustering', "Checking for timeout of '$generatingFilePath' with mtime $generatingFileMtime", $fname );

        // reporting
        eZDebug::accumulatorStart( 'oracle_cluster_query', 'oracle_cluster_total', 'Oracle_cluster_queries' );
        $time = microtime( true );

        $nameHash = "'" . md5( $generatingFilePath ) . "'";
        $newMtime = time();

        // The update query will only succeed if the mtime wasn't changed in between
        $query = "UPDATE " . self::TABLE_METADATA . " SET mtime = $newMtime WHERE name_hash = $nameHash AND mtime = $generatingFileMtime";
        $numRows = $this->_query( $query, $fname, false, array(), self::RETURN_COUNT );
        if ( $numRows === false )
        {
            $this->_error( $query, $fname );
            return false;
        }

        // rows affected: mtime has changed, or row has been removed
        if ( $numRows == 1 )
        {
            return true;
        }
        else
        {
            eZDebugSetting::writeDebug( 'kernel-clustering', "No rows affected by query '$query', record has been modified", __METHOD__ );
            return false;
        }
    }

    /**
    * Aborts the cache generation process by removing the .generating file
    * @param string $filePath Real cache file path
    * @param string $generatingFilePath .generating cache file path
    * @return void
    **/
    function _abortCacheGeneration( $generatingFilePath )
    {
        $sql = "DELETE FROM " . self::TABLE_METADATA . " WHERE name_hash = '" . md5( $generatingFilePath ) . "'";
        $this->_query( $sql, "_abortCacheGeneration( '$generatingFilePath' )" );
    }

    /**
     * Returns the remaining time, in seconds, before the generating file times
     * out
     *
     * @param resource $fileRow
     *
     * @return int Remaining generation seconds. A negative value indicates a timeout.
     **/
    private function remainingCacheGenerationTime( $row )
    {
        if( !isset( $row[0] ) )
            return -1;


        return ( $row[0] + $this->dbparams['cache_generation_timeout'] ) - time();
    }

    /**
     * Returns the list of expired binary files (images + binaries)
     *
     * @param array $scopes Array of scopes to consider. At least one.
     * @param int $limit Max number of items. Set to false for unlimited.
     *
     * @return array(filepath)
     *
     * @since 4.3
     */
    public function expiredFilesList( $scopes, $limit = array( 0, 100 ) )
    {
        /*if ( count( $scopes ) == 0 )
            throw new ezcBaseValueException( 'scopes', $scopes, "array of scopes", "parameter" );

        foreach ( $scopes as $key => $val )
        {
            $scopes[$key] = str_replace("'", "''", $val );
        }
        $scopeString = "'" . implode( "', '", $scopes ) . "'";
        $query = "SELECT name FROM " . self::TABLE_METADATA . " WHERE expired = 1 AND scope IN( $scopeString )";
        if ( $limit !== false )
        {
            $query .= " LIMIT {$limit[0]}, {$limit[1]}";
        }
        $res = $this->_query( $query, __METHOD__, true, array(), eZDBFileHandlerOracleBackend::RETURN_DATA );
        $filePathList = array();
        while ( $row = mysql_fetch_row( $res ) )
            $filePathList[] = $row[0];*/

        return array();
    }

    public $db = null;
    public $transactionCount = 0;
    var $dbparams = null;
    //var $lockedfile = null;
    var $error = false;
}

?>
