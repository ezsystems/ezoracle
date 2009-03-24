<?php
/**
 * File containing the eZOracleTestSuite class
 *
 * @copyright Copyright (C) 2009 eZ Systems AS. All rights reserved.
 * @license http://ez.no/licenses/gnu_gpl GNU GPLv2
 * @package tests
 *
 * @todo add a suite of tests for cluster mode
 */

class ezoracleTestSuite extends ezpTestSuite
{
    public function __construct()
    {
        parent::__construct();
        $this->setName( "eZ Oracle Extension Test Suite" );

        $this->addTestSuite( 'eZPostgreSQLDBTest' );
    }

    public static function suite()
    {
        return new self();
    }
}

?>
