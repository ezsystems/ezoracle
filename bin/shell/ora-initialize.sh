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
TEST_INSTANCE="orcl"

ADMIN_USER="system"
ADMIN_PASSWORD="manager"
ADMIN_INSTANCE="orcl"

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

### @todo we should give the user a chance to use a custom php.ini if he wants to

PHP=php

echo -n "Testing PHP"
if ! ora_which php &>/dev/null; then
    echo "`$MOVE_TO_COL``$SETCOLOR_WARNING`[ Warning ]`$SETCOLOR_NORMAL`"
    echo "No PHP executable found on PATH, please enter php executable complete with the path"
    echo -n "PHP command line executable: "
    read PHP
    if [ ! -x "$PHP" ]; then
        echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
        echo "No PHP executable found"
        exit 1
    fi
fi

PHPVERSION=`$PHP --version|grep 'PHP 5'`
if [ -z "$PHPVERSION" ]; then
        echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
        echo "PHP executable is not the correct version. This version of the ezoracle extension only supports PHP 5"
        exit 1
fi

echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"

# Oracle variables should be correctly setup before we can continue

echo "Testing Oracle installation"
ORA_WARNING=""

echo -n "  tnsnames.ora file:"
if [ -z "$TNS_ADMIN" ]; then
    if [ -z "$ORACLE_HOME" ]; then
        EZNETADMIN=""
    else
        EZNETADMIN=$ORACLE_HOME/network/admin
    fi
else
    EZNETADMIN=$TNS_ADMIN
fi
if [ -z "$EZNETADMIN" ] || [ ! -f "$EZNETADMIN/tnsnames.ora" ]; then
    if [ -z "$EZNETADMIN" ]; then
        echo "`$MOVE_TO_COL``$SETCOLOR_WARNING`[ Warning ]`$SETCOLOR_NORMAL`"
        echo "  The environment variables ORACLE_HOME and TNS_ADMIN are not set"
        echo "  so it is impossible to find the tnsnames.ora file"
        ORA_WARNING="1"
    else
        if [ ! -f "$EZNETADMIN/tnsnames.ora" ]; then
            echo "`$MOVE_TO_COL``$SETCOLOR_WARNING`[ Warning ]`$SETCOLOR_NORMAL`"
            echo "  The file tnsnames.ora is missing from \$ORACLE_HOME"
            echo "  ($EZNETADMIN)"
            ORA_WARNING="2"
        fi
    fi
    echo
    echo "  Oracle usually requires this file to figure out the service and"
    echo "  hostname for the DB server. You should copy this from the DB server"
    echo "  and do some modifications to it, or use Oracle Easy Naming connections"
    echo
    echo "  Oracle is usually found in `$SETCOLOR_DIRECTORY`/usr/oracle`$SETCOLOR_NORMAL`, e.g."
    echo "  export ORACLE_HOME=\"/usr/oracle\""
    #exit 1
else
    echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"
    echo "  The file tnsnames.ora has been found in $EZNETADMIN"
fi

echo -n "  LD_LIBRARY_PATH environment variable:"
### @todo we could actually check to see if oci libs are to be found inside LD_LIBRARY_PATH (and maybe in . ?)
if [ -z "$LD_LIBRARY_PATH" ]; then
    echo "`$MOVE_TO_COL``$SETCOLOR_WARNING`[ Warning ]`$SETCOLOR_NORMAL`"
    echo "  the environment variable LD_LIBRARY_PATH is not set"
    echo
    echo "  If you are using an Oracle Instant Client install, make sure that"
    echo "  the environment variable LD_LIBRARY_PATH is set and that it includes"
    echo "  the directory where the instant client has been installed"
    echo
    ORA_WARNING="3"
    #exit 1
else
    echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"
    echo "  The environment variable \$LD_LIBRARY_PATH is set to:"
    echo "  $LD_LIBRARY_PATH"
    echo
    echo "  If you are using an Oracle Instant Client install, make sure that"
    echo "  the environment variable LD_LIBRARY_PATH includes the directory"
    echo "  where the instant client has been installed"
    echo
fi

if [ ! -z "$ORA_WARNING" ]; then
    echo -n "Press [enter] to continue, [q+enter] to quit: "
    question=`echo $question | tr [A-Z] [a-z]`
    read question
    if [ "$question" == "q" ]; then
        exit
    fi
