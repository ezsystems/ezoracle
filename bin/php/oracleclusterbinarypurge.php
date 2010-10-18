<?php
/**
 * Cluster binary files purge script for oracle DFS implementation
 *
 * @copyright Copyright (C) 2010 eZ Systems AS. All rights reserved.
 * @license http://ez.no/licenses/gnu_gpl GNU General Public License v2.0
 *
 * @todo harden the script to disallow the passing of directories external to
 *       eZ Publish as root directories
 */

require 'autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array(
    'description' =>
        "eZ Publish binary files purge for Oracle DFS handler\n" .
        "Physically purges expired (leftover) binary files\n",
    'use-session' => false,
    'use-modules' => false,
    'use-extensions' => true ) );
$script->startup();
$options = $script->getOptions( '[dry-run][iteration-sleep:][iteration-limit:]' /*[start-from:]*/, '', array(
    'dry-run' => 'Test mode, output the list of affected files without removing them',
    'iteration-sleep' => 'Amount of seconds to sleep between each iteration when performing a purge operation, can be a float. Default is one second.',
    'iteration-limit' => 'Amount of items to remove in each iteration when performing a purge operation. Default is 100.',
    /*'start-from' => ''*/ ) );
$script->initialize();

$dry_run = false;
if ( $options['dry-run'] )
{
    $dry_run = $options['dry-run'];
}
$iteration_sleep = 0;
if ( $options['iteration-sleep'] )
{
    $iteration_sleep = (int)( $options['iteration-sleep'] * 1000000 );
}
$iteration_limit = 100;
if ( $options['iteration-limit'] )
{
    $iteration_limit = (int)$options['iteration-limit'];
}
/// @todo find a better subset to check for: only 'image' and 'binaryfile' files
$start_from = array( 'var' );
/*if ( $options['start-from'] )
{
    $start_from = array( $options['start-from'] );
}*/

/// @todo: check if current db file handler is not ezdfs then exit

$dbbackend = eZExtension::getHandlerClass(
    new ezpExtensionOptions(
        array(
            'iniFile'     => 'file.ini',
            'iniSection'  => 'eZDFSClusteringSettings',
            'iniVariable' => 'DBBackend' ) ) );
if ( method_exists( $dbbackend, 'dfspurge' ) )
{
    $dbbackend->_connect( false );
    $deletCount = 0;
    foreach ( $start_from as $rootdir )
    {
        $dbbackend->dfspurge( $rootdir, 'logDeletes', $iteration_sleep, $iteration_limit, $dry_run );
    }
    $cli->output( $dry_run ? "\nFound $deletCount files to delete" : "\nDeleted $deletCount files" );
}
else
{
    $cli->error( "Your current cluster handler does not require DFS binary purge" );
}

$script->shutdown();


function logDeletes( $files )
{
    global $cli, $isQuiet, $deletCount;
    if ( !$isQuiet )
    {
        foreach ( $files as $file )
        {
            $cli->output( $file );
        }
    }
    $deletCount += count( $files );
}

?>
