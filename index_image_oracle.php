<?php
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ 0racle
// SOFTWARE RELEASE: 1.6.x
// COPYRIGHT NOTICE: Copyright (C) 1999-2008 eZ Systems AS
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

// Copy this file to root directory of your eZ Publish installation.

$filename = ltrim( $_SERVER['SCRIPT_URL'], "/");

if ( !function_exists( 'oci_connect' ) )
    die( "PECL oci8 extension (http://pecl.php.net/package/oci8) is required to use Oracle clustering functionality.\n" );

if ( !( $db = @oci_connect( STORAGE_USER, STORAGE_PASS, STORAGE_DB ) ) )
    die( "Unable to connect to storage server.\n" );

$query = "SELECT * FROM ezdbfile WHERE name_hash = '" . md5( $filename ) . "'";
$statement = oci_parse( $db, $query );
if ( !oci_execute( $statement, OCI_DEFAULT ) )
    die( "Error fetching image.\n" );

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
