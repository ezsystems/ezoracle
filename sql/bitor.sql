CREATE OR replace FUNCTION bitor( x IN NUMBER, y IN NUMBER )
RETURN NUMBER  AS
--
-- Return an bitwise 'or' value of the input arguments.
--
BEGIN
    RETURN x + y - bitand(x,y);
END bitor;
/
