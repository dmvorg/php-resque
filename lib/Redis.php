<?php
namespace Resque;

use Credis_Client;
use Credis_Cluster;
use CredisException;

/**
 * Wrap Credis to add namespace support and various helper methods.
 * @method exists
 * @method int del(string $key)
 * @method type
 * @method keys
 * @method expire
 * @method ttl
 * @method move
 * @method int set(string $key, string $val, int $optOrTimeout)
 * @method int setex(string $key, string $val, int $timeout)
 * @method string get(string $key) Return value at key
 * @method string getset(string $key, string $val) Set value and return previous value
 * @method int setnx(string $key, string $val) Set if not exists
 * @method incr
 * @method incrby
 * @method decr
 * @method decrby
 * @method rpush
 * @method lpush
 * @method int llen(string $key) Length of list
 * @method array lrange(string $key, int $start, int $end)
 * @method ltrim
 * @method lindex
 * @method lset
 * @method int lrem(string $key, string $val) Remove value from list
 * @method string lpop(string $key) Remove and return the first element from list
 * @method blpop
 * @method rpop
 * @method sadd
 * @method srem
 * @method spop
 * @method int scard(string $key) Number of elements in a set
 * @method sismember
 * @method array smembers(string $key) Returns all members for set at $key
 * @method srandmember
 * @method zadd
 * @method zrem
 * @method zrange
 * @method zrevrange
 * @method zrangebyscore
 * @method zcard
 * @method zscore
 * @method zremrangebyscore
 * @method sort
 * @method rename
 * @method rpoplpush
 *
 * @package		Resque/Redis
 * @author         Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class Redis
{
    /**
     * Redis namespace
     *
     * @var string
     */
    private static $defaultNamespace = 'resque:';

    /**
     * A default host to connect to
     */
    const DEFAULT_HOST = 'localhost';

    /**
     * The default Redis port
     */
    const DEFAULT_PORT = 6379;

    /**
     * The default Redis Database number
     */
    const DEFAULT_DATABASE = 0;

    /**
     * @var array List of all commands in Redis that supply a key as their
     *    first argument. Used to prefix keys with the Resque\Resque namespace.
     */
    private $keyCommands = array(
        'exists',
        'del',
        'type',
        'keys',
        'expire',
        'ttl',
        'move',
        'set',
        'setex',
        'get',
        'getset',
        'setnx',
        'incr',
        'incrby',
        'decr',
        'decrby',
        'rpush',
        'lpush',
        'llen',
        'lrange',
        'ltrim',
        'lindex',
        'lset',
        'lrem',
        'lpop',
        'blpop',
        'rpop',
        'sadd',
        'srem',
        'spop',
        'scard',
        'sismember',
        'smembers',
        'srandmember',
        'zadd',
        'zrem',
        'zrange',
        'zrevrange',
        'zrangebyscore',
        'zcard',
        'zscore',
        'zremrangebyscore',
        'sort',
        'rename',
        'rpoplpush'
    );
    // sinterstore
    // sunion
    // sunionstore
    // sdiff
    // sdiffstore
    // sinter
    // smove
    // mget
    // msetnx
    // mset
    // renamenx

    /** @var Credis_Client */
    private $driver;

    /**
     * Set Redis namespace (prefix) default: resque
     *
     * @param string $namespace
     */
    public static function prefix($namespace)
    {
        if (strpos($namespace, ':') === false) {
            $namespace .= ':';
        }
        self::$defaultNamespace = $namespace;
    }

    /**
     * @param string|array $server   A DSN or array
     * @param int          $database A database number to select. However, if we find a valid database number in the DSN the
     *                               DSN-supplied value will be used instead and this parameter is ignored.
     */
    public function __construct($server, $database = null)
    {
        if (is_array($server)) {
            $this->driver = new Credis_Cluster($server);
        } elseif (is_string($server)) {

            list($host, $port, $dsnDatabase, $user, $password, $options) = self::parseDsn($server);
            // $user is not used, only $password

            // Look for known Credis_Client options
            $timeout    = isset($options['timeout']) ? intval($options['timeout']) : null;
            $persistent = isset($options['persistent']) ? $options['persistent'] : '';

            $this->driver = new Credis_Client($host, $port, $timeout, $persistent);
            if ($password) {
                $this->driver->auth($password);
            }

            // If we have found a database in our DSN, use it instead of the `$database`
            // value passed into the constructor.
            if ($dsnDatabase !== false) {
                $database = $dsnDatabase;
            }
        } else {
            $this->driver = $server;
        }

        if ($database !== null) {
            $this->driver->select($database);
        }
    }

    /**
     * Parse a DSN string, which can have one of the following formats:
     * - host:port
     * - redis://user:pass@host:port/db?option1=val1&option2=val2
     * - tcp://user:pass@host:port/db?option1=val1&option2=val2
     * Note: the 'user' part of the DSN is not used.
     *
     * @param string $dsn A DSN string
     * @return array An array of DSN components, with 'false' values for any unknown components. e.g.
     *                    [host, port, db, user, pass, options]
     */
    public static function parseDsn($dsn)
    {
        if ($dsn == '') {
            // Use a sensible default for an empty DNS string
            $dsn = 'redis://' . self::DEFAULT_HOST;
        }
        $parts = parse_url($dsn);

        // Check the URI scheme
        $validSchemes = array('redis', 'tcp');
        if (isset($parts['scheme']) && !in_array($parts['scheme'], $validSchemes)) {
            throw new \InvalidArgumentException("Invalid DSN. Supported schemes are " . implode(', ', $validSchemes));
        }

        // Allow simple 'hostname' format, which `parse_url` treats as a path, not host.
        if (!isset($parts['host']) && isset($parts['path'])) {
            $parts['host'] = $parts['path'];
            unset($parts['path']);
        }

        // Extract the port number as an integer
        $port = isset($parts['port']) ? intval($parts['port']) : self::DEFAULT_PORT;

        // Get the database from the 'path' part of the URI
        $database = false;
        if (isset($parts['path'])) {
            // Strip non-digit chars from path
            $database = intval(preg_replace('/[^0-9]/', '', $parts['path']));
        }

        // Extract any 'user' and 'pass' values
        $user = isset($parts['user']) ? $parts['user'] : false;
        $pass = isset($parts['pass']) ? $parts['pass'] : false;

        // Convert the query string into an associative array
        $options = array();
        if (isset($parts['query'])) {
            // Parse the query string into an array
            parse_str($parts['query'], $options);
        }

        return array(
            $parts['host'],
            $port,
            $database,
            $user,
            $pass,
            $options,
        );
    }

    /**
     * Magic method to handle all function requests and prefix key based
     * operations with the {self::$defaultNamespace} key prefix.
     *
     * @param string $name The name of the method called.
     * @param array  $args Array of supplied arguments to the method.
     * @return mixed Return value from Resident::call() based on the command.
     */
    public function __call($name, $args)
    {
        if (in_array($name, $this->keyCommands)) {
            if (is_array($args[0])) {
                foreach ($args[0] AS $i => $v) {
                    $args[0][$i] = self::$defaultNamespace . $v;
                }
            } else {
                $args[0] = self::$defaultNamespace . $args[0];
            }
        }
        try {
            // This is slow, but necessary to allow custom Redis wrappers
            return call_user_func_array([$this->driver, $name], $args);
        } catch (CredisException $e) {
            return false;
        } catch (\RedisException $e) {
            return false;
        }
    }

    public static function getPrefix()
    {
        return self::$defaultNamespace;
    }

    protected static function removePrefix($string)
    {
        $prefix = self::getPrefix();
        if (strpos($string, $prefix) === 0) {
            $string = substr($string, strlen($prefix));
        }
        return $string;
    }
}
