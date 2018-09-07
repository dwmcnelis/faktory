<?php

$path = dirname(__FILE__);

$mapping = array(
  'Faktory\Client' => $path . '/src/Client.php',
  'Faktory\FaktoryException' => $path . '/src/FaktoryException.php',
  'Faktory\Exception\BadResponseException' => $path . '/src/Exception/BadResponseException.php',
  'Faktory\Exception\NotConnectedException' => $path . '/src/Exception/NotConnectedException.php',
  'Faktory\Exception\UnsupportedProtocolException' => $path . '/src/Exception/UnsupportedProtocolException.php',
  'Faktory\Exception\WriteFailedException' => $path . '/src/Exception/WriteFailedException.php',
  'Faktory\Job' => $path . '/src/Job.php',
  'Faktory\JobInterface' => $path . '/src/JobInterface.php',
  'Faktory\Logger' => $path . '/src/Logger.php',
  'Faktory\ResolverInterface' => $path . '/src/ResolverInterface.php',
  'Faktory\Version' => $path . '/src/Version.php',
  'Faktory\Worker' => $path . '/src/Worker.php'
);

spl_autoload_register(function ($class) use ($mapping) {
  if (isset($mapping[$class])) {
    require $mapping[$class];
  }
}, true);
