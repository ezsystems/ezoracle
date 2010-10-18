<?php
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ 0racle
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

// Copy this file to root directory of your eZ Publish installation.

define( 'TABLE_METADATA', 'ezdbfile' );

function _die( $value )
{
    header( $_SERVER['SERVER_PROTOCOL'] . " 500 Internal Server Error" );
    die( $value );
}

if ( !function_exists( 'oci_connect' ) )
    _die( "PECL oci8 extension (http://pecl.php.net/package/oci8) is required to use Oracle clustering functionality.\n" );

if ( defined( 'STORAGE_PERSISTENT_CONNECTION' ) && STORAGE_PERSISTENT_CONNECTION )
{
    $db = @oci_pconnect( STORAGE_USER, STORAGE_PASS, STORAGE_DB );
}
else
{
     $db = @oci_connect( STORAGE_USER, STORAGE_PASS, STORAGE_DB );
}
if ( !$db )
    _die( "Unable to connect to storage server.\n" );

$filename = ltrim( $_SERVER['REQUEST_URI'], "/");

$query = "SELECT * FROM " . TABLE_METADATA . " WHERE name_hash = :name_hash";
if ( !$statement = oci_parse( $db, $query ) )
    _die( "Error fetching image.\n" );
$md5 = md5( $filename );
oci_bind_by_name( $statement, ':name_hash', $md5, -1 );
if ( !oci_execute( $statement, OCI_DEFAULT ) )
    _die( "Error fetching image.\n" );

$chunkSize = STORAGE_CHUNK_SIZE;
$expiry = defined( 'EXPIRY_TIMEOUT' ) ? EXPIRY_TIMEOUT : 6000;
if ( ( $row = oci_fetch_array( $statement, OCI_ASSOC ) ) )
{
    // output HTTP headers
    //$path     = $row['NAME'];
    $size     = $row['FILESIZE'];
    $mimeType = $row['DATATYPE'];
    $mtime    = $row['MTIME'];
    $mdate    = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';

    if ( defined( 'USE_ETAG' ) && USE_ETAG )
    {
        // getallheaders() not available on every server
        foreach ( $_SERVER as $header => $value )
        {
            if ( strtoupper( $header ) == 'HTTP_IF_NONE_MATCH' && trim( $value ) == "$mtime-$size" )
            {
                header( "HTTP/1.1 304 Not Modified" );
                oci_free_statement( $statement );
                if ( !defined( 'STORAGE_PERSISTENT_CONNECTION' ) || STORAGE_PERSISTENT_CONNECTION == false )
                {
                    oci_close( $db );
                }
                die();
            }
        }
        header( "ETag: $mtime-$size" );
    }

    header( "Content-Length: $size" );
    header( "Content-Type: $mimeType" );
    header( "Last-Modified: $mdate" );
    /* Set cache time out to 10 minutes, this should be good enough to work around an IE bug */
    header( "Expires: ". gmdate( 'D, d M Y H:i:s', time() + $expiry ) . ' GMT' );
    //header( "Connection: close" );
    header( "X-Powered-By: eZ Publish" );
    header( "Accept-Ranges: none" );
    header( 'Served-by: ' . $_SERVER["SERVER_NAME"] );

    // output image data
    $lob = $row['LOB'];
    while ( $chunk = $lob->read( $chunkSize ) )
    {
        echo $chunk;
        // in case output_buffering is on in php.ini, take over with out own
        // to avoid php buffering the whole image
        flush();
        // minor memory optimization trick. See http://blogs.oracle.com/opal/2010/03/reducing_oracle_lob_memory_use.html
        unset( $chunk );
    }
    $lob->free();

}
else
{
    header( $_SERVER['SERVER_PROTOCOL'] . " 404 Not Found" );
?>
<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<HTML><HEAD>
<TITLE>404 Not Found</TITLE>
</HEAD><BODY>
<H1>Not Found</H1>
The requested URL <?php echo htmlspecialchars( $filename ); ?> was not found on this server.<P>
</BODY></HTML>
<?php
}
oci_free_statement( $statement );
if ( !defined( 'STORAGE_PERSISTENT_CONNECTION' ) || STORAGE_PERSISTENT_CONNECTION == false )
{
    oci_close( $db );
}
?>
