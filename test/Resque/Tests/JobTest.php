<?php
require_once dirname(__FILE__) . '/bootstrap.php';

/**
 * Resque_Job tests.
 *
 * @package		Resque/Tests
 * @author		Chris Boulton <chris.boulton@interspire.com>
 * @copyright	(c) 2010 Chris Boulton
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Resque_Tests_JobTest extends Resque_Tests_TestCase
{
	protected $worker;

	public function setUp()
	{
		parent::setUp();

		// Register a worker to test with
		$this->worker = new Resque_Worker('jobs');
		$this->worker->registerWorker();
	}

	public function tearDown()
	{
		$this->worker->unregisterWorker();
	}

	public function testJobCanBeQueued()
	{
		$this->assertTrue((bool)Resque::enqueue('jobs', 'Test_Job'));
	}

	public function testQeueuedJobCanBeReserved()
	{
		Resque::enqueue('jobs', 'Test_Job');

		$job = Resque_Job::reserve('jobs');
		if($job == false) {
			$this->fail('Job could not be reserved.');
		}
		$this->assertEquals('jobs', $job->queue);
		$this->assertEquals('Test_Job', $job->payload->class);
	}

	/**
	 * @expectedException InvalidArgumentException
	 */
	public function testArrayArgumentsCannotBePassedToJob()
	{
		Resque::enqueue('jobs', 'Test_Job', array(
			'test'
		));
	}

	public function testQueuedJobReturnsExactSamePassedInArguments()
	{
		$args = new stdClass;
		$args->int = 123;
		$args->numArray = array(
			1,
			2,
		);
		$args->assocArray = new stdClass;
		$args->assocArray->key1 = 'value1';
		$args->assocArray->key2 = 'value2';

		Resque::enqueue('jobs', 'Test_Job', $args);
		$job = Resque_Job::reserve('jobs');

		$this->assertEquals($args, $job->payload->args);
	}

	public function testAfterJobIsReservedItIsRemoved()
	{
		Resque::enqueue('jobs', 'Test_Job');
		Resque_Job::reserve('jobs');
		$this->assertFalse(Resque_Job::reserve('jobs'));
	}

	public function testRecreatedJobMatchesExistingJob()
	{
		$args = new stdClass;
		$args->int = 123;
		$args->numArray = array(
			1,
			2,
		);
		$args->assocArray = new stdClass;
		$args->assocArray->key1 = 'value1';
		$args->assocArray->key2 = 'value2';

		Resque::enqueue('jobs', 'Test_Job', $args);
		$job = Resque_Job::reserve('jobs');

		// Now recreate it
		$job->recreate();

		$newJob = Resque_Job::reserve('jobs');
		$this->assertEquals($job->payload->class, $newJob->payload->class);
		$this->assertEquals($job->payload->args, $newJob->payload->args);
	}

	public function testFailedJobExceptionsAreCaught()
	{
		$payload = new stdClass;
		$payload->class = 'Failing_Job';
		$payload->args = null;
		$job = new Resque_Job('jobs', $payload);
		$job->worker = $this->worker;

		$this->worker->perform($job);

		$this->assertEquals(1, Resque_Stat::get('failed'));
		$this->assertEquals(1, Resque_Stat::get('failed:'.$this->worker));
	}

	/**
	 * @expectedException Resque_Exception
	 */
	public function testJobWithoutPerformMethodThrowsException()
	{
		Resque::enqueue('jobs', 'Test_Job_Without_Perform_Method');
		$job = $this->worker->reserve();
		$job->worker = $this->worker;
		$job->perform();
	}

	/**
	 * @expectedException Resque_Exception
	 */
	public function testInvalidJobThrowsException()
	{
		Resque::enqueue('jobs', 'Invalid_Job');
		$job = $this->worker->reserve();
		$job->worker = $this->worker;
		$job->perform();
	}
}