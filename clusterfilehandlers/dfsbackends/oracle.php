<?php
//
// Definition of eZDFSFileHandlerOracleBackend class
//
// Created on: <14-Oct-2009 11:28:15 gg>
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

/*
   This is the structure / SQL CREATE for the DFS database table.
   It can be created anywhere, in the same database on the same server, or on a
   distinct database / server.

CREATE TABLE ezdfsfile (
  name      varchar2(4000) NOT NULL,
  -- name_trunk varchar2(4000) NOT NULL,
  name_hash varchar(34)    PRIMARY KEY,
  datatype  varchar2(60)   DEFAULT 'application/octet-stream',
  scope     varchar(25)    DEFAULT '',
  filesize  INT            DEFAULT 0 NOT NULL,
  mtime     INT            DEFAULT 0 NOT NULL,
  expired   char(1)        DEFAULT '0' NOT NULL,
  status    char(1)        DEFAULT '0' NOT NULL
);
CREATE INDEX ezdfsfile_name ON ezdfsfile( name );
--CREATE INDEX ezdfsfile_name_trunk ON ezdfsfile( name_trunk );
CREATE INDEX ezdfsfile_mtime ON ezdfsfile( mtime );
--CREATE INDEX ezdfsfile_expired_name ON ezdfsfile( expired, name );

*/

class eZDFSFileHandlerOracleBackend
{
    /**
     * Connects to the database.
     *
     * @return void
     * @throw eZClusterHandlerDBNoConnectionException
     * @throw eZClusterHandlerDBNoDatabaseException
     **/
    public function _connect()
    {
        if ( !function_exists( 'oci_connect' ) )
            throw new eZClusterHandlerDBNoDatabaseException( "PECL oci8 extension (http://pecl.php.net/package/oci8) is required to use Oracle clustering functionality." );

        // DB Connection setup
        // This part is not actually required since _connect will only be called
        // once, but it is useful to run the unit tests. So be it.
        // @todo refactor this using eZINI::setVariable in unit tests
        if ( self::$dbparams === null )
        {
            $siteINI = eZINI::instance( 'site.ini' );
            $fileINI = eZINI::instance( 'file.ini' );

            self::$dbparams = array();
            //self::$dbparams['host']       = $fileINI->variable( 'eZDFSClusteringSettings', 'DBHost' );
            //self::$dbparams['port']       = $fileINI->variable( 'eZDFSClusteringSettings', 'DBPort' );
            //self::$dbparams['socket']     = $fileINI->variable( 'eZDFSClusteringSettings', 'DBSocket' );
            self::$dbparams['dbname']     = $fileINI->variable( 'eZDFSClusteringSettings', 'DBName' );
            self::$dbparams['user']       = $fileINI->variable( 'eZDFSClusteringSettings', 'DBUser' );
            self::$dbparams['pass']       = $fileINI->variable( 'eZDFSClusteringSettings', 'DBPassword' );

            self::$dbparams['max_connect_tries'] = $fileINI->variable( 'eZDFSClusteringSettings', 'DBConnectRetries' );
            self::$dbparams['max_execute_tries'] = $fileINI->variable( 'eZDFSClusteringSettings', 'DBExecuteRetries' );

            self::$dbparams['sql_output'] = $siteINI->variable( "DatabaseSettings", "SQLOutput" ) == "enabled";

            self::$dbparams['cache_generation_timeout'] = $siteINI->variable( "ContentSettings", "CacheGenerationTimeout" );

            self::$dbparams['persistent_connection'] = $fileINI->hasVariable( 'eZDFSClusteringSettings', 'DBPersistentConnection' ) ? ( $fileINI->variable( 'eZDFSClusteringSettings', 'DBPersistentConnection' ) == 'enabled' ) : false;
        }

        $maxTries = self::$dbparams['max_connect_tries'];
        $tries = 0;
        while ( $tries < $maxTries )
        {
            if ( self::$dbparams['persistent_connection'] )
            {
                if ( $this->db = oci_pconnect( self::$dbparams['user'], self::$dbparams['pass'], self::$dbparams['dbname'] ) )
                    break;
            }
            else
            {
                if ( $this->db = oci_connect( self::$dbparams['user'], self::$dbparams['pass'], self::$dbparams['dbname'] ) )
                    break;
            }
            ++$tries;
        }
        if ( !$this->db )
            throw new eZClusterHandlerDBNoConnectionException( self::$params['dbname'], self::$dbparams['user'], self::$dbparams['pass'] );

        // DFS setup
        if ( $this->dfsbackend === null )
        {
            $this->dfsbackend = new eZDFSFileHandlerDFSBackend();
        }
    }

    /**
     * Creates a copy of a file in DB+DFS
     * @param string $srcFilePath Source file
     * @param string $dstFilePath Destination file
     * @param string $fname
     * @return bool
     *
     * @see _copyInner
     **/
    public function _copy( $srcFilePath, $dstFilePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_copy($srcFilePath, $dstFilePath)";
        else
            $fname = "_copy($srcFilePath, $dstFilePath)";

        // fetch source file metadata
        $metaData = $this->_fetchMetadata( $srcFilePath, $fname );
        // if source file does not exist then do nothing.
        // @todo Throw an exception here.
        //       Info: $srcFilePath
        if ( !$metaData )
        {
            return false;
        }
        return $this->_protect( array( $this, "_copyInner" ), $fname,
                                $srcFilePath, $dstFilePath, $fname, $metaData );
    }

