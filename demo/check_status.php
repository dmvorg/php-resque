<?php
use Resque\Job\Status;
use Resque\Resque;

if(empty($argv[1])) {
	die('Specify the ID of a job to monitor the status of.');
}

require __DIR__ . '/init.php';

date_default_timezone_set('GMT');
Resque::setBackend('127.0.0.1:6379');
// You can also use a DSN-style format:
//Resque\Resque::setBackend('redis://user:pass@127.0.0.1:6379');
//Resque\Resque::setBackend('redis://user:pass@a.host.name:3432/2');

$status = new Status($argv[1]);
if(!$status->isTracking()) {
	die("Resque is not tracking the status of this job.\n");
}

echo "Tracking status of ".$argv[1].". Press [break] to stop.\n\n";
while(true) {
	fwrite(STDOUT, "Status of ".$argv[1]." is: ".$status->get()."\n");
	sleep(1);
}