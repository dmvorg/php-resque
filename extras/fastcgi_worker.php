<?php

use Resque\Resque;

// Look for parent project's Composer autoloader
$path = __DIR__.'/../../../vendor/autoload.php';
if (!file_exists($path)) {
    // Fallback to this project's autoloader
    $path = __DIR__.'/../vendor/autoload.php';
}
// Die if Composer hasn't been run yet
require_once $path;

if (empty($_POST['RESQUE_JOB'])) {
    header('Status: 400 Missing Job', true, 400);
    return;
}

if (isset($_SERVER['REDIS_BACKEND'])) {
    Resque::setBackend($_SERVER['REDIS_BACKEND']);
}

try {
    if (isset($_SERVER['APP_INCLUDE'])) {
        require_once $_SERVER['APP_INCLUDE'];
    }

    $job = unserialize($_POST['RESQUE_JOB']);

    if (!($job instanceof Job)) {
        // Allow job to be marked as failed in listener
        header('Status: 500', true, 500);
        echo 'Could not unserialize job';
        exit(3);
    }

    $job->worker->perform($job);

} catch (\Exception $e) {
    if (isset($job)) {
        $job->fail($e);
    } else {
        header('Status: 500', true, 500);
    }
}
