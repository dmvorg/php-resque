<?php

// Find and initialize Composer
use Resque\JobStrategy\Fastcgi;
use Resque\JobStrategy\Fork;
use Resque\JobStrategy\InProcess;
use Resque\Redis;
use Resque\Resque;
use Resque\Log;
use Resque\Worker;

$files = array(
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../../../../autoload.php',
    __DIR__ . '/../vendor/autoload.php',
);
foreach ($files as $file) {
    if (file_exists($file)) {
        require_once $file;
        break;
    }
}

if (!class_exists('Composer\Autoload\ClassLoader', false)) {
    die(
        'You need to set up the project dependencies using the following commands:' . PHP_EOL .
            'curl -s http://getcomposer.org/installer | php' . PHP_EOL .
            'php composer.phar install' . PHP_EOL
    );
}

$QUEUE = getenv('QUEUE');
if (empty($QUEUE)) {
    die("Set QUEUE env var containing the list of queues to work.\n");
}

/**
 * REDIS_BACKEND can have simple 'host:port' format or use a DSN-style format like this:
 * - redis://user:pass@host:port
 *
 * Note: the 'user' part of the DSN URI is required but is not used.
 */
$REDIS_BACKEND = getenv('REDIS_BACKEND');

// A redis database number
$REDIS_BACKEND_DB = getenv('REDIS_BACKEND_DB');
if (!empty($REDIS_BACKEND)) {
    if (empty($REDIS_BACKEND_DB))
        Resque::setBackend($REDIS_BACKEND);
    else
        Resque::setBackend($REDIS_BACKEND, $REDIS_BACKEND_DB);
}

$logLevel = false;
$LOGGING = getenv('LOGGING');
$VERBOSE = getenv('VERBOSE');
$VVERBOSE = getenv('VVERBOSE');
if (!empty($LOGGING) || !empty($VERBOSE)) {
    $logLevel = true;
} elseif (!empty($VVERBOSE)) {
    $logLevel = true;
}

$APP_INCLUDE = getenv('APP_INCLUDE');
if ($APP_INCLUDE) {
    if(!file_exists($APP_INCLUDE)) {
        die('APP_INCLUDE ('.$APP_INCLUDE.") does not exist.\n");
    }

    require_once $APP_INCLUDE;
}

// See if the APP_INCLUDE contains a logger object,
// If none exists, fallback to internal logger
if (!isset($logger) || !is_object($logger)) {
    $logger = new Log($logLevel);
}

$BLOCKING = getenv('BLOCKING') !== FALSE;

$interval = 5;
$INTERVAL = getenv('INTERVAL');
if (!empty($INTERVAL)) {
    $interval = $INTERVAL;
}

$count = 1;
$COUNT = getenv('COUNT');
if (!empty($COUNT) && $COUNT > 1) {
    $count = $COUNT;
}

$PREFIX = getenv('PREFIX');
if (!empty($PREFIX)) {
    $logger->log(Psr\Log\LogLevel::INFO, 'Prefix set to {prefix}', array('prefix' => $PREFIX));
    Redis::prefix($PREFIX);
}

$jobStrategy = null;
$JOB_STRATEGY = getenv('JOB_STRATEGY');
switch ($JOB_STRATEGY) {
    case 'inprocess':
        $jobStrategy = new InProcess;
        break;
    case 'fork':
        $jobStrategy = new Fork;
        break;
    case 'fastcgi':
        $fastcgiLocation = '127.0.0.1:9000';
        $FASTCGI_LOCATION = getenv('FASTCGI_LOCATION');
        if (!empty($FASTCGI_LOCATION)) {
            $fastcgiLocation = $FASTCGI_LOCATION;
        }

        $fastcgiScript = __DIR__.'/../extras/fastcgi_worker.php';
        $FASTCGI_SCRIPT = getenv('FASTCGI_SCRIPT');
        if (!empty($FASTCGI_SCRIPT)) {
            $fastcgiScript = $FASTCGI_SCRIPT;
        }

        require_once __DIR__.'/../lib/JobStrategy/Fastcgi.php';
        $jobStrategy = new Fastcgi(
            $fastcgiLocation,
            $fastcgiScript,
            array(
                'APP_INCLUDE'   => $APP_INCLUDE,
                'REDIS_BACKEND' => $REDIS_BACKEND,
            )
        );
        break;
}


if ($count > 1) {
    for ($i = 0; $i < $count; ++$i) {
        $pid = Resque::fork();
        if ($pid == -1) {
            $logger->log(Psr\Log\LogLevel::EMERGENCY, "Could not fork worker count:$count, i:$i");
            die();
        }
        // Child, start the worker
        elseif (!$pid) {
            $queues = explode(',', $QUEUE);
            $worker = new Worker($queues);
            $worker->setLogger($logger);
            if ($jobStrategy) {
                $worker->setJobStrategy($jobStrategy);
            }
            $logger->log(Psr\Log\LogLevel::NOTICE, 'Starting worker '.$worker);
            $worker->work($interval, $BLOCKING);
            break;
        }
    }
}
// Start a single worker
else {
    $queues = explode(',', $QUEUE);
    $worker = new Worker($queues);
    $worker->setLogger($logger);
    if ($jobStrategy) {
        $worker->setJobStrategy($jobStrategy);
    }

    $PIDFILE = getenv('PIDFILE');
    if ($PIDFILE) {
        file_put_contents($PIDFILE, getmypid()) or
            die('Could not write PID information to ' . $PIDFILE);
    }

    $logger->log(Psr\Log\LogLevel::NOTICE, 'Starting worker '.$worker);
    $worker->work($interval, $BLOCKING);
}
