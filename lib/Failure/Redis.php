<?php
namespace Resque\Failure;

use Resque\Resque;
use Resque\Failure_Interface;
use stdClass;

/**
 * Redis backend for storing failed Resque jobs.
 *
 * @package		Resque/Failure
 * @author         Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class Redis implements Failure_Interface
{
    const QUEUE_NAME = 'failed';

    /**
     * Initialize a failed job class and save it (where appropriate).
     *
     * @param object $payload   Object containing details of the failed job.
     * @param \Exception $exception Instance of the exception that was thrown by the failed job.
     * @param object $worker    Instance of Resque\Worker that received the job.
     * @param string $queue     The name of the queue the job was fetched from.
     */
    public function __construct($payload, $exception, $worker, $queue)
    {
        $data = array(
            'failed_at' => strftime('%a %b %d %H:%M:%S %Z %Y'),
            'payload'   => $payload,
            'exception' => get_class($exception),
            'error'     => $exception->getMessage(),
            'backtrace' => explode("\n", $exception->getTraceAsString()),
            'worker'    => (string) $worker,
            'queue'     => $queue,
        );
        $data = json_encode($data);
        Resque::redis()->rpush(self::QUEUE_NAME, $data);
    }

    /**
     * Delete any failure queue items after $len length.
     *
     * @param $len int
     */
    public static function limitQueueLength($len)
    {
        $redis = Resque::redis();
        while ($redis->llen(self::QUEUE_NAME) > $len) {
            $redis->lpop(self::QUEUE_NAME);
        }
    }
}
