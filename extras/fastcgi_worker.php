<?php

use Resque\Resque;

if (empty($_POST['RESQUE_JOB'])) {
    header('Status: 500 No Job');
    return;
}
// If reverted to a $_SERVER var, must use urldecode()
$job = $_POST['RESQUE_JOB'];

// Look for parent project's Composer autoloader
$path = __DIR__.'/../../../vendor/autoload.php';
if (!file_exists($path)) {
    // Fallback to this project's autoloader
    $path = __DIR__.'/../vendor/autoload.php';
}
// Die if Composer hasn't been run yet
require_once $path;

if (isset($_SERVER['REDIS_BACKEND'])) {
    Resque::setBackend($_SERVER['REDIS_BACKEND']);
}

try {
    if (isset($_SERVER['APP_INCLUDE'])) {
        require_once $_SERVER['APP_INCLUDE'];
    }

    $job = unserialize($job);
    $job->worker->perform($job);
} catch (\Exception $e) {
    if (isset($job)) {
        $job->fail($e);
    } else {
        header('Status: 500');
    }
}