fi

# Test if PHP has oracle compiled in

PHP_TEST_SCRIPT="$TMPDIR/.ezoracle_test.php"

cat <<EOF >"$PHP_TEST_SCRIPT"
<?php
if ( !extension_loaded( "oci8" ) )
    exit( 1 );
?>
EOF

echo -n "Testing PHPs Oracle support"
$PHP "$PHP_TEST_SCRIPT" &>/dev/null
if [ $? -ne 0 ]; then
    rm "$PHP_TEST_SCRIPT"
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "Your PHP installation does not have support for Oracle (OCI8)"
    echo "compiled in and enabled."
    echo "You will need to do that before this script can continue"
    echo
    echo "You can find more information on installing the OCI8 extension on www.php.net"
    exit 1
fi
rm "$PHP_TEST_SCRIPT"
echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"


# Find eZ Publish and make sure it has the extension

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
#            echo "No EZ_SDK_VERSION_STATE"
                    return 1
                fi
#        else
#        echo "No EZ_SDK_VERSION_MINOR"
            fi
#    else
#        echo "No EZ_SDK_VERSION_MAJOR"
        fi
    else
        return 1
    fi
    return 0
}

echo -n "Looking for eZ Publish installation"
if ! is_ezpublish_dir "$EZP_PATH"; then
    # also check if running from ezoracle bin/shell dir
    if ! is_ezpublish_dir "$EZP_PATH/../../../.."; then
        EZP_PATH=""
    else
        EZP_PATH="$EZP_PATH/../../../.."
        EZORACLE_EXT_PATH="$EZORACLE_EXT_PATH/../.."
    fi
else
    EZORACLE_EXT_PATH="$EZP_PATH/extension/ezoracle"
fi
if [ -z "$EZP_PATH" ]; then
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "No eZ Publish directory found"
    while [ -z "$EZP_PATH" ]; do
    echo -n "Please enter full eZ Publish path: "
    read DIR
    if [ -z "$DIR" ]; then
        exit
    fi
    if [ ! -d "$DIR" ]; then
        echo "Directory $DIR does not exist"
    elif ! is_ezpublish_dir "$DIR"; then
        echo "Directory $DIR is not an eZ Publish installation"
    else
        EZP_PATH="$DIR"
    fi
    done
    echo -n "Checking eZ Publish installation"
    echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"
    EZORACLE_EXT_PATH="$EZP_PATH/extension/ezoracle"
else
    echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"
fi

echo -n "Testing eZOracle extension"
if [ ! -d "$EZORACLE_EXT_PATH" ]; then
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "The Oracle extension is not installed in the eZ Publish directory"
    echo "Please copy the extension to $EZP_PATH/extension/"
    exit 1
fi
if [ ! -f "$EZORACLE_EXT_PATH/ezdb/dbms-drivers/ezoracledb.php" ]; then
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "The Oracle extension is not installed in the eZ Publish directory"
    echo "Please copy the extension to $EZP_PATH/extension/"
    exit 1
fi

# this is the first php script that needs to be run from eZ root dir
cd "$EZP_PATH" || exit 1

cat <<EOF >"$PHP_TEST_SCRIPT"
<?php

require 'autoload.php';

\$cli = eZCLI::instance();
\$script = eZScript::instance( array( 'description' => ( "" ),
                                       'use-session' => false,
                                       'use-modules' => true,
                                       'use-extensions' => true ) );

\$script->startup();

\$options = \$script->getOptions( "", "", array() );
\$script->initialize();

//include_once( 'lib/ezdb/classes/ezdb.php' );
\$extensions = eZExtension::activeExtensions();
if ( !in_array( "ezoracle", \$extensions ) )
{
    print( "eZOracle extension is present but not enabled\\n" );
    \$script->shutdown( 1 );
}

\$script->shutdown();
?>
EOF
ORACLE_ERROR=`$PHP "$PHP_TEST_SCRIPT"`
if [ $? -ne 0 ]; then
    rm "$PHP_TEST_SCRIPT"
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "$ORACLE_ERROR"
    exit 1
