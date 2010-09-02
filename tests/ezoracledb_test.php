<?php
/**
 * @todo move the relationlist/relationcount tests out of this into a generic db testsuite
 * @todo test using alphanumeric col name in arrayquery
 * @todo test arrayquery with all combinations of offset, limit (in generic db testsuite)
 * @todo test arrayquery with and without offset, limit and enabled driver charset conversion
 * @todo test fix for issue #015436 (in generic db testsuite )
 */

class eZOracleDBTest extends ezpDatabaseTestCase
{
    protected $insertDefaultData = false;

    public function __construct()
    {
        parent::__construct();
        $this->setName( "eZOracleDB Unit Tests" );
    }

    protected function setUp()
    {
        parent::setUp();

        if ( $this->sharedFixture->databaseName() !== "oracle" )
            self::markTestSkipped( "Not running Oracle, skipping" );

        ezpTestDatabaseHelper::clean( $this->sharedFixture );
    }

    public function testRelationCounts()
    {
        $db = $this->sharedFixture;
        $db->query( "CREATE TABLE a ( name varchar(40) )" );
        $db->query( "CREATE TABLE b ( name varchar(40) )" );
        $db->query( "CREATE TABLE c ( name varchar(40) )" );

        $relationCount = $db->relationCounts( eZDBInterface::RELATION_TABLE_BIT );
        self::assertEquals( 3, (int) $relationCount );
    }

    public function testRelationCount()
    {
        $db = $this->sharedFixture;
        $db->query( "CREATE TABLE a ( name varchar(40) )" );
        $db->query( "CREATE TABLE b ( name varchar(40) )" );
        $db->query( "CREATE TABLE c ( name varchar(40) )" );

        $relationCount = $db->relationCount( eZDBInterface::RELATION_TABLE );
        self::assertEquals( 3, (int) $relationCount );
    }

    public function testRelationList()
    {
        $db = $this->sharedFixture;
        $db->query( "CREATE TABLE a ( name varchar(40) )" );
        $db->query( "CREATE TABLE b ( name varchar(40) )" );
        $db->query( "CREATE TABLE c ( name varchar(40) )" );

        $relationList = $db->relationList( eZDBInterface::RELATION_TABLE );
        $relationArray = array( "a", "b", "c" );
        self::assertEquals( $relationArray, $relationList );
    }
}

?>