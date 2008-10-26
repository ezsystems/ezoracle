#!/bin/bash

RES_COL=60
# terminal sequence to move to that column. You could change this
# to something like "tput hpa ${RES_COL}" if your terminal supports it
MOVE_TO_COL="echo -en \\033[${RES_COL}G"
# terminal sequence to set color to a 'success' color (currently: green)
SETCOLOR_SUCCESS="echo -en \\033[1;32m"
# terminal sequence to set color to a 'failure' color (currently: red)
SETCOLOR_FAILURE="echo -en \\033[1;31m"
# terminal sequence to set color to a 'warning' color (currently: magenta)
SETCOLOR_WARNING="echo -en \\033[1;35m"

# terminal sequence to set color to a 'file' color (currently: default)
SETCOLOR_FILE="echo -en \\033[1;30m"
# terminal sequence to set color to a 'directory' color (currently: blue)
SETCOLOR_DIR="echo -en \\033[1;34m"
# terminal sequence to set color to a 'executable' color (currently: green)
SETCOLOR_EXE="echo -en \\033[1;32m"

# terminal sequence to set color to a 'comment' color (currently: gray)
SETCOLOR_COMMENT="echo -en \\033[1;30m"
# terminal sequence to set color to a 'emphasize' color (currently: bold black)
SETCOLOR_EMPHASIZE="echo -en \\033[1;38m"
# terminal sequence to set color to a 'new' color (currently: bold black)
SETCOLOR_NEW="echo -en \\033[1;38m"


# terminal sequence to reset to the default color.
SETCOLOR_NORMAL="echo -en \\033[0;39m"

# Position handling
POSITION_STORE="echo -en \\033[s"
POSITION_RESTORE="echo -en \\033[u"


# Common variables

TEST_USER="scott"
TEST_PASSWORD="tiger"
TEST_INSTANCE="oracl"

ADMIN_USER="system"
ADMIN_PASSWORD="manager"
ADMIN_INSTANCE="oracl"

EZDB_HAS_USER=""
EZDB_USER=""
EZDB_PASSWORD=""
EZDB_INSTANCE=""

DB_TEST=""

# OLD CODE:
# Check tmpdir variable,
# some system might not have this set
# [ -n "$TMPDIR" ] || TMPDIR=/tmp

# We use the current dir as tmp
TMPDIR=`pwd`

# Reimplementation of which, for some reason the which
# on Solaris 5.9 doesn't set a proper exit code
function ora_which
{
    local exec
    local path_list
    local path
    exec="$1"
    path_list=`echo $PATH | sed 's/:/ /g'`
    for path in $path_list; do
	if [ -x "$path/$exec" ]; then
	    return 0
	fi
    done
    return 1
}

# We need to make sure the PHP cli version is available

echo -n "Testing PHP"
if ! ora_which php &>/dev/null; then
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "No PHP executable found, please add it to the path"
    exit 1
fi
echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"

# Oracle variables must be correctly setup before we can continue

echo -n "Testing Oracle installation"
if [ -z "$ORACLE_HOME" ]; then
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "The enviroment variable ORACLE_HOME needs to be set"
    echo "Oracle is usually found in `$SETCOLOR_DIRECTORY`/usr/oracle`$SETCOLOR_NORMAL`"
    echo "e.g."
    echo "export ORACLE_HOME=\"/usr/oracle\""
    exit 1
fi

if [ ! -f "$ORACLE_HOME/network/admin/tnsnames.ora" ]; then
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "The file network/admin/tnsnames.ora is missing from \$ORACLE_HOME"
    echo "Oracle will require this file to figure out the service and hostname for the DB server"
    echo "You should copy this from the DB server and do some modifications to it"
    exit 1
fi
echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"

# Test if PHP has oracle compiled in

PHP_TEST_SCRIPT="$TMPDIR/.ezoracle_test.php"

cat <<EOF >"$PHP_TEST_SCRIPT"
<?php
if ( !extension_loaded( "oci8" ) )
    exit( 1 );
?>
EOF

