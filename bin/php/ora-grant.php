#!/usr/bin/env php
<?php
/**
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

error_reporting( E_ALL );

$argc = count( $argv );

if ( $argc < 4 )
{
    print( "Usage: $argv[0] <username> <password> <tablespace>\n" );
    exit( 1 );
}

$user = $argv[1];
$password = $argv[2];
$tablespace = $argv[3];

$sql = "CREATE USER $user IDENTIFIED BY $password DEFAULT TABLESPACE $tablespace QUOTA UNLIMITED ON $tablespace;
GRANT CREATE    SESSION   TO $user;
GRANT CREATE    TABLE     TO $user;
GRANT CREATE    TRIGGER   TO $user;
GRANT CREATE    SEQUENCE  TO $user;
GRANT CREATE    PROCEDURE TO $user;";

print( $sql . "\n" );

?>
