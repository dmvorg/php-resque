<?php
namespace Resque\JobStrategy;

use Psr\Log\LogLevel;
use Resque\Job;
use Resque\Resque;

/**
 * Separates the job execution environment from the worker via pcntl_fork
 *
 * @package		Resque/JobStrategy
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @author		Erik Bernharsdon <bernhardsonerik@gmail.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Fork extends InProcess
{
    /**
     * @param int|null 0 for the forked child, the PID of the child for the parent, or null if no child.
     */
    protected $child;

    /**
     * Separate the job from the worker via pcntl_fork
     *
     * @param Job $job
     */
    public function perform(Job $job)
    {
        $this->child = Resque::fork();

        // Forked and we're the child. Run the job.
        if ($this->child === 0) {
            parent::perform($job);
            exit(0);
        }

        // Parent process, sit and wait
        if($this->child > 0) {
            $status = 'Forked ' . $this->child . ' at ' . strftime('%F %T');
            $this->worker->updateProcLine($status);
            $this->worker->logger->log(LogLevel::INFO, $status);

            // Wait until the child process finishes before continuing
            pcntl_wait($status);
            $exitStatus = pcntl_wexitstatus($status);
            if($exitStatus !== 0) {
                $job->fail(new Job\DirtyExitException(
                    'Job exited with exit code ' . $exitStatus
                ));
            }
        }

        $this->child = null;
    }

    /**
     * Force an immediate shutdown of the worker, killing any child jobs
     * currently working
     */
    public function shutdown()
    {
        if (!$this->child) {
            $this->worker->logger->log(LogLevel::DEBUG, 'No child to kill.');
            return;
        }

        $this->worker->logger->log(LogLevel::INFO, 'Killing child at '.$this->child);
        if (exec('ps -o pid,state -p ' . $this->child, $output, $returnCode) && $returnCode != 1) {
            $this->worker->logger->log(LogLevel::DEBUG, 'Killing child at ' . $this->child);
            posix_kill($this->child, SIGKILL);
            $this->child = null;
        } else {
            $this->worker->logger->log(LogLevel::INFO, 'Child ' . $this->child . ' not found, restarting.');
            $this->worker->shutdown();
        }
    }
}
