<?php
namespace Resque\JobStrategy;

use Psr\Log\LogLevel;
use Resque\Worker;
use Resque\Job;

/**
 * Runs the job in the same process as Resque_Worker
 *
 * @package		Resque/JobStrategy
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @author		Erik Bernharsdon <bernhardsonerik@gmail.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class InProcess implements StrategyInterface
{
    /**
     * @var Worker Instance of Resque\Worker that is starting jobs
     */
    protected $worker;

    /**
     * Set the Resque_Worker instance
     *
     * @param Worker $worker
     */
    public function setWorker(Worker $worker)
    {
        $this->worker = $worker;
    }

    /**
     * Run the job in the worker process
     *
     * @param Job $job
     */
    public function perform(Job $job)
    {
        $status = 'Processing ' . $job->queue . ' since ' . strftime('%F %T');
        $this->worker->updateProcLine($status);
        $this->worker->logger->log(LogLevel::INFO, $status);
        $this->worker->perform($job);
    }

    /**
     * Force an immediate shutdown of the worker, killing any child jobs
     * currently working
     */
    public function shutdown()
    {
        $this->worker->logger->log(LogLevel::INFO, 'No child to kill.');
    }
}
