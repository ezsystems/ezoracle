<?php
/**
 * @copyright Copyright (C) 1999-2012 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 * @package kernel
 */

/**
 * DB/Oracle cluster gateway
 */
class ezpDbOracleClusterGateway extends ezpClusterGateway
{
    /**
     * Reference to the last fetched metadata LOB
     * @var OCI-Lob
     */
    private $lob;

    public function getDefaultPort()
    {
        // port isn't needed by oracle
        return false;
    }

    private function getChunkSize()
    {
        if ( defined( 'CLUSTER_CHUNK_SIZE' ) )
            return CLUSTER_CHUNK_SIZE;
        else
            return 65535;
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
        $query = "SELECT * FROM ezdbfile WHERE name_hash = :name_hash";
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

        // metadata === false => file not found
        if ( !$metadata = oci_fetch_array( $statement, OCI_ASSOC ) )
            return false;

        $this->lob = $metadata['LOB'];
        oci_free_statement( $statement );
        $metadata = array_change_key_case( $metadata );
        $metadata['size'] = $metadata['filesize']; unset( $metadata["filesize"] );
        return $metadata;
    }

    public function passthrough( $filepath, $offset = false, $length = false)
    {
        // output image data
        while ( $chunk = $this->lob->read( $this->getChunkSize() ) )
        {
            echo $chunk;
            // in case output_buffering is on in php.ini, take over with out own
            // to avoid php buffering the whole image
            flush();
            // minor memory optimization trick. See http://blogs.oracle.com/opal/2010/03/reducing_oracle_lob_memory_use.html
            unset( $chunk );
        }
        $this->lob->free();
        $this->lob = null;
    }

    public function close()
    {
        if ( $this->lob !== null )
        {
            $this->lob->free();
            $this->lob = null;
        }
        if ( !defined( 'CLUSTER_PERSISTENT_CONNECTION' ) || CLUSTER_PERSISTENT_CONNECTION )
            oci_close( $this->db );
        unset( $this->db );
    }
}

// return the class name for easier instanciation
return 'ezpDbOracleClusterGateway';