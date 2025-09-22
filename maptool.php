<?php

require_once __DIR__ . '/vendor/autoload.php';
use App\MapFileProcessor;

ini_set('memory_limit', '256M');
ini_set('post_max_size', '60M');

if ($argc < 2 ) {
  die ('No input file name specified');
}

$zipFile = $argv[1];
echo "\nInput file: $zipFile\n";

// Lets measure the execution time
$time_start = microtime(true);

$mapData = new MapFileProcessor($zipFile);
print_r($mapData->getMapStats());

$time_end = microtime(true);
$execution_time = ($time_end - $time_start);
echo '<b>Total Execution Time:</b> '.$execution_time.' seconds';