    /**
     * Inner function used by _copy to perform the operation in a transaction
     *
     * @param string $srcFilePath
     * @param string $dstFilePath
     * @param bool   $fname
     * @param array  $metaData Source file's metadata
     * @return bool
     *
     * @see _copy
     */
    private function _copyInner( $srcFilePath, $dstFilePath, $fname, $metaData )
    {
        $this->_delete( $dstFilePath, true, $fname );

        $filePathEscaped = $this->_escapeString( $dstFilePath );
        $filePathHash    = md5( $dstFilePath );
        $datatype        = $metaData['datatype'];
        $scope           = $metaData['scope'];
        $contentLength   = $metaData['size'];
        $fileMTime       = $metaData['mtime'];
        //$nameTrunk       = self::nameTrunk( $dstFilePath, $scope );

        /// @todo move to stored params convention
        $name = $this->_escapeString( $dstFilePath );
        $hash = md5( $dstFilePath );
        $sql  = "INSERT INTO " . self::TABLE_METADATA . " (datatype, name, name_hash, scope, filesize, mtime, expired) " .
                "VALUES ('$datatype', '$filePathEscaped', '$filePathHash', '$datatype', '$scope', " .
                "$contentLength, $fileMTime, '0')";

        $return = $this->_query( $sql, $fname, true, array(), self::RETURN_COUNT );
        // if the insertion affects 0 rows, we have to rollback the same way as
        // if there was an error executing the query
        if ( !$return )
        {
            return $this->_fail( $srcFilePath, "Failed to insert file metadata on copying." );
        }

        // Copy file data.
        if ( !$this->dfsbackend->copyFromDFSToDFS( $srcFilePath, $dstFilePath ) )
        {
            return $this->_fail( $srcFilePath, "Failed to copy DFS://$srcFilePath to DFS://$dstFilePath" );
        }
        return true;
    }

    /**
     * Purges meta-data and file-data for a file entry
     *
     * Will only expire a single file. Use _purgeByLike to purge multiple files
     *
     * @param string $filePath Path of the file to purge
     * @param bool $onlyExpired Only purges expired files
     * @param bool|int $expiry
     * @param bool $fname
     *
     * @see _purgeByLike
     **/
    public function _purge( $filePath, $onlyExpired = false, $expiry = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_purge($filePath)";
        else
            $fname = "_purge($filePath)";
        $sql = "DELETE FROM " . self::TABLE_METADATA . " WHERE name_hash=:hash";
        $params = array( ':hash' => md5( $filePath ) );
        if ( $expiry !== false )
        {
            $sql .= " AND mtime < :expiry";
            $params[':expiry'] = $expiry;
        }
        elseif ( $onlyExpired )
            $sql .= " AND expired = '1'";
        if ( ( $deleted = $this->_query( $sql, $fname, true, $params, self::RETURN_COUNT ) ) === false )
            return $this->_fail( "Purging file metadata for $filePath failed" );
        if ( $deleted == 1 )
        {
            $this->dfsbackend->delete( $filePath );
        }
        return true;
    }

    /**
     * Purges meta-data and file-data for files matching a pattern using a SQL
     * LIKE syntax.
     *
     * @param string $like
     *        SQL LIKE string applied to ezdfsfile.name to look for files to
     *        purge
     * @param bool $onlyExpired
     *        Only purge expired files (ezdfsfile.expired = 1)
     * @param integer $limit Maximum number of items to purge in one call
     * @param integer $expiry
     *        Timestamp used to limit deleted files: only files older than this
     *        date will be deleted
     * @param mixed $fname Optional caller name for debugging
     * @see _purge
     * @return bool|int false if it fails, number of affected rows otherwise
     * @todo This method should also remove the files from disk
     */
    public function _purgeByLike( $like, $onlyExpired = false, $limit = 50, $expiry = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_purgeByLike($like, $onlyExpired)";
        else
            $fname = "_purgeByLike($like, $onlyExpired)";

        // common query part used for both DELETE and SELECT
        $where = " WHERE name LIKE :alike";
        $params = array ( ':alike' => $like );
        if ( $expiry !== false )
        {
            $where .= " AND mtime < :expiry";
            $params[':expiry'] = $expiry;
        }
        elseif ( $onlyExpired )
            $where .= " AND expired = '1'";
        /// @todo use a bind param for rownum (is it even possible ?)
        if ( $limit )
            $where .= " and ROWNUM <= $limit";

        $this->_begin( $fname );

        // select query, in FOR UPDATE mode
        $selectSQL = "SELECT name FROM " . self::TABLE_METADATA .
                     "{$where} FOR UPDATE";
        if ( ( $files = $this->_query( $selectSQL, $fname, true, $params, self::RETURN_DATA_BY_COL ) ) === false )
        {
            $this->_rollback( $fname );
            return $this->_fail( "Selecting file metadata by like statement $like failed" );
        }
        $resultCount = count( $files['NAME'] );

        // if there are no results, we can just return 0 and stop right here
        if ( $resultCount == 0 )
        {
            /// @bug: should we not commit here instead of rolling back ???
            $this->_rollback( $fname );
            return 0;
        }

        // delete query
        /// @bug what if other rows have been added / removed that match our conditions
        ///      in the meantime? we should use a condition of the form WHERE name_hash IN ( ... )
        $deleteSQL = "DELETE FROM " . self::TABLE_METADATA . " {$where}";
        if ( !$res = $this->_query( $deleteSQL, $fname, true, $params, self::RETURN_COUNT ) )
        {
            $this->_rollback( $fname );
            return $this->_fail( "Purging file metadata by like statement $like failed" );
        }

        if ( $res != $resultCount )
        {
            eZDebug::writewarning( "Mismatch in files to be deleted count when purging like '$like'.", __METHOD__ );
        }

        $this->dfsbackend->delete( $files['NAME'] );

        $this->_commit( $fname );

        return $res;
    }