echo -n "Testing PHPs Oracle support"
php "$PHP_TEST_SCRIPT" &>/dev/null
if [ $? -ne 0 ]; then
    rm "$PHP_TEST_SCRIPT"
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "Your PHP installation does not have support for Oracle (OCI8) compiled in"
    echo "You will need to do that before this script can continue"
    echo
    echo "You will need to have a full Oracle installation "
    echo "or the header and library files from an Oracle installation*"
    echo "to properly compile PHP"
    echo "Pass --with-oci8 to PHPs configure to enable this"
    echo
    echo "* Copy rdmbs, bin and lib from an Oracle install"
    exit 1
fi
rm "$PHP_TEST_SCRIPT"
echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"


# Find eZ publish and make sure it has the extension

EZP_PATH=`pwd`
EZORACLE_EXT_PATH=`pwd`

function is_ezpublish_dir
{
    local dir
    dir="$1"
    if [[ -f "$dir/index.php" &&
		-f "$dir/lib/version.php" &&
		-d "$dir/extension" ]]; then
	if grep 'EZ_SDK_VERSION_MAJOR' "$dir/lib/version.php" &>/dev/null; then
	    if grep 'EZ_SDK_VERSION_MINOR' "$dir/lib/version.php" &>/dev/null; then
		if ! grep 'EZ_SDK_VERSION_STATE' "$dir/lib/version.php" &>/dev/null; then
#		    echo "No EZ_SDK_VERSION_STATE"
		    return 1
		fi
#	    else
#		echo "No EZ_SDK_VERSION_MINOR"
	    fi
#	else
#	    echo "No EZ_SDK_VERSION_MAJOR"
	fi
    else
	return 1
    fi
    return 0
}

echo -n "Looking for eZ publish installation"
if ! is_ezpublish_dir "$EZP_PATH"; then
    EZP_PATH=""
else
    EZORACLE_EXT_PATH="$EZP_PATH/extension/ezoracle"
fi
if [ -z "$EZP_PATH" ]; then
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "No eZ publish directory found"
    while [ -z "$EZP_PATH" ]; do
	echo -n "Please enter full eZ publish path: "
	read DIR
	if [ -z "$DIR" ]; then
	    exit
	fi
	if [ ! -d "$DIR" ]; then
	    echo "Directory $DIR does not exist"
	elif ! is_ezpublish_dir "$DIR"; then
	    echo "Directory $DIR is not an eZ publish installation"
	else
	    EZP_PATH="$DIR"
	fi
    done
    echo -n "Checking eZ publish installation"
    echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"
    EZORACLE_EXT_PATH="$EZP_PATH/extension/ezoracle"
else
    echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"
fi

echo -n "Testing eZOracle extension"
if [ ! -d "$EZORACLE_EXT_PATH" ]; then
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "The Oracle extension is not installed in the eZ publish directory"
    echo "Please copy the extension to $EZP_PATH/extension/"
    exit 1
fi
if [ ! -f "$EZORACLE_EXT_PATH/ezdb/dbms-drivers/ezoracledb.php" ]; then
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "The Oracle extension is not installed in the eZ publish directory"
    echo "Please copy the extension to $EZP_PATH/extension/"
    exit 1
fi
echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"

EZSCHEMA_PATH="$EZP_PATH/share/db_schema.dba"
echo -n "Looking for database schema file"
if [ ! -f "$EZSCHEMA_PATH" ]; then
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "The database schema file `$SETCOLOR_FILE`db_schema.dba`$SETCOLOR_NORMAL` could not be found"
    echo "The oracle extension cannot initialize the database without it"
    echo "You will have to setup Oracle manually by following the INSTALL manual"
    exit 1
fi
echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"

EZDATA_PATH="$EZP_PATH/share/db_data.dba"
echo -n "Looking for database data file"
if [ ! -f "$EZDATA_PATH" ]; then
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "The database data file `$SETCOLOR_FILE`db_data.dba`$SETCOLOR_NORMAL` could not be found"
    echo "The oracle extension cannot initialize the database without it"
    echo "You will have to setup Oracle manually by following the INSTALL manual"
    exit 1
fi
echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"

## Testing a logon to oracle

echo -n "Do you wish to test if the Oracle server can be connected to? [y|N] "
read question
question=`echo $question | tr [A-Z] [a-z]`
case $question in
    y|yes)
	DB_TEST="1"
	;;
    n|no)
	DB_TEST=""
	;;
    *)
	DB_TEST=""
	;;
esac

