<?php
namespace Resque\Failure;

use Psr\Log\LoggerInterface;
use Resque\Failure_Interface;

/**
 * LoggerInterface backend for "storing" failed Resque jobs.
 *
 * @package Resque/Failure
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class Log implements Failure_Interface
{
    /** @var LoggerInterface */
    private static $logger;

    /**
     * Initialize a failed job class and log it.
     *
     * @param object $payload   Object containing details of the failed job.
     * @param \Exception $exception Instance of the exception that was thrown by the failed job.
     * @param object $worker    Instance of Resque\Worker that received the job.
     * @param string $queue     The name of the queue the job was fetched from.
     */
    public function __construct($payload, $exception, $worker, $queue)
    {
        $msg = "Resque Job Failure [{$queue}]: $exception";
        $data = array(
            //'failed_at' => strftime('%a %b %d %H:%M:%S %Z %Y'),
            'payload'   => $payload,
            //'exception' => get_class($exception),
            //'error'     => $exception->getMessage(),
            //'backtrace' => explode("\n", $exception->getTraceAsString()),
            'worker'    => (string) $worker,
            //'queue'     => $queue,
        );

        if (self::$logger) {
            self::$logger->error($msg, $data);
        } else {
            fwrite(STDERR, "$msg; " . json_encode($data));
        }
    }

    /**
     * Set the global Logger instance.
     * Must be called before any failures can be logged.
     *
     * @param LoggerInterface $logger
     */
    public static function setLogger(LoggerInterface $logger)
    {
        self::$logger = $logger;
    }
}