    /**
     * Deletes a file from DB
     *
     * The file won't be removed from disk, _purge has to be used for this.
     * Only single files will be deleted, to delete multiple files,
     * _deleteByLike has to be used.
     *
     * @param string $filePath Path of the file to delete
     * @param bool $insideOfTransaction
     *        Wether or not a transaction is already started
     * @param bool|string $fname Optional caller name for debugging
     * @see _deleteInner
     * @see _deleteByLike
     * @return bool
     */
    public function _delete( $filePath, $insideOfTransaction = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_delete($filePath)";
        else
            $fname = "_delete($filePath)";
        // @todo Check if this is requried: _protect will already take care of
        //       checking if a transaction is running. But leave it like this
        //       for now.
        if ( $insideOfTransaction )
        {
            $res = $this->_deleteInner( $filePath, $fname );
            if ( !$res || $res instanceof eZMySQLBackendError )
            {
                $this->_handleErrorType( $res );
            }
        }
        else
        {
            return $this->_protect( array( $this, '_deleteInner' ), $fname,
                                    $filePath, $fname );
        }
    }

    /**
     * Callback method used by by _delete to delete a single file
     *
     * @param string $filePath Path of the file to delete
     * @param string $fname Optional caller name for debugging
     * @return bool
     **/
    protected function _deleteInner( $filePath, $fname )
    {
        $hash = md5( $filePath );
        $sql = self::$deletequery . "WHERE name_hash=:hash";
        if ( !$this->_query( $sql, $fname, true, array( ':hash' => $hash ) ) )
            return $this->_fail( "Deleting file $filePath failed" );
        return true;
    }

    /**
     * Deletes multiple files using a SQL LIKE statement
     *
     * Use _delete if you need to delete single files
     *
     * @param string $like
     *        SQL LIKE condition applied to ezdfsfile.name to look for files
     *        to delete. Will use name_trunk if the LIKE string matches a
     *        filetype that supports name_trunk.
     * @param string $fname Optional caller name for debugging
     * @return bool
     * @see _deleteByLikeInner
     * @see _delete
     */
    public function _deleteByLike( $like, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByLike($like)";
        else
            $fname = "_deleteByLike($like)";
        return $this->_protect( array( $this, '_deleteByLikeInner' ), $fname,
                                $like, $fname );
    }

    /**
     * Callback used by _deleteByLike to perform the deletion
     *
     * @param string $like
     * @param mixed $fname
     * @return
     */
    private function _deleteByLikeInner( $like, $fname )
    {
        $sql = self::$deletequery . "WHERE name LIKE :alike" ;
        if ( !$res = $this->_query( $sql, $fname, true, array ( ':alike' => $like ) ) )
        {
            return $this->_fail( "Failed to delete files by like: '$like'" );
        }
        return true;
    }

    /**
     * Deletes DB files by using a SQL regular expression applied to file names
     *
     * @param string $regex
     * @param mixed $fname
     * @return bool
     * @deprecated Has severe performance issues
     */
    public function _deleteByRegex( $regex, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByRegex($regex)";
        else
            $fname = "_deleteByRegex($regex)";
        return $this->_protect( array( $this, '_deleteByRegexInner' ), $fname,
                                $regex, $fname );
    }

    /**
     * Callback used by _deleteByRegex to perform the deletion
     *
     * @param mixed $regex
     * @param mixed $fname
     * @return
     * @deprecated Has severe performances issues
     */
    public function _deleteByRegexInner( $regex, $fname )
    {
        $sql = self::$deletequery . "WHERE REGEXP_LIKE( name, :escapedRegex )";
        if ( !$res = $this->_query( $sql, $fname, true, array( ':escapedRegex' => $regex ) ) )
        {
            return $this->_fail( "Failed to delete files by regex: '$regex'" );
        }
        return true;
    }

    /**
     * Deletes multiple DB files by wildcard
     *
     * @param string $wildcard
     * @param mixed $fname
     * @return bool
     * @deprecated Has severe performance issues
     */
    public function _deleteByWildcard( $wildcard, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByWildcard($wildcard)";
        else
            $fname = "_deleteByWildcard($wildcard)";
        return $this->_protect( array( $this, '_deleteByWildcardInner' ), $fname,
                                $wildcard, $fname );
    }

    /**
     * Callback used by _deleteByWildcard to perform the deletion
     *
     * @param mixed $wildcard
     * @param mixed $fname
     * @return bool
     * @deprecated Has severe performance issues
     */
    protected function _deleteByWildcardInner( $wildcard, $fname )
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