fi
rm "$PHP_TEST_SCRIPT"
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

    ### @todo use sed to make sure USER (or at least pwd) does not break php code!!!

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

    echo -n "Testing Oracle availability (this might take a while...)"
    cat <<EOF >"$PHP_TEST_SCRIPT"
<?php
\$db = @oci_connect( "$USER", "$PASSWORD", "$INSTANCE" );
if ( !\$db )
{
    \$error = oci_error();
    if ( \$error['code'] != 0 )
    {
        print( "Connection error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
    }
    exit( 1 );
}
?>
EOF
    ORACLE_ERROR=`$PHP "$PHP_TEST_SCRIPT"`
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
        echo "The instance that is used is not correctly configured on the server"
        echo "or client. You should examine these files in the oracle home directory:"
        echo "  network/admin/tnsnames.ora, network/admin/sqlnet.ora and"
        echo "  network/admin/listener.ora (the last one only on the server)"
        echo
        echo "For more info you can also run the following command: tnsping $INSTANCE"
        echo
        echo "Another cause of this error is that there is no listener active on the"
        echo "DB server. Log into the DB server as the Oracle user then try to run:"
        echo "  lsnrctl status"
        echo "If no active database server is listed, try to run:"
        echo "  lsnrctl start"
        echo "The listener(s) should then be started"
    else
        echo "Unknown error, reasons for this can be:"
        echo "- The user or password incorrect."
        echo "  Review your input and try again."
        echo "- The instance that is used is not correctly configured on the server"
        echo "  or client. You should examine these files in the oracle home directory:"
        echo "    network/admin/tnsnames.ora, network/admin/sqlnet.ora and"
        echo "    network/admin/listener.ora (the last one  only on server)"
        echo
        echo "  For more info you can also run the following command: tnsping $INSTANCE"
        echo
        echo "Another source of this error is that there is no listener active on the"
        echo "DB server. Log into the DB server as the Oracle user then try to run:"
        echo "  lsnrctl status"
        echo "If no active database server is listed, try to run:"
        echo "  lsnrctl start"
        echo "The listener(s) should then be started"
    fi
    exit 1
    fi
    rm "$PHP_TEST_SCRIPT"
    echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"
    ADMIN_INSTANCE="$TEST_INSTANCE"
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

    echo -n "New User Tablespace: (leave empty for system default) "
    read TABLESPACE

    EZDB_HAS_USER="1"
    EZDB_USER="$USER"
    EZDB_PASSWORD="$PASSWORD"
    EZDB_INSTANCE="$INSTANCE"

    if [ -z "$TABLESPACE" ]; then
        echo -n "Checking for default tablespace for new users"
        cat <<EOF >"$PHP_TEST_SCRIPT"
<?php
\$db = @oci_connect( "$AUSER", "$APASSWORD", "$INSTANCE" );
if ( !\$db )
{
    \$error = oci_error();
    if ( \$error['code'] != 0 )
    {
        print( "Connection error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
    }
    exit( 1 );
}
\$sql = "SELECT PROPERTY_VALUE
FROM DATABASE_PROPERTIES
WHERE PROPERTY_NAME = 'DEFAULT_PERMANENT_TABLESPACE'";
\$statement = oci_parse( \$db, \$sql );
\$tablespace = 'SYSTEM';
if ( !@oci_execute( \$statement, OCI_DEFAULT ) ) // view might not exist
{
    \$error = oci_error( \$statement );
    if ( \$error['code'] != 0 )
    {
        if ( \$error['code'] == 942 ) // view does not exist
        {
            exit;
        }
        print( "SQL error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
        print( "SQL was:\n" . \$sql );
        exit( 1 );
    }
}
else
{
    \$row = oci_fetch_array( \$statement );
    if ( \$row !== false )
    {
        echo \$row['PROPERTY_VALUE'];
    }
}
?>
EOF
        ORACLE_ERROR=`$PHP "$PHP_TEST_SCRIPT"`
        if [ $? -ne 0 ]; then
            rm "$PHP_TEST_SCRIPT"
            echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
            echo "Checking for default tablespace failed:"
            echo $ORACLE_ERROR
            echo
            exit 1
        fi
        rm "$PHP_TEST_SCRIPT"
        if [ -z "$ORACLE_ERROR" ]; then
            TABLESPACE="SYSTEM"
        else
            TABLESPACE=$ORACLE_ERROR
        fi
        echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"
    fi

    SQL="CREATE USER $USER IDENTIFIED BY $PASSWORD DEFAULT TABLESPACE $TABLESPACE QUOTA UNLIMITED ON $TABLESPACE;
GRANT CREATE    SESSION   TO $USER;
GRANT CREATE    TABLE     TO $USER;
GRANT CREATE    TRIGGER   TO $USER;
GRANT CREATE    SEQUENCE  TO $USER;
GRANT CREATE    PROCEDURE TO $USER;"
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
\$db = @oci_connect( "$AUSER", "$APASSWORD", "$INSTANCE" );
if ( !\$db )
{
    \$error = oci_error();
    if ( \$error['code'] != 0 )
    {
        print( "Connection error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
    }
    exit( 1 );
}
\$sqls = explode( ";", "$SQL" );
foreach ( \$sqls as \$sql )
{
    if ( trim( \$sql ) == '' )
        continue;
    \$statement = oci_parse( \$db, \$sql );
    if ( !oci_execute( \$statement, OCI_DEFAULT ) )
    {
        \$error = oci_error( \$statement );
        if ( \$error['code'] != 0 )
        {
            print( "SQL error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
            print( "SQL was:\n" . \$sql );
            if ( \$error["code"] == "01920" )
                exit( 2 );
        }
        exit( 1 );
    }
    oci_free_statement( \$statement );
}
?>
EOF
    ORACLE_ERROR=`$PHP "$PHP_TEST_SCRIPT"`
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

# Make sure we have a proper username and password
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
\$db = @oci_connect( "$EZDB_USER", "$EZDB_PASSWORD", "$EZDB_INSTANCE" );
if ( !\$db )
{
    \$error = oci_error();
    if ( \$error['code'] != 0 )
    {
        print( "Connection error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
    }
    exit( 1 );
}
else
{
    \$statement = oci_parse( \$db, "select VALUE from NLS_DATABASE_PARAMETERS where PARAMETER='NLS_CHARACTERSET'" );
    if ( !oci_execute( \$statement, OCI_DEFAULT ) )
    {
        \$error = oci_error( \$statement );
        if ( \$error['code'] != 0 )
        {
            print( "SQL error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
            print( "SQL was:\n" . \$sql );
        }
        exit( 2 );
    }
    else
    {
        \$row = oci_fetch_array( \$statement );
        if ( \$row !== false )
        {
            echo \$row['VALUE'];
        }
    }
}
?>
EOF
ORACLE_ERROR=`$PHP "$PHP_TEST_SCRIPT"`
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
    echo "Unknown error, reasons for this can be:"
    echo "- The user or password incorrect."
    echo "  Review your input and try again."
    echo "- The instance that is used is not correctly configured on the server/client."
    echo "  You should examine these files:"
    echo "    network/admin/tnsnames.ora, network/admin/sqlnet.ora and"
    echo "    network/admin/listener.ora (only on server)"
    echo "    in the oracle home directory (\$ORACLE_HOME)"
    echo "  For more info you can also run the following command: tnsping $INSTANCE"
    echo
    echo "Another source of this error is that there is no listener active on the DB server"
    echo "Log into the DB server as the Oracle user then try to run:"
    echo "lsnrctl status"
    echo "If no active database server is listed, try to run:"
    echo "lsnrctl start"
    echo "The listener(s) should then be started"
    fi
    exit 1
else
    rm "$PHP_TEST_SCRIPT"
    echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"
    echo -n "Testing Oracle database character set"
    if [ "$ORACLE_ERROR" = "AL32UTF8" ]; then
        echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"
    else
        echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
        echo "The internal character set used by the database is $ORACLE_ERROR."
        echo "For best performances, it is recommended to set it to AL32UTF8."
        echo "You should at least make sure that the character set $ORACLE_ERROR is a"
        echo "superset of the charcter sets that will be used for the contents of this"
        echo "eZ Publish installation. E.g. WE8ISO889P1 is fine if you only plan to"
        echo "have content in european languages."
        echo "If in doubt, please alter your database configuration before continuing."
        if [ -z "$NLS_LANG" ]; then
            echo
            echo "Also note that, since the NLS_LANG environment variable appears"
            echo "not to be set, if the database server is at version 9.1 or lower,"
            echo "php might not be able to connect using the correct character set."
            echo "This configuration is not supported"
        fi
        echo
        echo -n "Press [enter] to continue, [q+enter] to quit: "
        question=`echo $question | tr [A-Z] [a-z]`
        read question
        if [ "$question" == "q" ]; then
            exit
        fi
    fi
fi

echo -n "Creating md5_digest function in Oracle"
cat <<EOF >"$PHP_TEST_SCRIPT"
<?php
\$db = @oci_connect( "$EZDB_USER", "$EZDB_PASSWORD", "$EZDB_INSTANCE" );
if ( !\$db )
{
    \$error = oci_error();
    if ( \$error['code'] != 0 )
    {
        print( "Connection error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
    }
    exit( 1 );
}
\$statement = oci_parse( \$db, preg_replace( array( '#\r#', '#^[ \n]+#', '#[ \n]*/[ \n]*\$#' ), array( '', '', '' ), file_get_contents( '$EZORACLE_EXT_PATH/sql/md5_digest.sql' ) ) );
if ( !oci_execute( \$statement, OCI_DEFAULT ) )
{
    \$error = oci_error( \$statement );
    if ( \$error['code'] != 0 )
    {
        print( "SQL error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
        print( "SQL was:\n" . \$sql );
        if ( \$error["code"] == "01920" )
            exit( 2 );
    }
    exit( 1 );
}

?>
EOF
ORACLE_ERROR=`$PHP "$PHP_TEST_SCRIPT"`
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

echo -n "Creating bitor function in Oracle"
cat <<EOF >"$PHP_TEST_SCRIPT"
<?php
\$db = @oci_connect( "$EZDB_USER", "$EZDB_PASSWORD", "$EZDB_INSTANCE" );
if ( !\$db )
{
    \$error = oci_error();
    if ( \$error['code'] != 0 )
    {
        print( "Connection error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
    }
    exit( 1 );
}
\$statement = oci_parse( \$db, preg_replace( array( '#\r#', '#^[ \n]+#', '#[ \n]*/[ \n]*\$#' ), array( '', '', '' ), file_get_contents( '$EZORACLE_EXT_PATH/sql/bitor.sql' ) ) );
if ( !oci_execute( \$statement, OCI_DEFAULT ) )
{
    \$error = oci_error( \$statement );
    if ( \$error['code'] != 0 )
    {
        print( "SQL error(" . \$error["code"] . "):\n" . \$error["message"] .  "\n" );
        print( "SQL was:\n" . \$sql );
        if ( \$error["code"] == "01920" )
            exit( 2 );
    }
    exit( 1 );
}

?>
EOF
ORACLE_ERROR=`$PHP "$PHP_TEST_SCRIPT"`
if [ $? -ne 0 -o -n "$ORACLE_ERROR" ]; then
    rm "$PHP_TEST_SCRIPT"
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "Failed to create bitor function:"
    echo $ORACLE_ERROR
    echo
    exit 1
fi
rm "$PHP_TEST_SCRIPT"
echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"

# We have the necessary information and can initialize
# the database, create the schema and insert data

echo -n "Cleaning up eZ elements (if any)"
cat <<EOF >"$PHP_TEST_SCRIPT"
<?php

require 'autoload.php';

\$cli = eZCLI::instance();
\$script = eZScript::instance( array( 'description' => ( "" ),
                                       'use-session' => false,
                                       'use-modules' => true,
                                       'use-extensions' => true ) );

\$script->startup();

\$options = \$script->getOptions( "", "", array() );
\$script->initialize();

//include_once( 'lib/ezdb/classes/ezdb.php' );
\$dbdata = array(
'server' => '',
'user' => '$EZDB_USER',
'password' => '$EZDB_PASSWORD',
'database' => '$EZDB_INSTANCE' );
\$db = eZDB::instance( 'ezoracle', \$dbdata, true );
if ( !is_object( \$db ) )
{
    fputs( STDERR, "Could not initialize eZOracle database driver\\n" );
    \$script->shutdown( 1 );
}
if ( !\$db->isConnected() )
{
    fputs( STDERR, "Could not connect to Oracle database:\\n" );
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
//include_once( 'lib/ezdb/classes/ezdbtool.php' );
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
ORACLE_ERROR=`$PHP "$PHP_TEST_SCRIPT" &>/dev/stdout`
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

require 'autoload.php';

\$cli = eZCLI::instance();
\$script = eZScript::instance( array( 'description' => ( "" ),
                                       'use-session' => false,
                                       'use-modules' => true,
                                       'use-extensions' => true ) );

\$script->startup();

\$options = \$script->getOptions( "", "", array() );
\$script->initialize();

//include_once( 'lib/ezdb/classes/ezdb.php' );
\$dbdata = array(
'server' => '',
'user' => '$EZDB_USER',
'password' => '$EZDB_PASSWORD',
'database' => '$EZDB_INSTANCE' );
\$db = eZDB::instance( 'ezoracle', \$dbdata, true );
if ( !is_object( \$db ) )
{
    fputs( STDERR, "Could not initialize eZOracle database driver\\n" );
    \$script->shutdown( 1 );
}
if ( !\$db->isConnected() )
{
    fputs( STDERR, "Could not connect to Oracle database:\\n" );
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
//include_once( 'lib/ezdbschema/classes/ezdbschema.php' );
\$schema = eZDbSchema::read( '$EZSCHEMA_PATH' );
if ( !\$schema )
{
    fputs( STDERR, "Failed loading database schema file $EZSCHEMA_PATH\\n" );
    \$script->shutdown( 1 );
}
\$dbSchema = eZDbSchema::instance( array( 'type' => 'oracle',
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
ORACLE_ERROR=`$PHP "$PHP_TEST_SCRIPT" -derror,warning &>/dev/stdout`
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

require 'autoload.php';

\$cli = eZCLI::instance();
\$script = eZScript::instance( array( 'description' => ( "" ),
                                       'use-session' => false,
                                       'use-modules' => true,
                                       'use-extensions' => true ) );

\$script->startup();

\$options = \$script->getOptions( "", "", array() );
\$script->initialize();

//include_once( 'lib/ezdb/classes/ezdb.php' );
\$dbdata = array(
'server' => '',
'user' => '$EZDB_USER',
'password' => '$EZDB_PASSWORD',
'database' => '$EZDB_INSTANCE' );
\$db = eZDB::instance( 'ezoracle', \$dbdata, true );
if ( !is_object( \$db ) )
{
    fputs( STDERR, "Could not initialize eZOracle database driver\\n" );
    \$script->shutdown( 1 );
}
if ( !\$db->isConnected() )
{
    fputs( STDERR, "Could not connect to Oracle database:\\n" );
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
//include_once( 'lib/ezdbschema/classes/ezdbschema.php' );
\$schemaArray = eZDbSchema::read( '$EZSCHEMA_PATH', true );
if ( !\$schemaArray )
{
    fputs( STDERR, "Failed loading database schema file $EZSCHEMA_PATH\\n" );
    \$script->shutdown( 1 );
}
\$dataArray = eZDbSchema::read( '$EZDATA_PATH', true );
if ( !\$dataArray )
{
    fputs( STDERR, "Failed loading database data file $EZDATA_PATH\\n" );
    \$script->shutdown( 1 );
}
\$schemaArray = array_merge( \$schemaArray, \$dataArray );
\$schemaArray['type'] = 'oracle';
\$schemaArray['instance'] =& \$db;
\$dbSchema = eZDbSchema::instance( \$schemaArray );
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
ORACLE_ERROR=`$PHP "$PHP_TEST_SCRIPT" -dall &>/dev/stdout`
if [ $? -ne 0 ]; then
    rm "$PHP_TEST_SCRIPT"
    echo "`$MOVE_TO_COL``$SETCOLOR_FAILURE`[ Failure ]`$SETCOLOR_NORMAL`"
    echo "$ORACLE_ERROR"
    exit 1
fi
rm "$PHP_TEST_SCRIPT"
#echo "$ORACLE_ERROR" > .txt

echo "`$MOVE_TO_COL``$SETCOLOR_SUCCESS`[ Success ]`$SETCOLOR_NORMAL`"