<?php
namespace Resque;

/**
 * Base Resque class.
 *
 * @package		Resque
 * @author         Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class Resque
{
    const VERSION = '1.2';

    const DEFAULT_INTERVAL = 5;

    /**
     * @var Redis Instance of Resque\Redis that talks to redis.
     */
    public static $redis = null;

    /**
     * @var mixed Host/port combination separated by a colon, or a nested
     * array of servers with host/port pairs
     */
    protected static $redisServer = null;

    /**
     * @var int ID of Redis database to select.
     */
    protected static $redisDatabase = 0;

    /**
     * Given a host/port combination separated by a colon, set it as
     * the redis server that Resque will talk to.
     *
     * @param mixed $server Host/port combination separated by a colon, DSN-formatted URI, or
     *                      a callable that receives the configured database ID
     *                      and returns a Resque\Redis instance, or
     *                      a nested array of servers with host/port pairs.
     * @param int   $database
     */
    public static function setBackend($server, $database = 0)
    {
        self::$redisServer   = $server;
        self::$redisDatabase = $database;
        self::$redis         = null;
    }

    /**
     * Return an instance of the Resque\Redis class instantiated for Resque.
     *
     * @return Redis Instance of Resque\Redis.
     */
    public static function redis()
    {
        if (self::$redis !== null) {
            return self::$redis;
        }

        if (is_callable(self::$redisServer)) {
            self::$redis = new Redis(call_user_func(self::$redisServer, self::$redisDatabase));
        } else {
            self::$redis = new Redis(self::$redisServer, self::$redisDatabase);
        }

        return self::$redis;
    }

    /**
     * fork() helper method for php-resque that handles issues PHP socket
     * and phpredis have with passing around sockets between child/parent
     * processes.
     * Will close connection to Redis before forking.
     *
     * @return int Return vars as per pcntl_fork()
     */
    public static function fork()
    {
        if (!function_exists('pcntl_fork')) {
            return -1;
        }

        // Close the connection to Redis before forking.
        // This is a workaround for issues phpredis has.
        self::$redis = null;

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork child worker.');
        }

        return $pid;
    }

    /**
     * Push a job to the end of a specific queue. If the queue does not
     * exist, then create it as well.
     *
     * @param string $queue The name of the queue to add the job to.
     * @param array  $item  Job description as an array to be JSON encoded.
     * @return boolean
     */
    public static function push($queue, $item)
    {
        self::redis()->sadd('queues', $queue);
        $length = self::redis()->rpush('queue:' . $queue, json_encode($item));
        if ($length < 1) {
            return false;
        }
        return true;
    }

    /**
     * Pop an item off the end of the specified queue, decode it and
     * return it.
     *
     * @param string $queue The name of the queue to fetch an item from.
     * @return array Decoded item from the queue.
     */
    public static function pop($queue)
    {
        $item = self::redis()->lpop('queue:' . $queue);

        if (!$item) {
            return null;
        }

        return json_decode($item, true);
    }

    /**
     * Remove items of the specified queue
     *
     * @param string $queue The name of the queue to fetch an item from.
     * @param array  $items
     * @return integer number of deleted items
     */
    public static function dequeue($queue, $items = Array())
    {
        if (count($items) > 0) {
            return self::removeItems($queue, $items);
        } else {
            return self::removeList($queue);
        }
    }

    /**
     * Pop an item off the end of the specified queues, using blocking list pop,
     * decode it and return it.
     *
     * @param array $queues
     * @param int   $timeout
     * @return null|array   Decoded item from the queue.
     * @throws \RedisException
     */
    public static function blpop(array $queues, $timeout)
    {
        $list = array();
        foreach ($queues AS $queue) {
            $list[] = 'queue:' . $queue;
        }

        $timeout = (int) $timeout;
        $startTime = microtime(true);
        $item = self::redis()->blpop($list, $timeout);

        if (!$item) {
            // if block takes <10% of expected time and returned nothing
            if ($timeout > 1 && $diff = (microtime(true) - $startTime) < $timeout*.10) {
                // might try self::redis()->info() first too
                $diff = number_format($diff, 2);
                throw new \RedisException("blpop expected {$timeout}s, failed in {$diff}s");
            }
            return null;
        }

        /**
         * Normally the Resque\Redis class returns queue names without the prefix
         * But the blpop is a bit different. It returns the name as prefix:queue:name
         * So we need to strip off the prefix:queue: part
         */
        $queue = substr($item[0], strlen(self::redis()->getPrefix() . 'queue:'));

        return array(
            'queue'   => $queue,
            'payload' => json_decode($item[1], true)
        );
    }

    /**
     * Return the size (number of pending jobs) of the specified queue.
     *
     * @param string $queue name of the queue to be checked for pending jobs
     * @return int The size of the queue.
     */
    public static function size($queue)
    {
        return self::redis()->llen('queue:' . $queue);
    }

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string  $queue       The name of the queue to place the job in.
     * @param string  $class       The name of the class that contains the code to execute the job.
     * @param array   $args        Any optional arguments that should be passed when the job is executed.
     * @param boolean $trackStatus Set to true to be able to monitor the status of a job.
     * @return string
     */
    public static function enqueue($queue, $class, $args = null, $trackStatus = false)
    {
        $result = Job::create($queue, $class, $args, $trackStatus);
        if ($result) {
            Event::trigger('afterEnqueue', array(
                'class' => $class,
                'args'  => $args,
                'queue' => $queue,
                'id'    => $result,
            ));
        }

        return $result;
    }

    /**
     * Reserve and return the next available job in the specified queue.
     *
     * @param string $queue Queue to fetch next available job from.
     * @return Job Resque\Job Instance of Resque\Job to be processed, false if none or error.
     */
    public static function reserve($queue)
    {
        return Job::reserve($queue);
    }

    /**
     * Get an array of all known queues.
     *
     * @return array Array of queues.
     */
    public static function queues()
    {
        $queues = self::redis()->smembers('queues');
        if (!is_array($queues)) {
            $queues = array();
        }
        return $queues;
    }

    /**
     * Remove Items from the queue
     * Safely moving each item to a temporary queue before processing it
     * If the Job matches, counts otherwise puts it in a requeue_queue
     * which at the end eventually be copied back into the original queue
     *
     * @private
     * @param string $queue The name of the queue
     * @param array  $items
     * @return integer number of deleted items
     */
    private static function removeItems($queue, $items = Array())
    {
        $counter       = 0;
        $originalQueue = 'queue:' . $queue;
        $tempQueue     = $originalQueue . ':temp:' . time();
        $requeueQueue  = $tempQueue . ':requeue';

        // move each item from original queue to temp queue and process it
        $finished = false;
        while (!$finished) {
            $string = self::redis()->rpoplpush($originalQueue, self::redis()->getPrefix() . $tempQueue);

            if (!empty($string)) {
                if (self::matchItem($string, $items)) {
                    $counter++;
                } else {
                    self::redis()->rpoplpush($tempQueue, self::redis()->getPrefix() . $requeueQueue);
                }
            } else {
                $finished = true;
            }
        }

        // move back from temp queue to original queue
        $finished = false;
        while (!$finished) {
            $string = self::redis()->rpoplpush($requeueQueue, self::redis()->getPrefix() . $originalQueue);
            if (empty($string)) {
                $finished = true;
            }
        }

        // remove temp queue and Resque queue
        self::redis()->del($requeueQueue);
        self::redis()->del($tempQueue);

        return $counter;
    }

    /**
     * matching item
     * item can be ['class'] or ['class' => 'id'] or ['class' => {:foo => 1, :bar => 2}]
     *
     * @private
     * @param string $string redis result in json
     * @param $items
     * @return bool
     */
    private static function matchItem($string, $items)
    {
        $decoded = json_decode($string, true);

        foreach ($items as $key => $val) {
            # class name only  ex: item[0] = ['class']
            if (is_numeric($key)) {
                if ($decoded['class'] == $val) {
                    return true;
                }
                # class name with args , example: item[0] = ['class' => {'foo' => 1, 'bar' => 2}]
            } elseif (is_array($val)) {
                $decodedArgs = (array) $decoded['args'][0];
                if ($decoded['class'] == $key &&
                    count($decodedArgs) > 0 && count(array_diff($val, $decodedArgs)) == 0
                ) {
                    return true;
                }
                # class name with ID, example: item[0] = ['class' => 'id']
            } else {
                if ($decoded['class'] == $key && $decoded['id'] == $val) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Remove List
     *
     * @private
     * @param string $queue the name of the queue
     * @return integer number of deleted items belongs to this list
     */
    private static function removeList($queue)
    {
        $counter = self::size($queue);
        $result  = self::redis()->del('queue:' . $queue);
        return ($result == 1) ? $counter : 0;
    }


    /**
     * Extends PHP-Resque adding pub/sub broadcast-style event dispatching, similar to RabbitMQ's Fanout.
     *
     * @package task
     * @author Jesse Decker <jesse.decker@dmv.org>
     * @date 2014-12-11
     */

    /**
     * Register a queue as listening to an exchange.
     * @param string $exchange
     * @param string $queue
     */
    public static function subscribe($exchange, $queue)
    {
        if (!$queue) {
            throw new \InvalidArgumentException("queue param must be supplied");
        }

        /** @var \Redis $redis */
        $redis = self::redis();
        $redis->sadd("exchanges:$exchange", $queue);
        $redis->sadd('exchanges', $exchange);
    }

    /**
     * Remove a queue from an exchange.
     * @param string $exchange
     * @param string $queue
     */
    public static function unsubscribe($exchange, $queue)
    {
        if (!$queue) {
            throw new \InvalidArgumentException("queue param must be supplied");
        }

        /** @var \Redis $redis */
        $redis = self::redis();
        $redis->srem("exchanges:$exchange", $queue);
        if ($redis->scard("exchanges:$exchange") == 0) {
            $redis->srem('exchanges', $exchange);
        }
    }

    /**
     * Return list of registered queues for the provided exchange.
     * @param string $exchange
     * @return array List of queues in exchange
     */
    public static function queues_for($exchange)
    {
        /** @var \Redis $redis */
        $redis = self::redis();
        $queues = [];
        if ($redis->sismember('exchanges', $exchange)) {
            $queues = $redis->smembers("exchanges:$exchange");
        }
        return $queues;
    }

    /**
     * Return list of all exchanges.
     * @return array
     */
    public static function exchanges()
    {
        /** @var \Redis $redis */
        $redis = self::redis();
        $members = $redis->smembers('exchanges');
        $exchanges = [];
        foreach ($members as $exchange) {
            $exchanges[] = [
                'exchange' => $exchange,
                'queues' => self::queues_for($exchange),
            ];
        }
        return $exchanges;
    }

    /**
     * Push an event onto all registered queues for an exchange.
     * @param string $exchange
     * @param string $class
     * @param array $args
     */
    public static function publish($exchange, $class, array $args = null)
    {
        $queues = self::queues_for($exchange) ?: [];
        foreach ($queues as $queue) {
            self::push($queue, [
                'class' => $class,
                'args'  => array($args),
                //'id'    => $id, Used in Resque_Job::create() but probably not needed here
            ]);
        }
    }
}
