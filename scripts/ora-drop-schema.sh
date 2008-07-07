#!/bin/sh

if [ -z "$1" ]; then
    echo "Usage: $0 <login_string>"
    echo "4 ex.: $0 scott/tiger@orcl"
    exit 1
fi

# drop all sequences starting with 'ez' or 'sibanklist'
login_string=$1
#select_seqs_sql="SELECT sequence_name FROM user_sequences where sequence_name LIKE 'EZ%' OR sequence_name LIKE 'S_%' OR sequence_name LIKE 'SIBANKLIST%';"
#select_tables_sql="SELECT table_name FROM user_tables WHERE table_name LIKE 'EZ%' OR table_name LIKE 'SIBANKLIST%';"
select_seqs_sql="SELECT sequence_name FROM user_sequences;"
select_tables_sql="SELECT table_name FROM user_tables;"

[ -z "$sqlplus" ] && sqlplus=sqlplus

ezseqs=`echo "$select_seqs_sql"     | $sqlplus -S $login_string | grep -viE 'sequence_name|^-{10}|rows s' | grep .`
eztables=`echo "$select_tables_sql" | $sqlplus -S $login_string | grep -viE 'table_name|^-{10}|rows s' | grep .`

[ "$ezseqs"   = 'No rows retrieved.' ]  && ezseqs=''
[ "$eztables" = 'No rows retrieved.' ]  && eztables=''

# drop all tables and sequences starting with 'ez'

for ezseq in $ezseqs; do
    echo "DROP SEQUENCE $ezseq;"
done | $sqlplus $login_string

for eztable in $eztables; do
    echo "DROP TABLE $eztable;"
done | $sqlplus $login_string

# select trigger_name from dba_triggers where trigger_name like 'EZ%';
# select index_name from dba_indexes where index_name like 'EZ%';