        $sql = self::$deletequery . "WHERE REGEXP_LIKE( name, :escapedRegex )";
        if ( !$res = $this->_query( $sql, $fname, true, array( ':escapedRegex' => $regex ) ) )
        {
            return $this->_fail( "Failed to delete files by regex: '$regex'" );
        }
        return true;
    }

    public function _deleteByDirList( $dirList, $commonPath, $commonSuffix, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByDirList($dirList, $commonPath, $commonSuffix)";
        else
            $fname = "_deleteByDirList($dirList, $commonPath, $commonSuffix)";
        return $this->_protect( array( $this, '_deleteByDirListInner' ), $fname,
                                $dirList, $commonPath, $commonSuffix, $fname );
    }

    protected function _deleteByDirListInner( $dirList, $commonPath, $commonSuffix, $fname )
    {
        $result = true;
        $this->error = false;
        $like = ''; // not sure it is necessary to initialize, but in case...
        $sql = self::$deletequery . "WHERE name LIKE :alike" ;
        /// @todo !important test that oci_parse went ok, and oci_bind_by_name too
        $statement = oci_parse( $this->db, $sql );
        oci_bind_by_name( $statement, ':alike', $like, 4000 );

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

    public function _exists( $filePath, $fname = false, $ignoreExpiredFiles = true )
    {
        if ( $fname )
            $fname .= "::_exists($filePath)";
        else
            $fname = "_exists($filePath)";
        $hash = md5( $filePath );
        $sql = "SELECT mtime, expired FROM " . self::TABLE_METADATA . " WHERE name_hash=:hash";

        if ( ! $row = $this->_selectOneAssoc( $sql, $fname, false, false, array( ':hash' => $hash ) ) )
        {
            return false;
        }
        /// @todo should not we check 'expired', too?
        /// @todo fix this before enabling logical deletes: only test if $ignoreExpiredFiles
        return $row['mtime'] >= 0;
    }

    protected function __mkdir_p( $dir )
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
     * Fetches the file $filePath from the database to its own name
     *
     * Saving $filePath locally with its original name, or $uniqueName if given
     *
     * @param string $filePath
     * @param string $uniqueName Alternative name to save the file to
     * @return string|bool the file physical path, or false if fetch failed
     **/
    public function _fetch( $filePath, $uniqueName = false )
    {
        if ( $fname )
            $fname .= "::_fetch($filePath)";
        else
            $fname = "_fetch($filePath)";
        $metaData = $this->_fetchMetadata( $filePath, $fname );
        if ( !$metaData )
        {
            // @todo Throw an exception
            eZDebug::writeError( "File '$filePath' does not exist while trying to fetch.", __METHOD__ );
            return false;
        }
        //$contentLength = $metaData['size'];

        // create temporary file
        if ( strrpos( $filePath, '.' ) > 0 )
            $tmpFilePath = substr_replace( $filePath, getmypid().'tmp', strrpos( $filePath, '.' ), 0  );
        else
            $tmpFilePath = $filePath . '.' . getmypid().'tmp';
        $this->__mkdir_p( dirname( $tmpFilePath ) );

        // copy DFS file to temporary FS path
        // @todo Throw an exception
        if ( !$this->dfsbackend->copyFromDFS( $filePath, $tmpFilePath ) )
        {
            eZDebug::writeError("Failed copying DFS://$filePath to FS://$tmpFilePath ");
            return false;
        }

        // Make sure all data is written correctly
        clearstatcache();
        $tmpSize = filesize( $tmpFilePath );
        // @todo Throw an exception
        if ( $tmpSize != $metaData['size'] )
        {
            eZDebug::writeError( "Size ($tmpSize) of written data for file '$tmpFilePath' does not match expected size " . $metaData['size'], __METHOD__ );
            return false;
        }

        if ( $uniqueName !== true )
        {
            eZFile::rename( $tmpFilePath, $filePath );
        }
        else
        {
            $filePath = $tmpFilePath;
        }

        return $filePath;
    }

    public function _fetchContents( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_fetchContents($filePath)";
        else
            $fname = "_fetchContents($filePath)";
        $metaData = $this->_fetchMetadata( $filePath, $fname );
        // @todo Throw an exception
        if ( !$metaData )
        {
            eZDebug::writeError( "File '$filePath' does not exist while trying to fetch its contents.", __METHOD__ );
            return false;
        }
        //$contentLength = $metaData['size'];

        // @todo Catch an exception
        if ( !$contents = $this->dfsbackend->getContents( $filePath ) )
        {
            eZDebug::writeError("An error occured while reading contents of DFS://$filePath", __METHOD__ );
            return false;
        }
        return $contents;
    }

    /**
     * Fetches and returns metadata for $filePath
     * @return array|false file metadata, or false if the file does not exist in
     *                     database.
     */
    function _fetchMetadata( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_fetchMetadata($filePath)";
        else
            $fname = "_fetchMetadata($filePath)";
        $hash = md5( $filePath );
        $sql  = "SELECT datatype,name,name_hash,scope,filesize,mtime,expired " .
                "FROM " . self::TABLE_METADATA . " WHERE name_hash=:hash" ;

        $row = $this->_selectOneAssoc( $sql, $fname, false, false, array( ':hash' => $hash ) );

        // Hide that Oracle cannot handle 'size' column.
        if ( $row )
        {
            $row['size'] = $row['filesize'];
            unset( $row['filesize'] );
        }

        return $row;
    }

    public function _linkCopy( $srcPath, $dstPath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_linkCopy($srcPath,$dstPath)";
        else
            $fname = "_linkCopy($srcPath,$dstPath)";
        return $this->_copy( $srcPath, $dstPath, $fname );
    }

    /**
     * Passes $filePath content through
     * @param string $filePath
     * @deprecated should not be used since it cannot handle reading errors
     **/
    public function _passThrough( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_passThrough($filePath)";
        else
            $fname = "_passThrough($filePath)";

        $metaData = $this->_fetchMetadata( $filePath, $fname );
        // @todo Throw an exception
        if ( !$metaData )
            return false;

        // @todo Catch an exception
        $this->dfsbackend->passthrough( $filePath );

        return true;
    }

    /**
     * Renames $srcFilePath to $dstFilePath
     *
     * @param string $srcFilePath
     * @param string $dstFilePath
     * @return bool
     */
    public function _rename( $srcFilePath, $dstFilePath )
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
        $sql = "UPDATE " . self::TABLE_METADATA . " SET name='$name', name_hash='$hash' WHERE name_hash=:name_hash";

        // we count the rows updated: if zero, it means another transaction
        // removed the src file after we fetched metadata above, so we rollback
        $return = $this->_query( $sql, $fname, true, array(
            /*':name' => $name, ':hash' => $hash,*/ ':name_hash' => $metaData['name_hash'] ), self::RETURN_COUNT );
        if ( $return === 0 )
        {
            $return = false;
        }

        if ( $return )
        {
            $return = $this->dfsbackend->renameOnDFS( $srcFilePath, $dstFilePath );
        }
        return $return;
    }

    /**
     * Stores $filePath to cluster
     *
     * @param string $filePath
     * @param string $datatype
     * @param string $scope
     * @param string $fname
     * @return void
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

    /**
     * Callback function used to perform the actual file store operation
     * @param string $filePath
     * @param string $datatype
     * @param string $scope
     * @param string $fname
     * @see eZDFSFileHandlerMySQLBackend::_store()
     * @return bool
     **/
    function _storeInner( $filePath, $datatype, $scope, $fname )
    {
        // Insert file metadata.
        clearstatcache();
        $fileMTime = (int)filemtime( $filePath );
        $contentLength = (int)filesize( $filePath );
        $filePathHash = md5( $filePath );
        //$nameTrunk = self::nameTrunk( $filePath, $scope );
        $filePathEscaped = $this->_escapeString( $filePath );
        $datatype = $this->_escapeString( $datatype );
        $scope = $this->_escapeString( $scope );

        // Check if a file with the same name already exists in db.
        if ( $row = $this->_fetchMetadata( $filePath ) ) // if it does
        {
            $sql  = "UPDATE " . set::TABLE_METADATA . " SET " .
                    //"name='$filePathEscaped', name_hash='$filePathHash', " .
                    "datatype='$datatype', scope='$scope', " .
                    "filesize=$contentLength, mtime=$fileMTime, expired='0' " .
                    "WHERE name_hash='$filePathHash'";
        }
        else // else if it doesn't
        {
            // create file in db
            $sql  = "INSERT INTO " . self::TABLE_METADATA . " (name, name_hash, datatype, scope, filesize, mtime, expired) " .
                    "VALUES ('$filePathEscaped', '$filePathHash', '$datatype', '$scope', " .
                    "$contentLength, $fileMTime, '0')";
        }

        /// @todo move to stored params convention
        $return = $this->_query( $sql, $fname, true, array(), self::RETURN_COUNT );
        if ( !$return )
        {
            return $this->_fail( "Failed to insert file metadata while storing. Possible race condition" );
        }

        // copy given $filePath to DFS
        if ( !$this->dfsbackend->copyToDFS( $filePath ) )
        {
            return $this->_fail( "Failed to copy FS://$filePath to DFS://$filePath" );
        }

        return true;
    }

    /**
     * Stores $contents as the contents of $filePath to the cluster
     *
     * @param string $filePath
     * @param string $contents
     * @param string $scope
     * @param string $datatype
     * @param int $mtime
     * @param string $fname
     * @return void
     */
    function _storeContents( $filePath, $contents, $scope, $datatype, $mtime = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_storeContents($filePath, ..., $scope, $datatype)";
        else
            $fname = "_storeContents($filePath, ..., $scope, $datatype)";

        $this->_protect( array( $this, '_storeContentsInner' ), $fname,
                         $filePath, $contents, $scope, $datatype, $mtime, $fname );
    }

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
            $sql  = "UPDATE " . self::TABLE_METADATA . " SET " .
                //"name='$filePathEscaped', name_hash='$filePathHash', " .
                "datatype='$datatype', scope='$scope', " .
                "filesize=$contentLength, mtime=$mtime, expired='$expired' " .
                "WHERE name_hash='$filePathHash'";
        }
        else // else if it doesn't
        {
            // create file in db
            $sql  = "INSERT INTO " . self::TABLE_METADATA . " (name, name_hash, datatype, scope, filesize, mtime, expired) " .
                    "VALUES ('$filePathEscaped', '$filePathHash', '$datatype', '$scope', " .
                    "'$contentLength', $mtime, '$expired')";
        }

        /// @todo move to stored params convention
        $return = $this->_query( $sql, $fname, true, array(), self::RETURN_COUNT );
        if ( !$return )
        {
            return $this->_fail( "Failed to insert file metadata while storing contents. Possible race condition" );
        }

        if ( !$this->dfsbackend->createFileOnDFS( $filePath, $contents ) )
        {
            return $this->_fail( "Failed to open DFS://$filePath for writing" );
        }

        return true;
    }

    public function _getFileList( $scopes = false, $excludeScopes = false )
    {
        $query = 'SELECT name FROM ' . self::TABLE_METADATA;

        if ( is_array( $scopes ) && count( $scopes ) > 0 )
        {
            $query .= ' WHERE scope ';
            if ( $excludeScopes )
                $query .= 'NOT ';
            $query .= "IN ('" . implode( "', '", $scopes ) . "')";
        }

        $rows = $this->_query( $query, "_getFileList( array( " . implode( ', ', $scopes ) . " ), $excludeScopes )", true, array(), self::RETURN_DATA );
        if ( $rows === false )
        {
            eZDebug::writeDebug( 'Unable to get file list', __METHOD__ );
            // @todo Throw an exception
            return false;
        }

        $filePathList = array();
        foreach( $rows as $row )
            $filePathList[] = $row['NAME'];

        return $filePathList;
    }

    /**
     * Handles a DB error, displaying it as an eZDebug error
     * @see eZDebug::writeError
     * @param string $msg Message to display
     * @param string $sql SQL query to display error for
     * @return void
     **/
    protected function _die( $msg, $sql = null )
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
        eZDebug::writeError( self::$dbparams, "$msg: " . $error['message'] );
    }

    /**
     * Runs a select query and returns one numeric indexed row from the result
     * If there are more than one row it will fail and exit, if 0 it returns
     * false.
     *
     * @param string $query
     * @param string $fname The function name that started the query, should
     *                      contain relevant arguments in the text.
     * @param string $error Sent to _error() in case of errors
     * @param bool   $debug If true it will display the fetched row in addition
     *                      to the SQL.
     * @return array|false
     **/
    protected function _selectOneRow( $query, $fname, $error = false, $debug = false, $bindparams = array() )
    {
        return $this->_selectOne( $query, $fname, $error, $debug, $bindparams, OCI_NUM+OCI_RETURN_NULLS );
    }

    /**
     * Runs a select query and returns one associative row from the result.
     *
     * If there are more than one row it will fail and exit, if 0 it returns
     * false.
     *
     * @param string $query
     * @param string $fname The function name that started the query, should
     *                      contain relevant arguments in the text.
     * @param string $error Sent to _error() in case of errors
     * @param bool   $debug If true it will display the fetched row in addition
     *                      to the SQL.
     * @return array|false
     **/
    protected function _selectOneAssoc( $query, $fname, $error = false, $debug = false, $bindparams = array() )
    {
        return $this->_selectOne( $query, $fname, $error, $debug, $bindparams, OCI_ASSOC+OCI_RETURN_NULLS );
    }

    /**
     * Runs a select query, applying the $fetchCall callback to one result
     * If there are more than one row it will fail and exit, if 0 it returns false.
     *
     * @param string $fname The function name that started the query, should
     *                      contain relevant arguments in the text.
     * @param string $error Sent to _error() in case of errors
     * @param bool $debug If true it will display the fetched row in addition to the SQL.
     * @param callback $fetchCall The callback to fetch the row.
     * @return mixed
     **/
    protected function _selectOne( $query, $fname, $error = false, $debug = false, $bindparams = array(), $fetchOpts=OCI_BOTH )
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
     * Starts a new transaction by executing a BEGIN call.
     * If a transaction is already started nothing is executed.
     **/
    protected function _begin( $fname = false )
    {
        if ( $fname )
            $fname .= "::_begin";
        else
            $fname = "_begin";
        $this->transactionCount++;
        /// @todo set savepoint
    }

    /**
     * Stops a current transaction and commits the changes by executing a COMMIT call.
     * If the current transaction is a sub-transaction nothing is executed.
     **/
    protected function _commit( $fname = false )
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
     * Stops a current transaction and discards all changes by executing a
     * ROLLBACK call.
     * If the current transaction is a sub-transaction nothing is executed.
     **/
    protected function _rollback( $fname = false )
    {
        if ( $fname )
            $fname .= "::_rollback";
        else
            $fname = "_rollback";
        $this->transactionCount--;
        if ( $this->transactionCount == 0 )
            oci_rollback( $this->db );
    }

    /**
     * Protects a custom function with SQL queries in a database transaction.
     * If the function reports an error the transaction is ROLLBACKed.
     *
     * The first argument to the _protect() is the callback and the second is the
     * name of the function (for query reporting). The remainder of arguments are
     * sent to the callback.
     *
     * A return value of false from the callback is considered a failure, any
     * other value is returned from _protect(). For extended error handling call
     * _fail() and return the value.
     **/
    protected function _protect()
    {
        $args = func_get_args();
        $callback = array_shift( $args );
        $fname    = array_shift( $args );

        $maxTries = self::$dbparams['max_execute_tries'];
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

            /// @todo replace with an exception
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
     * Creates an error object which can be read by some backend functions.
     * @param mixed $value The value which is sent to the debug system.
     * @param string $text The text/header for the value.
     **/
    protected function _fail( $value, $text = false )
    {
        if ( $this->error )
        {
            $value .= "\n" . $this->error['code'] . ": " . $this->error['message'];
        }

        return new eZMySQLBackendError( $value, $text );
    }

    /**
     * Performs mysql query and returns mysql result.
     * Times the sql execution, adds accumulator timings and reports SQL to
     * debug.
     * @param string $fname The function name that started the query, should
     *                      contain relevant arguments in the text.
     **/
    protected function _query( $query, $fname = false, $reportError = true, $bindparams = array(), $return_type = self::RETURN_BOOL )
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
                if ( $return_type == self::RETURN_COUNT )
                {
                    $res = oci_num_rows( $statement );
                }
                else if ( $return_type == self::RETURN_DATA )
                {
                    oci_fetch_all( $statement, $res, 0, 0, OCI_FETCHSTATEMENT_BY_ROW+OCI_ASSOC );
                }
                else if ( $return_type == self::RETURN_DATA_BY_COL )
                {
                    oci_fetch_all( $statement, $res, 0, 0, OCI_FETCHSTATEMENT_BY_COLUMN+OCI_ASSOC );
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
     * @static
     */
    protected function _escapeString( $str )
    {
        return str_replace ( "'", "''", $str );
    }

    /**
     * Provides the SQL calls to convert $value to MD5
     * The returned value can directly be put into SQLs.
     **/
    protected function _md5( $value )
    {
        return "'" . md5( $value ) . "'";
    }

    /**
     * Prints error message $error to debug system.
     * @param string $query The query that was attempted, will be printed if
     *                      $error is \c false
     * @param string $fname The function name that started the query, should
     *                      contain relevant arguments in the text.
     * @param string $error The error message, if this is an array the first
     *                      element is the value to dump and the second the error
     *                      header (for eZDebug::writeNotice). If this is \c
     *                      false a generic message is shown.
     */
    protected function _error( $query, $fname, $error = "Failed to execute SQL for function:" )
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
     * Report SQL $query to debug system.
     *
     * @param string $fname The function name that started the query, should contain relevant arguments in the text.
     * @param int    $timeTaken Number of seconds the query + related operations took (as float).
     * @param int $numRows Number of affected rows.
     **/
    function _report( $query, $fname, $timeTaken, $numRows = false )
    {
        if ( !self::$dbparams['sql_output'] )
            return;

        $rowText = '';
        if ( $numRows !== false )
            $rowText = "$numRows rows, ";
        //static $numQueries = 0;
        if ( strlen( $fname ) == 0 )
            $fname = "_query";
        $backgroundClass = ($this->transactionCount > 0  ? "debugtransaction transactionlevel-$this->transactionCount" : "");
        eZDebug::writeNotice( "$query", "cluster::oracle::{$fname}[{$rowText}" . number_format( $timeTaken, 3 ) . " ms] query number per page:" . self::$numQueries++, $backgroundClass );
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
    public function _startCacheGeneration( $filePath, $generatingFilePath )
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
                return array( 'result' => 'error' );
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

                    eZDebugSetting::writeDebug( 'kernel-clustering', "$filePath generation has timedout, taking over", __METHOD__ );
                    $updateQuery = "UPDATE " . self::TABLE_METADATA . " SET mtime = {$mtime} WHERE name_hash = {$nameHash} AND mtime = {$previousMTime}";
                    //eZDebug::writeDebug( $updateQuery, '$updateQuery' );

                    // we run the query manually since the default _query won't
                    // report affected rows
                    //$stmt = oci_parse( $this->db, $updateQuery );
                    //$res = oci_execute( $stmt );
                    $res = $this->_query( $updateQuery, $fname, false, array(), self::RETURN_COUNT );
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
     * the .generating file to the actual file, and removed the .generating
     * @param string $filePath
     * @return bool
     **/
    public function _endCacheGeneration( $filePath, $generatingFilePath, $rename )
    {
        $fname = "_endCacheGeneration( $filePath )";

        //eZDebugSetting::writeDebug( 'kernel-clustering', $filePath, __METHOD__ );

        $nameHash = "'" . md5( $generatingFilePath ) . "'";

        // no rename: the .generating entry is just deleted
        if ( $rename === false )
        {
            $this->_query( "DELETE FROM " . self::TABLE_METADATA . " WHERE name_hash=$nameHash" );
            $this->dfsbackend->delete( $generatingFilePath );
            return true;
        }
        // rename mode: the generating file and its contents are renamed to the
        // final name
        else
        {
            $this->_begin( $fname );

            $newPath = "'" . md5( $filePath ) . "'";

            // both files are locked for update
            if ( !$generatingMetaData = $this->_query( "SELECT * FROM " . self::TABLE_METADATA . " WHERE name_hash=$nameHash FOR UPDATE", $fname, true, array(), self::RETURN_DATA ) )
            {
                $this->_rollback( $fname );
                return false;
            }
            //$generatingMetaData = mysql_fetch_assoc( $res );

            // we cannot use RETURN COUNT here, as it does not work with selects
            $res = $this->_query( "SELECT * FROM " . self::TABLE_METADATA . " WHERE name_hash=$newPath FOR UPDATE", $fname, false, array(), self::RETURN_DATA );
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

            // here we rename the actual FILE. The .generating file has been
            // created on DFS, and should be renamed
            if ( !$this->dfsbackend->renameOnDFS( $generatingFilePath, $filePath ) )
            {
                eZDebug::writeError("An error occured renaming DFS://$generatingFilePath to DFS://$filePath", $fname );
                $this->_rollback( $fname );
                // @todo Throw an exception
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
    public function _checkCacheGenerationTimeout( $generatingFilePath, $generatingFileMtime )
    {
        $fname = "_checkCacheGenerationTimeout( $generatingFilePath, $generatingFileMtime )";
        //eZDebugSetting::writeDebug( 'kernel-clustering', "Checking for timeout of '$generatingFilePath' with mtime $generatingFileMtime", $fname );

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
            /// @todo Throw an exception
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
    public function _abortCacheGeneration( $generatingFilePath )
    {
        $fname = "_abortCacheGeneration( $generatingFilePath )";

        /// @bug why use a transaction here if no rollback is possible?
        $this->_begin( $fname );

        $sql = "DELETE FROM " . self::TABLE_METADATA . " WHERE name_hash = '" . md5( $generatingFilePath ) . "'";
        $this->_query( $sql, $fname );
        $this->dfsbackend->delete( $generatingFilePath );

        $this->_commit( $fname );
    }

    /**
     * Returns the remaining time, in seconds, before the generating file times
     * out
     *
     * @param resource $fileRow
     *
     * @return int Remaining generation seconds. A negative value indicates a timeout.
     **/
    protected function remainingCacheGenerationTime( $row )
    {
        if( !isset( $row[0] ) )
            return -1;

        return ( $row[0] + self::$dbparams['cache_generation_timeout'] ) - time();
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
     *
     * @todo reenable this logic before moving to logical deletions
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
           $res = $this->_query( $query, __METHOD__, true, array(), self::RETURN_DATA );
           $filePathList = array();
           while ( $row = mysql_fetch_row( $res ) )
           $filePathList[] = $row[0];*/

        return array();
    }


    /**
     * Similar to eZDFSFileHandler::purge()
     *
     * The difference here is that since we do physical deletes in the db and not
     * logical ones, the standard purge operation will not work - after we have
     * removed the row in the database and left the file in the dfs, we cannot
     * retrieve any more an indicator that it has to be purged. :-(
     * So we do a dumb, dumb scan of the dfs folder, and for every file found we
     * check if it is not in the db anymore. If it is not, we delete it.
     * To make this bearable, we build the following ad hoc functions. The
     * standard purging API is not a good fit.
     *
     * @param string   $dir
     * @param callback $printCallback will be called possibly many times over; expect 1 param: an arry of deleted file names
     * @param int      $microsleep
     * @param int      $max
     */
    public function dfspurge( $dir, $printCallback = false, $microsleep = false, $max = 100, $dryRun = false )
    {
        // this we should recover from eZDFSFileHandlerDFSBackend, but it is
        // currently a protected member. So we copy code over
        $mountPointPath = eZINI::instance( 'file.ini' )->variable( 'eZDFSClusteringSettings', 'MountPointPath' );
        if ( substr( $mountPointPath, -1 ) != '/' )
            $mountPointPath = "$mountPointPath/";

        // remove an extra recursive 'if' check within _dfspurge and do it here...
        if ( is_file( "$mountPointPath$dir") )
        {
            $this->_dfspurgeInner( array( $dir ), $printCallback, false );
            return;
        }

        $rest = $this->_dfspurge( $dir, $mountPointPath, $dryRun, $max, $printCallback, $microsleep );
        if ( count( $rest ) )
        {
            $this->_dfspurgeInner( $rest, $dryRun, $printCallback, false );
        }
    }

    protected function _dfspurge( $dir, $root, $dryRun = false, $limit = 100, $printCallback = false, $microsleep = false )
    {
        $files = array();
        foreach ( scandir( "$root$dir" ) as $file )
        {
            if ( $file != '.' && $file != '..' )
            {
                $file = "$dir/$file";
                if ( is_dir( "$root$file" ) )
                {
                    $files = array_merge( $files, $this->_dfspurge( $file, $root, $dryRun, $limit, $printCallback, $microsleep ) );
                    if ( count( $files ) >= $limit )
                    {
                        $this->_dfspurgeInner( array_slice( $files, 0, $limit ), $dryRun, $printCallback, $microsleep );
                        $files = array_slice( $files, $limit );
                    }
                }
                else
                {
                    $files[md5( $file )] = $file;
                    if ( count( $files ) % $limit == 0 )
                    {
                        $this->_dfspurgeInner( $files, $dryRun, $printCallback, $microsleep );
                        $files = array();
                    }
                }
            }
        }
        return $files;
    }

    protected function _dfspurgeInner( $array, $dryRun = false, $printCallback = false, $microsleep = false )
    {
        // look for files present in the db
        $selectSQL = 'select name_hash from ' . self::TABLE_METADATA . " where name_hash in ('" . implode( "', '", array_keys( $array ) ) . "')";
        //$connector = new eZDFSFileHandlerOracleBackend();
        //$connector->connect();
        $found = $this->_query( $selectSQL, '_dfspurgeInner', true, array(), self::RETURN_DATA_BY_COL );

        /// @todo manage db error case
        if ( $found )
        {
            // remove them from files to be deleted
            foreach( $found['NAME_HASH'] as $md5file )
            {
                unset( $array[$md5file] );
            }

            // remove the rest from the filesystem
            if ( !$dryRun )
            {
                $this->dfsbackend->delete( $array );
            }

            if ( $printCallback && count( $array ) )
            {
                call_user_func_array( $printCallback, array( $array ) );
            }
        }
        usleep( $microsleep );
    }


    /**
     * DB connection handle
     * @var handle
     **/
    public $db = null;

    /**
     * DB connexion parameters
     * @var array
     **/
    protected static $dbparams = null;

    /**
     * Amount of executed queries, for debugging purpose
     * @var int
     **/
    protected $numQueries = 0;

    /**
     * Current transaction level.
     * Will be used to decide wether we can BEGIN (if it's the first BEGIN call)
     * or COMMIT (if we're commiting the last running transaction
     * @var int
     **/
    protected $transactionCount = 0;

    /**
     * DB file table name
     * @var string
     **/
    const TABLE_METADATA = 'ezdfsfile';

    /**
     * Distributed filesystem backend
     * @var eZDFSFileHandlerDFSBackend
     **/
    protected $dfsbackend = null;

    /**
    * Constants used when calling the _query functions
    */
    const RETURN_BOOL = 0;
    const RETURN_COUNT = 1;
    const RETURN_DATA = 2;
    const RETURN_DATA_BY_COL = 4;

    /// @todo add runtime support (via an ini param?) to switch to logical deletes
    //static $deletequery = "UPDATE ezdbfile SET mtime=-ABS(mtime), expired='1' ";
    static $deletequery = "DELETE FROM ezdbfile ";

}

?>