if [ "$DB_TEST" == "1" ]; then
    echo -n "Username [$TEST_USER]: "
    read USER
    echo -n "Password [$TEST_PASSWORD]: "
    read PASSWORD
    echo -n "Oracle Instance [$TEST_INSTANCE]: "
    read INSTANCE

    if [ -z "$USER" ]; then
	USER="$TEST_USER"
    fi
    if [ -z "$PASSWORD" ]; then
	PASSWORD="$TEST_PASSWORD"
    fi
    if [ -z "$INSTANCE" ]; then
	INSTANCE="$TEST_INSTANCE"
    fi
    TEST_USER="$USER"
    TEST_PASSWORD="$PASSWORD"
    TEST_INSTANCE="$INSTANCE"

    echo -n "Testing Oracle availability"
    cat <<EOF >"$PHP_TEST_SCRIPT"
<?php
\$db = @OCILogon( "$USER", "$PASSWORD", "$INSTANCE" );
if ( !\$db )
{
    \$error = OCIError();
    if ( \$error['code'] != 0 )
    {
        print( "Connection error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
        exit( 1 );
    }
}
?>
EOF
    ORACLE_ERROR=`php "$PHP_TEST_SCRIPT"`
    if [ $? -ne 0 ]; then
	rm "$PHP_TEST_SCRIPT"
	echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
	echo "Oracle logon failed:"
	echo $ORACLE_ERROR
	echo
	
	if echo $ORACLE_ERROR | grep "ORA-01017" &>/dev/null; then
	    echo "The username or password is incorrect."
	    echo "Review your input and try again."
	elif echo $ORACLE_ERROR | grep "ORA-12154" &>/dev/null; then
	    echo "The instance that is used is not correctly configured on the server/client."
	    echo "You should examine these files:"
	    echo "  network/admin/tnsnames.ora and"
	    echo "  network/admin/listener.ora (only on server)"
	    echo "  in the oracle home directory (\$ORACLE_HOME)"
	    echo
	    echo "Another source of this error is that there is no listener active on the DB server"
	    echo "Log into the DB server as the Oracle user then try to run:"
	    echo "lsnrctl"
	    echo "LSNRCTL> start"
	    echo
	    echo "The listeners should then be started"
	else
	    echo "Unknown error, reasons for this can be:"
	    echo "- The user or password incorrect."
	    echo "  Review your input and try again."
	    echo "- The instance that is used is not correctly configured on the server"
	    echo "  or this client. You should examine these files:"
	    echo "  network/admin/tnsnames.ora and"
	    echo "  network/admin/listener.ora"
	    echo "  in the oracle home directory (\$ORACLE_HOME)"
	    echo
	    echo "Another source of this error is that there is no listener active on the DB server"
	    echo "Log into the DB server as the Oracle user then try to run:"
	    echo "lsnrctl"
	    echo "LSNRCTL> start"
	    echo
	    echo "The listeners should then be started"
	fi
	exit 1
    fi
    rm "$PHP_TEST_SCRIPT"
    echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"
fi

echo -n "Do you wish to create a new user in the Oracle system? [y|N] "
read question
question=`echo $question | tr [A-Z] [a-z]`
case $question in
    y|yes)
	DB_CREATE_USER="1"
	;;
    n|no)
	DB_CREATE_USER=""
	;;
    *)
	DB_CREATE_USER=""
	;;
esac

if [ "$DB_CREATE_USER" == "1" ]; then
    echo -n "Admin Username [$ADMIN_USER]: "
    read AUSER
    if [ -z "$AUSER" ]; then
	AUSER="$ADMIN_USER"
    fi

    echo -n "Admin Password [$ADMIN_PASSWORD]: "
    read APASSWORD
    if [ -z "$APASSWORD" ]; then
	APASSWORD="$ADMIN_PASSWORD"
    fi

    echo -n "New Username: "
    read USER
    if [ -z "$USER" ]; then
	echo "Need a proper username"
	exit 1
    fi

    echo -n "New Password: "
    read PASSWORD
    if [ -z "$PASSWORD" ]; then
	echo "Need a proper password"
	exit 1
    fi

    echo -n "Oracle Instance [$ADMIN_INSTANCE]: "
    read INSTANCE
    if [ -z "$INSTANCE" ]; then
	INSTANCE="$ADMIN_INSTANCE"
    fi

    EZDB_HAS_USER="1"
    EZDB_USER="$USER"
    EZDB_PASSWORD="$PASSWORD"
    EZDB_INSTANCE="$INSTANCE"

    SQL="CREATE USER $USER IDENTIFIED BY $PASSWORD QUOTA UNLIMITED ON SYSTEM;
