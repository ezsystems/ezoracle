<?php

// Copy this file to root directory of your eZ publish installation.

$filename = ltrim( $_SERVER['SCRIPT_URL'], "/");

if ( !function_exists( 'oci_connect' ) )
    die( "PECL oci8 extension (http://pecl.php.net/package/oci8) is required to use Oracle clustering functionality.\n" );

if ( !( $db = @oci_connect( STORAGE_USER, STORAGE_PASS, STORAGE_DB ) ) )
    $this->_die( "Unable to connect to storage server" );

$query = "SELECT * FROM ezdbfile WHERE name_hash = '" . md5( $filename ) . "'";
$statement = oci_parse( $db, $query );
if ( !oci_execute( $statement, OCI_DEFAULT ) )
    die( "Error fetching image.\n" );

$chunkSize = STORAGE_CHUNK_SIZE;
if ( ( $row = oci_fetch_array( $statement, OCI_ASSOC ) ) )
{
    // output HTTP headers
    $path     = $row['NAME'];
    $size     = $row['FILESIZE'];
    $mimeType = $row['DATATYPE'];
    $mtime    = $row['MTIME'];
    $mdate    = gmdate( 'D, d M Y H:i:s T', $mtime );

    header( "Content-Length: $size" );
    header( "Content-Type: $mimeType" );
    header( "Last-Modified: $mdate" );
    header( "Expires: ". gmdate('D, d M Y H:i:s', time() + 6000) . 'GMT' );
    header( "Connection: close" );
    header( "X-Powered-By: eZ publish" );
    header( "Accept-Ranges: bytes" );
    header( 'Served-by: ' . $_SERVER["SERVER_NAME"] );

    // output image data
    $lob = $row['LOB'];
    while ( $chunk = $lob->read( $chunkSize ) )
        echo $chunk;

    $lob->free();
}
else
{
    header( "HTTP/1.1 404 Not Found" );
?>
<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<HTML><HEAD>
<TITLE>404 Not Found</TITLE>
</HEAD><BODY>
<H1>Not Found</H1>
The requested URL <?=htmlspecialchars( $filename )?> was not found on this server.<P>
</BODY></HTML>
<?php
}
oci_free_statement( $statement );
oci_close( $db );
?>
