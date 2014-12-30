<?php
namespace Resque\JobStrategy;

use Psr\Log\LogLevel;
use Resque\Job;

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
        $this->child = $this->fork();

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

    /**
     * Attempt to fork a child process from the parent to run a job in.
     *
     * Return values are those of pcntl_fork().
     *
     * @return int 0 for the forked child, or the PID of the child for the parent.
     * @throws \RuntimeException When pcntl_fork returns -1
     */
    private function fork()
    {
        $pid = pcntl_fork();
        if($pid === -1) {
            throw new \RuntimeException('Unable to fork child worker.');
        }

        return $pid;
    }
}
