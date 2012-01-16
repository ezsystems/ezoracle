<?php
/**
 * @copyright Copyright (C) 1999-2012 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 * @package kernel
 */

/**
 * DFS/Oracle cluster gateway
 */
class ezpDfsOracleClusterGateway extends ezpClusterGateway
{
    public function getDefaultPort()
    {
        // port isn't needed by oracle
        return false;
    }

    /**
     * Note: host & port aren't used by the oracle gateway
     */
    public function connect( $host, $port, $user, $password, $database, $charset )
    {
        if ( !function_exists( 'oci_connect' ) )
            throw new RuntimeException( "PECL oci8 extension (http://pecl.php.net/package/oci8) is required to use Oracle clustering functionality." );

        if ( defined( 'CLUSTER_PERSISTENT_CONNECTION' ) && CLUSTER_PERSISTENT_CONNECTION )
            $connectFunction = 'oci_pconnect';
        else
            $connectFunction = 'oci_connect';

        if ( !$this->db = @$connectFunction( $user, $password, $database, $charset ) )
        {
            $error = oci_error();
            throw new RuntimeException( "Failed to connect to the oracle database " .
                "(error #{$error['code']}: {$error['message']})" );
        }
    }

    public function fetchFileMetadata( $filepath )
    {
        $query = "SELECT filesize, datatype, mtime FROM ezdfsfile WHERE name_hash = :name_hash";
        if ( !$statement = oci_parse( $this->db, $query ) )
        {
            $error = oci_error();
            throw new RuntimeException( "Failed to fetch file metadata for '$filepath' " .
                "(error #{$error['code']}: {$error['message']})" );
        }

        oci_bind_by_name( $statement, ':name_hash', md5( $filepath ), -1 );
        if ( !oci_execute( $statement, OCI_DEFAULT ) )
        {
            $error = oci_error();
            throw new RuntimeException( "Failed to fetch file metadata for '$filepath' " .
                "(error #{$error['code']}: {$error['message']})" );
        }

        $metadata = oci_fetch_array( $statement, OCI_ASSOC );
        oci_free_statement( $statement );
        $metadata = array_change_key_case( $metadata );
        $metadata['size'] = $metadata['filesize']; unset( $metadata["filesize"] );
        return $metadata;
    }

    public function passthrough( $filepath, $offset = false, $length = false)
    {
        $dfsFilePath = CLUSTER_MOUNT_POINT_PATH . '/' . $filepath;

        if ( !file_exists( $dfsFilePath ) )
            throw new RuntimeException( "Unable to open DFS file '$dfsFilePath'" );

        $fp = fopen( $dfsFilePath, 'r' );
        fpassthru( $fp );
        fclose( $fp );
    }

    public function close()
    {
        if ( !defined( 'CLUSTER_PERSISTENT_CONNECTION' ) || CLUSTER_PERSISTENT_CONNECTION )
            oci_close( $this->db );
        unset( $this->db );
    }
}

// return the class name for easier instanciation
return 'ezpDfsOracleClusterGateway';