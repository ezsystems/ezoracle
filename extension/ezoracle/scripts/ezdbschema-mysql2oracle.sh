#!/bin/sh
# Transfers schema and data from MySQL to Oracle using ezdbschema interface
# that is available in eZ Publish 3.5+.
#
# The script doesn't introduce any new functionality. It just calls
# subsequently standard eZ Publish scripts.
#
# The result should be equal to running
# ora-drop-schema.php, mysql2oracle-schema.php,
# mysql2oracle-data.php and ora-update-seqs.php
#
# Adjust database connection parameters below, then
# run this script from root directory of eZ Publish.

MY_DB=stable35
MY_USER=root
MY_PASS=
MY_SERVER=localhost

ORA_USER=scott
ORA_PASS=tiger
ORA_INSTANCE=orcl

###############################################################################
function check_rc()
{
    if [ $? != 0 ]; then
        echo An error occured.
        exit 1
    fi
}

function is_ezpublish_dir
{
    if [ ! -f "index.php" -o ! -f "lib/version.php" -o ! -d "extension" ]; then
        return 1
    fi

    if grep -q 'EZ_SDK_VERSION_MAJOR' lib/version.php 2>/dev/null &&
       grep -q 'EZ_SDK_VERSION_MINOR' lib/version.php 2>/dev/null &&
       grep -q 'EZ_SDK_VERSION_STATE' lib/version.php 2>/dev/null
    then
        return 0
    fi

    return 1
}
###############################################################################

if ! is_ezpublish_dir; then
    echo Please go to root directory of your eZ Publish installation.
    exit 1
fi

# dump schema
bin/php/ezsqldumpschema.php -ddebug,warning,error \
    --output-array --output-types=schema \
    --type=ezmysql --user=$MY_USER --host=$MY_SERVER $MY_DB $MY_DB-schema.txt

check_rc

# dump data
bin/php/ezsqldumpschema.php -ddebug,warning,error \
    --output-array --output-types=data \
    --type=ezmysql --user=$MY_USER --host=$MY_SERVER $MY_DB $MY_DB-data.txt

check_rc

# insert schema
bin/php/ezsqlinsertschema.php -ddebug,warning,error \
    --clean-existing --insert-types=schema \
    --type=oracle --user=$ORA_USER --password=$ORA_PASS \
    $MY_DB-schema.txt $ORA_INSTANCE

check_rc

# insert data
bin/php/ezsqlinsertschema.php -ddebug,warning,error \
    --insert-types=data \
    --type=oracle --user=$ORA_USER --password=$ORA_PASS \
    --schema-file=$MY_DB-schema.txt $MY_DB-data.txt $ORA_INSTANCE

check_rc