GRANT CREATE    SESSION   TO $USER;
GRANT CREATE    TABLE     TO $USER;
GRANT CREATE    TRIGGER   TO $USER;
GRANT CREATE    SEQUENCE  TO $USER;
GRANT CREATE    PROCEDURE TO $USER;
GRANT ALTER ANY TABLE     TO $USER;
GRANT ALTER ANY TRIGGER   TO $USER;
GRANT ALTER ANY SEQUENCE  TO $USER;
GRANT DROP  ANY TABLE     TO $USER;
GRANT DROP  ANY TRIGGER   TO $USER;
GRANT DROP  ANY SEQUENCE  TO $USER;"
    echo "The user will be created with the following SQLs"
    echo "$SQL"
    echo -n "Press [enter] to continue, [q+enter] to quit: "
    question=`echo $question | tr [A-Z] [a-z]`
    read question

    if [ "$question" == "q" ]; then
	exit
    fi

    echo -n "Creating new user in Oracle"
    cat <<EOF >"$PHP_TEST_SCRIPT"
<?php
\$db = @OCILogon( "$AUSER", "$APASSWORD", "$INSTANCE" );
if ( !\$db )
{
    \$error = OCIError();
    if ( \$error['code'] != 0 )
    {
        print( "Connection error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
        exit( 1 );
    }
}
\$sqls = explode( ";", "$SQL" );
foreach ( \$sqls as \$sql )
{
    if ( trim( \$sql ) == '' )
        continue;
    \$statement = OCIParse( \$db, \$sql );
    if ( !OCIExecute( \$statement, OCI_DEFAULT ) )
    {
        \$error = OCIError();
        if ( \$error['code'] != 0 )
        {
            print( "SQL error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
            print( "SQL was:\n" . \$sql );
            OCIFreeStatement( \$statement );
            if ( \$error["code"] == "01920" )
                exit( 2 );
            exit( 1 );
        }
    }
    OCIFreeStatement( \$statement );
}
?>
EOF
    ORACLE_ERROR=`php "$PHP_TEST_SCRIPT"`
    if [ $? -ne 0 ]; then
	rm "$PHP_TEST_SCRIPT"
	echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
	echo "Oracle user creation failed:"
	echo $ORACLE_ERROR
	echo
	
	exit 1
    fi
    rm "$PHP_TEST_SCRIPT"
    echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"
fi

# Make sure we a proper username and password
# If the user was created above, we should have it already

[ -z "$EZDB_USER" ] && EZDB_USER="$TEST_USER"
[ -z "$EZDB_PASSWORD" ] && EZDB_PASSWORD="$TEST_PASSWORD"
[ -z "$EZDB_INSTANCE" ] && EZDB_INSTANCE="$TEST_INSTANCE"


if [ "$EZDB_HAS_USER" != "1" ]; then
    echo
    echo "We will now need a username and password to connect to the Oracle server"
    echo "This user will be used to initialize and fill the database with data"
    if [ -z "$EZDB_USER" ]; then
	echo -n "Username: "
	read USER
	if [ -z "$USER" ]; then
	    echo "Need a proper username"
	    exit 1
	fi
	EZDB_USER="$USER"
    else
	echo -n "Username [$EZDB_USER]: "
	read USER
	if [ -n "$USER" ]; then
	    EZDB_USER="$USER"
	fi
    fi

    if [ -z "$EZDB_PASSWORD" ]; then
	echo -n "Password: "
	read PASSWORD
	if [ -z "$PASSWORD" ]; then
	    echo "Need a proper password"
	    exit 1
	fi
	EZDB_PASSWORD="$PASSWORD"
    else
	echo -n "Password [$EZDB_PASSWORD]: "
	read PASSWORD
	if [ -n "$PASSWORD" ]; then
	    EZDB_PASSWORD="$PASSWORD"
	fi
    fi
fi

# If instance hasn't been read earlier we ask for it

if [[ "$EZDB_HAS_USER" != "1" ||
      -z "$EZDB_INSTANCE" ]]; then
    echo
    echo "We will now need an instance to connect to the Oracle server"
    if [ -z "$EZDB_INSTANCE" ]; then
	echo -n "Oracle Instance: "
	read INSTANCE

	if [ -z "$INSTANCE" ]; then
	    echo "Need a proper Oracle instance"
	    exit 1
	fi
	EZDB_INSTANCE="$INSTANCE"
    else
	echo -n "Oracle Instance [$EZDB_INSTANCE]: "
	read INSTANCE

	if [ -n "$INSTANCE" ]; then
	    EZDB_INSTANCE="$INSTANCE"
	fi
    fi
fi

echo -n "Testing user info with Oracle"
cat <<EOF >"$PHP_TEST_SCRIPT"
<?php
\$db = @OCILogon( "$EZDB_USER", "$EZDB_PASSWORD", "$EZDB_INSTANCE" );
if ( !\$db )
{
    \$error = OCIError();
    if ( \$error['code'] != 0 )
    {
        print( "Connection error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
        exit( 1 );
    }
}
?>
EOF
ORACLE_ERROR=`php "$PHP_TEST_SCRIPT"`
if [ $? -ne 0 ]; then
    rm "$PHP_TEST_SCRIPT"
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "Oracle logon failed:"
    echo $ORACLE_ERROR
    echo

    if echo $ORACLE_ERROR | grep "ORA-01017" &>/dev/null; then
	echo "The username or password is incorrect."
	echo "Review your input and try again."
    elif echo $ORACLE_ERROR | grep "ORA-12154" &>/dev/null; then
	echo "The instance that is used is not correctly configured on the server/client."
	echo "You should examine these files:"
	echo "  network/admin/tnsnames.ora and"
	echo "  network/admin/listener.ora (only on server)"
	echo "  in the oracle home directory (\$ORACLE_HOME)"
	echo
	echo "Another source of this error is that there is no listener active on the DB server"
	echo "Log into the DB server as the Oracle user then try to run:"
	echo "lsnrctl"
	echo "LSNRCTL> start"
	echo
	echo "The listeners should then be started"
    else
	echo "Unknown error, reasons for this can be:"
	echo "- The user or password incorrect."
	echo "  Review your input and try again."
	echo "- The instance that is used is not correctly configured on the server"
	echo "  or this client. You should examine these files:"
	echo "  network/admin/tnsnames.ora and"
	echo "  network/admin/listener.ora"
	echo "  in the oracle home directory (\$ORACLE_HOME)"
	echo
	echo "Another source of this error is that there is no listener active on the DB server"
	echo "Log into the DB server as the Oracle user then try to run:"
	echo "lsnrctl"
	echo "LSNRCTL> start"
	echo
	echo "The listeners should then be started"
    fi
    exit 1
fi
rm "$PHP_TEST_SCRIPT"
echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"


echo -n "Creating md5_digest function in Oracle"
cat <<EOF >"$PHP_TEST_SCRIPT"
<?php
\$db = @OCILogon( "$EZDB_USER", "$EZDB_PASSWORD", "$EZDB_INSTANCE" );
if ( !\$db )
{
    \$error = OCIError();
    if ( \$error['code'] != 0 )
    {
        print( "Connection error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
        exit( 1 );
    }
}
\$statement = OCIParse( \$db, 'CREATE OR REPLACE FUNCTION md5_digest (vin_string IN VARCHAR2)
RETURN VARCHAR2 IS
--
-- Return an MD5 hash of the input string.
--
BEGIN
RETURN
    lower(dbms_obfuscation_toolkit.md5(input =>
                                       utl_raw.cast_to_raw(vin_string)));
END md5_digest;
' );
if ( !OCIExecute( \$statement, OCI_DEFAULT ) )
{
    \$error = OCIError();
    if ( \$error['code'] != 0 )
    {
        print( "SQL error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
        print( "SQL was:\n" . \$sql );
        OCIFreeStatement( \$statement );
        if ( \$error["code"] == "01920" )
            exit( 2 );
        exit( 1 );
    }
}
OCIFreeStatement( \$statement );

?>
EOF
ORACLE_ERROR=`php "$PHP_TEST_SCRIPT"`
if [ $? -ne 0 -o -n "$ORACLE_ERROR" ]; then
    rm "$PHP_TEST_SCRIPT"
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "Failed to create md5_digest function:"
    echo $ORACLE_ERROR
    echo
    exit 1
fi
rm "$PHP_TEST_SCRIPT"
echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"


# We have the necessary information and can initializese
# the database, create the schema and insert data

cd "$EZP_PATH" || exit 1

echo -n "Cleaning up eZ elements (if any)"
cat <<EOF >"$PHP_TEST_SCRIPT"
<?php

include_once( 'lib/ezutils/classes/ezcli.php' );
include_once( 'kernel/classes/ezscript.php' );

\$cli =& eZCLI::instance();
\$script =& eZScript::instance( array( 'description' => ( "" ),
                                       'use-session' => false,
                                       'use-modules' => true,
                                       'use-extensions' => true ) );

\$script->startup();

\$options = \$script->getOptions( "", "", array() );
\$script->initialize();

include_once( 'lib/ezdb//classes/ezdb.php' );
\$dbdata = array(
'server' => '',
'user' => '$EZDB_USER',
'password' => '$EZDB_PASSWORD',
'database' => '$EZDB_INSTANCE' );
\$db =& eZDB::instance( 'ezoracle', \$dbdata, true );
if ( !\$db )
{
    print( "Could initialize eZOracle database driver\\n" );
    \$script->shutdown( 1 );
}
include_once( 'lib/ezdb/classes/ezdbtool.php' );
\$status = eZDBTool::cleanup( \$db );
if ( !\$status )
{
    print( "Failed cleaning up database\n" );
    print( "Error(" . \$db->errorNumber() . "): " . \$db->errorMessage() . "\n" );
    \$script->shutdown( 1 );
}
\$script->shutdown();
?>
EOF
ORACLE_ERROR=`php "$PHP_TEST_SCRIPT"`
if [ $? -ne 0 ]; then
    rm "$PHP_TEST_SCRIPT"
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "$ORACLE_ERROR"
    exit 1
fi
rm "$PHP_TEST_SCRIPT"
echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"

# Create the schema

echo -n "Creating schema"
cat <<EOF >"$PHP_TEST_SCRIPT"
<?php

include_once( 'lib/ezutils/classes/ezcli.php' );
include_once( 'kernel/classes/ezscript.php' );

\$cli =& eZCLI::instance();
\$script =& eZScript::instance( array( 'description' => ( "" ),
                                       'use-session' => false,
                                       'use-modules' => true,
                                       'use-extensions' => true ) );

\$script->startup();

\$options = \$script->getOptions( "", "", array() );
\$script->initialize();

include_once( 'lib/ezdb//classes/ezdb.php' );
\$dbdata = array(
'server' => '',
'user' => '$EZDB_USER',
'password' => '$EZDB_PASSWORD',
'database' => '$EZDB_INSTANCE' );
\$db =& eZDB::instance( 'ezoracle', \$dbdata, true );
if ( !is_object( \$db ) )
{
    fputs( STDERR, "Could initialize eZOracle database driver\\n" );
    \$script->shutdown( 1 );
}
if ( !\$db->isConnected() )
{
    fputs( STDERR, "Could initialize eZOracle database driver\\n:" );
    \$msg = \$db->errorMessage();
    if ( \$msg )
    {
        \$number = \$db->errorNumber();
        if ( \$number > 0 )
            \$msg .= '(' . \$number . ')';
        fputs( STDERR, '* ' . \$msg . "\\n" );
    }
    \$script->shutdown( 1 );
}
include_once( 'lib/ezdbschema/classes/ezdbschema.php' );
\$schema = eZDBSchema::read( '$EZSCHEMA_PATH' );
if ( !\$schema )
{
    fputs( STDERR, "Failed loading database schema file $EZSCHEMA_PATH\\n" );
    \$script->shutdown( 1 );
}
\$dbSchema = eZDBSchema::instance( array( 'type' => 'oracle',
                                         'instance' => &\$db,
                                         'schema' => \$schema ) );
if ( !\$dbSchema )
{
    fputs( STDERR, "Failed loading Oracle schema handler\\n" );
    \$script->shutdown( 1 );
}

if ( !\$dbSchema->insertSchema() )
{
    fputs( STDERR, "Failed inserting schema to Oracle\\n" );
    \$script->shutdown( 1 );
}

\$script->shutdown();
?>
EOF

ORACLE_ERROR=`php "$PHP_TEST_SCRIPT" -derror,warning &>/dev/stdout`
# echo
# echo "$ORACLE_ERROR" > .txt
# exit
if [ $? -ne 0 ]; then
    rm "$PHP_TEST_SCRIPT"
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "$ORACLE_ERROR"
    exit 1
fi
rm "$PHP_TEST_SCRIPT"
echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"


# Create the schema

echo -n "Importing data"
#ORG_DATA_FILE="$EZP_PATH/kernel/sql/common/cleandata.sql"
#if [ ! -f "$ORG_DATA_FILE" ]; then
#    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
#    echo "Could not find data file, $ORG_DATA_FILE does not exist"
#    exit 1
#fi
#DATA_FILE_DIR="$TMPDIR"
#DATA_FILE="$DATA_FILE_DIR/.cleandata.sql"
#sed 's/\(contentobject_attr\)ibute\(_version\)/\1\2/g' "$ORG_DATA_FILE" | sed "s/\\\\'/''/g" > "$DATA_FILE"
#if [ ! -f "$DATA_FILE" ]; then
#    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
#    echo "Could not copy data file to $DATA_FILE"
#    exit 1
#fi
cat <<EOF >"$PHP_TEST_SCRIPT"
<?php

include_once( 'lib/ezutils/classes/ezcli.php' );
include_once( 'kernel/classes/ezscript.php' );

\$cli =& eZCLI::instance();
\$script =& eZScript::instance( array( 'description' => ( "" ),
                                       'use-session' => false,
                                       'use-modules' => true,
                                       'use-extensions' => true ) );

\$script->startup();

\$options = \$script->getOptions( "", "", array() );
\$script->initialize();

include_once( 'lib/ezdb//classes/ezdb.php' );
\$dbdata = array(
'server' => '',
'user' => '$EZDB_USER',
'password' => '$EZDB_PASSWORD',
'database' => '$EZDB_INSTANCE' );
\$db =& eZDB::instance( 'ezoracle', \$dbdata, true );
if ( !is_object( \$db ) )
{
    fputs( STDERR, "Could initialize eZOracle database driver\\n" );
    \$script->shutdown( 1 );
}
if ( !\$db->isConnected() )
{
    fputs( STDERR, "Could initialize eZOracle database driver\\n:" );
    \$msg = \$db->errorMessage();
    if ( \$msg )
    {
        \$number = \$db->errorNumber();
        if ( \$number > 0 )
            \$msg .= '(' . \$number . ')';
        fputs( STDERR, '* ' . \$msg . "\\n" );
    }
    \$script->shutdown( 1 );
}
include_once( 'lib/ezdbschema/classes/ezdbschema.php' );
\$schemaArray = eZDBSchema::read( '$EZSCHEMA_PATH', true );
if ( !\$schemaArray )
{
    fputs( STDERR, "Failed loading database schema file $EZSCHEMA_PATH\\n" );
    \$script->shutdown( 1 );
}
\$dataArray = eZDBSchema::read( '$EZDATA_PATH', true );
if ( !\$dataArray )
{
    fputs( STDERR, "Failed loading database data file $EZDATA_PATH\\n" );
    \$script->shutdown( 1 );
}
\$schemaArray = array_merge( \$schemaArray, \$dataArray );
\$schemaArray['type'] = 'oracle';
\$schemaArray['instance'] =& \$db;
\$dbSchema = eZDBSchema::instance( \$schemaArray );
if ( !\$dbSchema )
{
    fputs( STDERR, "Failed loading Oracle schema handler\\n" );
    \$script->shutdown( 1 );
}

if ( !\$dbSchema->insertSchema( array( 'schema' => false,
                                      'data' => true ) ) )
{
    fputs( STDERR, "Failed inserting data to Oracle\\n" );
    \$script->shutdown( 1 );
}

\$script->shutdown();
?>
EOF
ORACLE_ERROR=`php "$PHP_TEST_SCRIPT" -dall &>/dev/stdout`
if [ $? -ne 0 ]; then
#    rm "$PHP_TEST_SCRIPT"
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "$ORACLE_ERROR"
    exit 1
fi
echo "$ORACLE_ERROR" > .txt
rm "$PHP_TEST_SCRIPT"
echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"
