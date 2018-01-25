<?php
namespace Resque\JobStrategy;

use EBernhardson\FastCGI\Client;
use EBernhardson\FastCGI\CommunicationException;
use EBernhardson\FastCGI\TimedOutException;
use Psr\Log\LogLevel;
use Resque\Worker;
use Resque\Job;

/**
 * @package Resque/JobStrategy
 * @author  Erik Bernhardson <bernhardsonerik@gmail.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class Fastcgi implements StrategyInterface
{
    /**
     * @var array Default environment for all FastCGI requests
     */
    public static $defaultRequestData = array(
        'GATEWAY_INTERFACE' => 'FastCGI/1.0',
        'SERVER_SOFTWARE' => 'php-resque-fastcgi/1.3-dev',
        'REMOTE_ADDR' => '127.0.0.1',
        'REMOTE_PORT' => 8888,
        'SERVER_ADDR' => '127.0.0.1',
        'SERVER_PORT' => 8888,
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        // Send data as post (don't change this)
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    );

    /**
     * @var bool True when waiting for a response from FastCGI server
     */
    private $waiting = false;

    /**
     * @var array Environment for FastCGI requests, created upon __construct()
     */
    protected $requestData;

    /** @var string */
    private $location;
    /** @var int */
    private $port;
    /** @var Client */
    private $fcgi;
    /** @var Worker */
    private $worker;

    /**
     * @param string $location	When the location contains a `:` it will be considered a host/port pair
     *							otherwise a unix socket path
     * @param string $script	  Absolute path to the script that will load resque and perform the job
     * @param array  $environment Additional environment variables available in $_SERVER to the FastCGI script
     */
    public function __construct($location, $script, $environment = array())
    {
        $port = null; // must be === null
        if (false !== strpos($location, ':')) {
            list($location, $port) = explode(':', $location, 2);
        }
        $this->location = $location;
        $this->port = $port;

        $this->fcgi = new Client($location, $port);
        //$this->fcgi->setKeepAlive(true); << This can do bad things with dynamic FPM pools

        // Don't allow empty headers
        $this->requestData = array_filter(array_merge(array(
            'SCRIPT_FILENAME' => $script,
            'SERVER_NAME'     => php_uname('n'),
        ), self::$defaultRequestData, $environment));
    }

    /**
     * Clear open connections when serializing.
     * @return array
     */
    public function __sleep()
    {
        return [ 'location', 'port' ];
    }

    /**
     * @param Worker $worker
     */
    public function setWorker(Worker $worker)
    {
        $this->worker = $worker;
    }

    /**
     * Executes the provided job over a FastCGI connection
     *
     * @param Job $job
     */
    public function perform(Job $job)
    {
        // Update status
        $status = 'Requested fcgi job execution from ' . $this->location . ($this->port ? ':' . $this->port : '') . ' at ' . strftime('%F %T');
        $this->worker->updateProcLine($status);
        $this->worker->logger->log(LogLevel::DEBUG, $status);

        // Prepare request params
        $content = 'RESQUE_JOB=' . urlencode(serialize($job));
        $payload = $job->payload; // implicit copy
        $payload['queue'] = $job->queue; // add the queue-name
        unset($payload['args']); // remove arguments, which might be large
        $headers = $this->requestData; // implicit copy
        $headers['CONTENT_LENGTH'] = strlen($content);
        $headers['REQUEST_URI'] .= '?' . http_build_query($payload, null, '&');

        $this->waiting = true;

        $response = $responseObj = $startTime = null;
        for ($tries = 2; $tries; $tries--) {
            try {
                if (!$responseObj) {
                    $startTime = microtime(true);
                    // Send job data as POST content
                    $responseObj = $this->fcgi->asyncRequest($headers, $content);
                }
                break; // No need for retries

            } catch (CommunicationException $e) {
                if ($tries) {
                    // Connection got stale; try to re-open
                    $this->fcgi->close(); // kill socket just in case
                    $responseObj = null;
                    $this->worker->logger->log(LogLevel::NOTICE, 'Error sending job to FPM, restarting (' . $e->getMessage() . ')', [ 'e' => $e ]);
                } else {
                    $this->waiting = false;
                    $job->fail($e);
                    return;
                }
            }
        }

        for ($tries = 2; $tries; $tries--) {
            try {
                // Wait for response body
                $response = $responseObj->get();

            } catch (TimedOutException $e) {
                // Can try again...
                $this->worker->logger->log(LogLevel::INFO, 'FPM timeout after ' . round(microtime(true)-$startTime,4) . ' seconds (' . $e->getMessage() . ')', [ 'job' => (string) $job, 'e' => $e ]);

            } catch (CommunicationException $e) {
                // If retry at this point, we may run the job twice, so we must give up
                $this->waiting = false;
                $this->worker->logger->log(LogLevel::ERROR, "Job Failed after " . round(microtime(true)-$startTime,4) . " seconds", ['job' => (string) $job]);
                $job->fail($e);
                return;
            }
        }

        $this->waiting = false;

        // FPM tears down and re-builds children processes, so we can't keep the connection around
        // @see http://serverfault.com/questions/349947/php-fpm-timeout-issue-everyday
        $this->fcgi->close();

        if (
               !$response['statusCode'] // this would be odd, but it could happen
            || $response['statusCode'] > 299 // Allow the script to return any 200-ish status for logging purposes
            || strpos($response['body'], "\nFatal error: ") // Fatal Errors still return 200!!
        ) {
            $job->fail(new \Exception(sprintf(
                'FastCGI job returned non-200 status code: %s Stdout: %s Stderr: %s',
                $response['headers']['status'],
                $response['body'],
                $response['stderr']
            )));
        }
    }

    /**
     * Shutdown the worker process.
     */
    public function shutdown()
    {
        if ($this->waiting === false) {
            $this->worker->logger->log(LogLevel::INFO, 'No child to kill.');
        } else {
            $this->worker->logger->log(LogLevel::INFO, 'Closing fcgi connection with job in progress.');
        }
        if ($this->fcgi) {
            $this->fcgi->close();
        }
    }
}
