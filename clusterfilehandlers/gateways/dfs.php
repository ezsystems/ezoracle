<?php
/**
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 * @version //autogentag//
 * @package kernel
 */

/**
 * DFS/Oracle cluster gateway
 */
class ezpDfsOracleClusterGateway extends ezpClusterGateway
{
    public function connect()
    {
        if ( !function_exists( 'oci_connect' ) )
            throw new RuntimeException( "PECL oci8 extension (http://pecl.php.net/package/oci8) is required to use Oracle clustering functionality." );

        if ( defined( 'CLUSTER_PERSISTENT_CONNECTION' ) && CLUSTER_PERSISTENT_CONNECTION )
            $connectFunction = 'oci_pconnect';
        else
            $connectFunction = 'oci_connect';

        if ( !$this->db = @$connectFunction( $this->user, $this->password, $this->name, $this->charset ) )
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

        if ( $metadata === false )
            return false;
        $metadata = array_change_key_case( $metadata );
        $metadata['size'] = $metadata['filesize']; unset( $metadata["filesize"] );
        return $metadata;
    }

    public function passthrough( $filepath, $filesize, $offset = false, $length = false )
    {
        $dfsFilePath = CLUSTER_MOUNT_POINT_PATH . '/' . $filepath;

        if ( !file_exists( $dfsFilePath ) )
            throw new RuntimeException( "Unable to open DFS file '$dfsFilePath'" );

        $fp = fopen( $dfsFilePath, 'rb' );
        if ( $offset !== false && @fseek( $fp, $offset ) === -1 )
            throw new RuntimeException( "Failed to seek offset $offset on file '$filepath'" );
        if ( $offset === false && $length === false )
            fpassthru( $fp );
        else
            echo fread( $fp, $length );

        fclose( $fp );
    }

    public function close()
    {
        if ( !defined( 'CLUSTER_PERSISTENT_CONNECTION' ) || CLUSTER_PERSISTENT_CONNECTION === false )
            oci_close( $this->db );
        unset( $this->db );
    }
}

ezpClusterGateway::setGatewayClass( 'ezpDfsOracleClusterGateway' );
