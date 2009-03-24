<?php
/**
 * File containing the eZOracleTestSuite class
 *
 * @copyright Copyright (C) 2009 eZ Systems AS. All rights reserved.
 * @license http://ez.no/licenses/gnu_gpl GNU GPLv2
 * @package tests
 */

class ezoracleTestSuite extends ezpTestSuite
{
    public function __construct()
    {
        parent::__construct();
        $this->setName( "eZ Oracle Extension Test Suite" );

        /// @todo...
    }

    public static function suite()
    {
        return new self();
    }
}

?>
