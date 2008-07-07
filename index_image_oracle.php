<?php

// Copy this file to root directory of your eZ Publish installation.

define( 'TABLE_METADATA', 'ezdbfile' );

function _die( $value )
{
    header( $_SERVER['SERVER_PROTOCOL'] . " 500 Internal Server Error" );
    die( $value );
}

if ( !function_exists( 'oci_connect' ) )
    _die( "PECL oci8 extension (http://pecl.php.net/package/oci8) is required to use Oracle clustering functionality.\n" );

if ( !( $db = @oci_connect( STORAGE_USER, STORAGE_PASS, STORAGE_DB ) ) )
    _die( "Unable to connect to storage server.\n" );

$filename = ltrim( $_SERVER['SCRIPT_URL'], "/");

$query = "SELECT * FROM " . TABLE_METADATA . " WHERE name_hash = :name_hash";
if ( !$statement = oci_parse( $db, $query ) )
    _die( "Error fetching image.\n" );
oci_bind_by_name( $statement, ':name_hash', md5( $filename ), -1 );
if ( !oci_execute( $statement, OCI_DEFAULT ) )
    _die( "Error fetching image.\n" );

$chunkSize = STORAGE_CHUNK_SIZE;
if ( ( $row = oci_fetch_array( $statement, OCI_ASSOC ) ) )
{
    // output HTTP headers
    //$path     = $row['NAME'];
    $size     = $row['FILESIZE'];
    $mimeType = $row['DATATYPE'];
    $mtime    = $row['MTIME'];
    $mdate    = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';

    header( "Content-Length: $size" );
    header( "Content-Type: $mimeType" );
    header( "Last-Modified: $mdate" );
    /* Set cache time out to 10 minutes, this should be good enough to work around an IE bug */
    header( "Expires: ". gmdate('D, d M Y H:i:s', time() + 6000) . ' GMT' );
    header( "Connection: close" );
    header( "X-Powered-By: eZ Publish" );
    header( "Accept-Ranges: none" );
    header( 'Served-by: ' . $_SERVER["SERVER_NAME"] );

    // output image data
    $lob = $row['LOB'];
    while ( $chunk = $lob->read( $chunkSize ) )
        echo $chunk;

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
oci_close( $db );
?>
