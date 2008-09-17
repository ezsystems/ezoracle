#!/usr/bin/env php
<?php
error_reporting( E_ALL );

$argc = count( $argv );

if ( $argc < 3 )
{
    print( "Usage: $argv[0] <username> <password>\n" );
    exit( 1 );
}

$user = $argv[1];
$password = $argv[2];

//$user = "scott";
//$password = "tiger";
$sql = "CREATE USER $user IDENTIFIED BY $password QUOTA UNLIMITED ON SYSTEM;
GRANT CREATE    SESSION   TO $user;
GRANT CREATE    TABLE     TO $user;
GRANT CREATE    TRIGGER   TO $user;
GRANT CREATE    SEQUENCE  TO $user;
GRANT CREATE    PROCEDURE TO $user;
GRANT ALTER ANY TABLE     TO $user;
GRANT ALTER ANY TRIGGER   TO $user;
GRANT ALTER ANY SEQUENCE  TO $user;
GRANT DROP  ANY TABLE     TO $user;
GRANT DROP  ANY TRIGGER   TO $user;
GRANT DROP  ANY SEQUENCE  TO $user;";

print( $sql . "\n" );

?>
