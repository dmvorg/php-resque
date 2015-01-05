<?php
namespace Resque\Job;

use Resque\Resque;

/**
 * Status tracker/information for a job.
 *
 * @package		Resque/Job
 * @author         Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class Status
{
    const STATUS_WAITING = 'queued';
    const STATUS_RUNNING = 'working';
    const STATUS_FAILED = 'failed';
    const STATUS_COMPLETE = 'completed';

    /**
     * @var string The ID of the job this status class refers back to.
     */
    private $id;

    /**
     * @var mixed Cache variable if the status of this job is being monitored or not.
     *    True/false when checked at least once or null if not checked yet.
     */
    private $isTracking = null;

    /**
     * @var array Array of statuses that are considered final/complete.
     */
    private static $completeStatuses = array(
        self::STATUS_FAILED,
        self::STATUS_COMPLETE
    );

    /**
     * Setup a new instance of the job monitor class for the supplied job ID.
     *
     * @param string $id The ID of the job to manage the status for.
     */
    public function __construct($id)
    {
        $this->id = $id;
    }

    /**
     * Create a new status monitor item for the supplied job ID. Will create
     * all necessary keys in Redis to monitor the status of a job.
     *
     * @param string $id The ID of the job to monitor the status of.
     */
    public static function create($id)
    {
        $statusPacket = array(
            'status'  => self::STATUS_WAITING,
            'updated' => time(),
            'started' => time(),
        );
        Resque::redis()->set('job:' . $id . ':status', json_encode($statusPacket));
    }

    /**
     * Check if we're actually checking the status of the loaded job status
     * instance.
     *
     * @return boolean True if the status is being monitored, false if not.
     */
    public function isTracking()
    {
        if ($this->isTracking === false) {
            return false;
        }

        if (!Resque::redis()->exists((string) $this)) {
            $this->isTracking = false;
            return false;
        }

        $this->isTracking = true;
        return true;
    }

    /**
     * Update the status indicator for the current job with a new status.
     *
     * @param int $status The status of the job (see constants in Resque\Job\Status)
     * @param mixed $result The result of the job
     */
    public function update($status, $result = null)
    {
        if (!$this->isTracking()) {
            return;
        }

        $statusPacket = array(
            'status'  => $status,
            'updated' => time(),
            'result'  => $result,
        );
        Resque::redis()->set((string) $this, json_encode($statusPacket));

        // Expire the status for completed jobs after 24 hours
        if (in_array($status, self::$completeStatuses)) {
            Resque::redis()->expire((string) $this, 86400);
        }
    }

    /**
     * Fetch the status for the job being monitored.
     *
     * @return false|array False if the status is not being monitored, otherwise the status array
     */
    public function getAll()
    {
        if (!$this->isTracking()) {
            return false;
        }

        $statusPacket = json_decode(Resque::redis()->get((string) $this), true);
        if (!$statusPacket) {
            return false;
        }

        return $statusPacket;
    }

    /**
     * Fetch the status for the job being monitored.
     *
     * @return mixed False if the status is not being monitored, otherwise the status as
     *    as an integer, based on the Resque\Job\Status constants.
     */
    public function get()
    {
        $statusPacket = $this->getAll();

        if ($statusPacket) {
            $statusPacket = $statusPacket['status'];
        }

        return $statusPacket;
    }

    /**
     * Stop tracking the status of a job.
     */
    public function stop()
    {
        Resque::redis()->del((string) $this);
    }

    /**
     * Generate a string representation of this object.
     *
     * @return string String representation of the current job status class.
     */
    public function __toString()
    {
        return 'job:' . $this->id . ':status';
    }
}
