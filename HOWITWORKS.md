*For an overview of how to __use__ php-resque, see `README.md`.*

The following is a step-by-step breakdown of how php-resque operates.

## Enqueue Job ##

What happens when you call `Resque\Resque::enqueue()`?

1. `Resque\Resque::enqueue()` calls `Resque\Job::create()` with the same arguments it
   received.
2. `Resque\Job::create()` checks that your `$args` (the third argument) are
   either `null` or in an array
3. `Resque\Job::create()` generates a job ID (a "token" in most of the docs)
4. `Resque\Job::create()` pushes the job to the requested queue (first
   argument)
5. `Resque\Job::create()`, if status monitoring is enabled for the job (fourth
   argument), calls `Resque\Job\Status::create()` with the job ID as its only
   argument
6. `Resque\Job\Status::create()` creates a key in Redis with the job ID in its
   name, and the current status (as well as a couple of timestamps) as its
   value, then returns control to `Resque\Job::create()`
7. `Resque\Job::create()` returns control to `Resque\Resque::enqueue()`, with the job
   ID as a return value
8. `Resque\Resque::enqueue()` triggers the `afterEnqueue` event, then returns control
   to your application, again with the job ID as its return value

## Workers At Work ##

How do the workers process the queues?

1. `Resque\Worker::work()`, the main loop of the worker process, calls
   `Resque\Worker->reserve()` to check for a job
2. `Resque\Worker->reserve()` checks whether to use blocking pops or not (from
   `BLOCKING`), then acts accordingly:
  * Blocking Pop
    1. `Resque\Worker->reserve()` calls `Resque\Job::reserveBlocking()` with
       the entire queue list and the timeout (from `INTERVAL`) as arguments
    2. `Resque\Job::reserveBlocking()` calls `Resque\Resque::blpop()` (which in turn
       calls Redis' `blpop`, after prepping the queue list for the call, then
       processes the response for consistency with other aspects of the
       library, before finally returning control [and the queue/content of the
       retrieved job, if any] to `Resque\Job::reserveBlocking()`)
    3. `Resque\Job::reserveBlocking()` checks whether the job content is an
       array (it should contain the job's type [class], payload [args], and
       ID), and aborts processing if not
    4. `Resque\Job::reserveBlocking()` creates a new `Resque\Job` object with
       the queue and content as constructor arguments to initialize the job
       itself, and returns it, along with control of the process, to
       `Resque\Worker->reserve()`
  * Queue Polling
    1. `Resque\Worker->reserve()` iterates through the queue list, calling
       `Resque\Job::reserve()` with the current queue's name as the sole
       argument on each pass
    2. `Resque\Job::reserve()` passes the queue name on to `Resque\Resque::pop()`,
       which in turn calls Redis' `lpop` with the same argument, then returns
       control (and the job content, if any) to `Resque\Job::reserve()`
    3. `Resque\Job::reserve()` checks whether the job content is an array (as
       before, it should contain the job's type [class], payload [args], and
       ID), and aborts processing if not
    4. `Resque\Job::reserve()` creates a new `Resque\Job` object in the same
       manner as above, and also returns this object (along with control of
       the process) to `Resque\Worker->reserve()`
