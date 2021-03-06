<?php
namespace Resque;

/**
 * Interface that all failure backends should implement.
 *
 * @package        Resque\Resque/Resque\Failure
 * @author         Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
interface Failure_Interface
{
    /**
     * Initialize a failed job class and save it (where appropriate).
     *
     * @param object $payload   Object containing details of the failed job.
     * @param object $exception Instance of the exception that was thrown by the failed job.
     * @param object $worker    Instance of Resque\Worker that received the job.
     * @param string $queue     The name of the queue the job was fetched from.
     */
    public function __construct($payload, $exception, $worker, $queue);
}
