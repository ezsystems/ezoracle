-- START: from 4.6.0 using DFS cluster setup
ALTER TABLE ezdfsfile MODIFY datatype VARCHAR2(255);
-- END: from 4.6.0 using cluster setup

-- START: from 4.6.0 using DB cluster setup
ALTER TABLE ezdbfile MODIFY datatype VARCHAR2(255);
-- END: from 4.6.0 using cluster setup