3. In either case, `Resque\Worker->reserve()` returns the new `Resque\Job`
   object, along with control, up to `Resque\Worker::work()`; if no job is
   found, it simply returns `FALSE`
  * No Jobs
    1. If blocking mode is not enabled, `Resque\Worker::work()` sleeps for
       `INTERVAL` seconds; it calls `usleep()` for this, so fractional seconds
       *are* supported
  * Job Reserved
    1. `Resque\Worker::work()` triggers a `beforeFork` event
    2. `Resque\Worker::work()` calls `Resque\Worker->workingOn()` with the new
       `Resque\Job` object as its argument
    3. `Resque\Worker->workingOn()` does some reference assignments to help keep
       track of the worker/job relationship, then updates the job status from
       `WAITING` to `RUNNING`
    4. `Resque\Worker->workingOn()` stores the new `Resque\Job` object's payload
       in a Redis key associated to the worker itself (this is to prevent the job
       from being lost indefinitely, but does rely on that PID never being
       allocated on that host to a different worker process), then returns control
       to `Resque\Worker::work()`
    5. `Resque\Worker::work()` forks a child process to run the actual `perform()`
    6. The next steps differ between the worker and the child, now running in
       separate processes:
      * Worker
        1. The worker waits for the job process to complete
        2. If the exit status is not 0, the worker calls `Resque\Job->fail()` with
           a `Resque\Job\DirtyExitException` as its only argument.
        3. `Resque\Job->fail()` triggers an `onFailure` event
        4. `Resque\Job->fail()` updates the job status from `RUNNING` to `FAILED`
        5. `Resque\Job->fail()` calls `Resque\Failure::create()` with the job
           payload, the `Resque\Job\DirtyExitException`, the internal ID of the
           worker, and the queue name as arguments
        6. `Resque\Failure::create()` creates a new object of whatever type has
           been set as the `Resque\Failure` "backend" handler; by default, this is
           a `Resque\Resque\Failure\Redis` object, whose constructor simply collects the
           data passed into `Resque\Failure::create()` and pushes it into Redis
           in the `failed` queue
        7. `Resque\Job->fail()` increments two failure counters in Redis: one for
           a total count, and one for the worker
        8. `Resque\Job->fail()` returns control to the worker (still in
           `Resque\Worker::work()`) without a value
      * Job
        1. The job calls `Resque\Worker->perform()` with the `Resque\Job` as its
           only argument.
        2. `Resque\Worker->perform()` sets up a `try...catch` block so it can
           properly handle exceptions by marking jobs as failed (by calling
           `Resque\Job->fail()`, as above)
        3. Inside the `try...catch`, `Resque\Worker->perform()` triggers an
           `afterFork` event
        4. Still inside the `try...catch`, `Resque\Worker->perform()` calls
           `Resque\Job->perform()` with no arguments
        5. `Resque\Job->perform()` calls `Resque\Job->getInstance()` with no
           arguments
        6. If `Resque\Job->getInstance()` has already been called, it returns the
           existing instance; otherwise:
        7. `Resque\Job->getInstance()` checks that the job's class (type) exists
           and has a `perform()` method; if not, in either case, it throws an
           exception which will be caught by `Resque\Worker->perform()`
        8. `Resque\Job->getInstance()` creates an instance of the job's class, and
           initializes it with a reference to the `Resque\Job` itself, the job's
           arguments (which it gets by calling `Resque\Job->getArguments()`, which
           in turn simply returns the value of `args[0]`, or an empty array if no
           arguments were passed), and the queue name
        9. `Resque\Job->getInstance()` returns control, along with the job class
           instance, to `Resque\Job->perform()`
        10. `Resque\Job->perform()` sets up its own `try...catch` block to handle
            `Resque\Job\DontPerform` exceptions; any other exceptions are passed
            up to `Resque\Worker->perform()`
        11. `Resque\Job->perform()` triggers a `beforePerform` event
        12. `Resque\Job->perform()` calls `setUp()` on the instance, if it exists
        13. `Resque\Job->perform()` calls `perform()` on the instance
        14. `Resque\Job->perform()` calls `tearDown()` on the instance, if it
            exists
        15. `Resque\Job->perform()` triggers an `afterPerform` event
        16. The `try...catch` block ends, suppressing `Resque\Job\DontPerform`
            exceptions by returning control, and the value `FALSE`, to
            `Resque\Worker->perform()`; any other situation returns the value
            `TRUE` along with control, instead
        17. The `try...catch` block in `Resque\Worker->perform()` ends
        18. `Resque\Worker->perform()` updates the job status from `RUNNING` to
            `COMPLETE`, then returns control, with no value, to the worker (again
            still in `Resque\Worker::work()`)
        19. `Resque\Worker::work()` calls `exit(0)` to terminate the job process
            cleanly
      * SPECIAL CASE: Non-forking OS (Windows)
        1. Same as the job above, except it doesn't call `exit(0)` when done
    7. `Resque\Worker::work()` calls `Resque\Worker->doneWorking()` with no
       arguments
    8. `Resque\Worker->doneWorking()` increments two processed counters in Redis:
       one for a total count, and one for the worker
    9. `Resque\Worker->doneWorking()` deletes the Redis key set in
       `Resque\Worker->workingOn()`, then returns control, with no value, to
       `Resque\Worker::work()`
4. `Resque\Worker::work()` returns control to the beginning of the main loop,
   where it will wait for the next job to become available, and start this
   process all over again