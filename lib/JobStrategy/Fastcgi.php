<?php
namespace Resque\JobStrategy;

use Psr\Log\LogLevel;
use Resque\Worker;
use Resque\Job;
use EBernhardson\FastCGI\Client;
use EBernhardson\FastCGI\CommunicationException;

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
        $this->fcgi->setKeepAlive(true);

        // Don't allow empty headers
        $this->requestData = array_filter(array_merge(array(
            'SCRIPT_FILENAME' => $script,
            'SERVER_NAME'     => php_uname('n'),
        ), self::$defaultRequestData, $environment));
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

        $response = null;
        for ($retries = 2; $retries; $retries--) {
            try {
                if (!$this->fcgi) {
                    $this->fcgi = new Client($this->location, $this->port);
                    $this->fcgi->setKeepAlive(true);
                }

                // Send job data as POST content
                $this->fcgi->request($headers, $content);
                // Will block until response
                $response = $this->fcgi->response();
                break;

            } catch (CommunicationException $e) {
                if ($retries) {
                    // Maybe the connection got stale; try to re-open
                    $this->fcgi->close();
                    $this->fcgi = null;
                } else {
                    $this->waiting = false;
                    $job->fail($e);
                    return;
                }
            }
        }

        $this->waiting = false;

        if ($response['statusCode'] !== 200) {
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
        $this->fcgi->close();
    }
}
