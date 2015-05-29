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

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Status: 400 Bad Request Type', true, 400);
    return;
}

if (isset($_SERVER['REDIS_BACKEND'])) {
    Resque::setBackend($_SERVER['REDIS_BACKEND']);
}

try {
    if (isset($_SERVER['APP_INCLUDE'])) {
        require_once $_SERVER['APP_INCLUDE'];
    }

    // Use raw POST data to skip PHP's variable parsing
    // JSON encapsulation saves us from UTF8 character hell
    $job = unserialize(json_decode(file_get_contents('php://input')));

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
