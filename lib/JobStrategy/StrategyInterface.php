<?php
namespace Resque\JobStrategy;

use Resque\Worker;
use Resque\Job;

/**
 * Interface that all job strategy backends should implement.
 *
 * @package		Resque/JobStrategy
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @author		Erik Bernharsdon <bernhardsonerik@gmail.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
interface StrategyInterface
{
    /**
     * Set the Resque\Worker instance
     *
     * @param Worker $worker
     */
    function setWorker(Worker $worker);

    /**
     * Separates the job execution context from the worker and calls $worker->perform($job).
     *
     * @param Job $job
     */
    function perform(Job $job);

    /**
     * Force an immediate shutdown of the worker, killing any child jobs
     * currently working
     */
    function shutdown();
}
