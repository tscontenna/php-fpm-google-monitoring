<?php

/**
 * Monitor script for php-fpm on GCE.
 *  Inspired by the munin plugin, published at https://gallery.munin-monitoring.org/plugins/munin-contrib/php_fpm_process/
 *
 *  Usage: 
 *  1. Make a copy config-example.php as config.php and edit it to point the correct location of php-fpm status URL.
 *	2. Run with credential json file as an env like:
 *     >  GOOGLE_APPLICATION_CREDENTIALS=/path/to/my-google-service.json  php ./main.php
 *  3. Check the Monitoring section on Google Cloud Console. php_fpm entries can be found at `Metric Explorer` > `Global` > `Custom Metrics`.
 *  4. Register it as a cron or systemd-timer script, to run every 5 minutes.
 */

$stderr = fopen("php://stderr","w+");

$config = require(__DIR__."/config.php");

require (__DIR__.'/vendor/autoload.php');

// Read parameters from env GOOGLE_APPLICATION_CREDENTIALS
$credFilePath = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?? '';
if (!$credFilePath) {
	fwrite($stderr, "*** Error: env GOOGLE_APPLICATION_CREDENTIALS is not set\n");
	exit(-1);
}

$credFilePath = @realpath($credFilePath);
if( !is_file($credFilePath) || !is_readable($credFilePath)) {
	fwrite($stderr, "*** Error: cannot read file '$credFilePath' \n");
	exit(-1);
}

$isToDelete = in_array("--delete", $_SERVER['argv']);
$isDebug    = in_array("--debug", $_SERVER['argv']);

$credInfo = json_decode(file_get_contents($credFilePath));

use Google\Api\LabelDescriptor;
use Google\Api\Metric;
use Google\Api\MetricDescriptor;
use Google\Api\MonitoredResource;
use Google\Cloud\Monitoring\V3\MetricServiceClient;
use Google\Cloud\Monitoring\V3\Point as GCM_Point;
use Google\Cloud\Monitoring\V3\TimeInterval as GCM_TimeInterval;
use Google\Cloud\Monitoring\V3\TimeSeries as GCM_TimeSeries;
use Google\Cloud\Monitoring\V3\TypedValue as GCM_TypedValue;
use Google\Protobuf\Timestamp as Pb_Timestamp;

$projectId = $credInfo->project_id;

$fpmStatus = new FpmStatus($config['pool']['status_url']);
$fpmStatus->fetch();
if ($isDebug) print_r($fpmStatus);

$reporter = new FpmReporter($projectId, $fpmStatus);
if ($isToDelete) {
	$reporter->deleteMetricDescriptor();
} else {
	$reporter->createMetricDescriptor();
	$reporter->writeSeriesData();
}


class FpmReporter
{
	private $fpmStatus;
	private $projectId;

	const METRIC_TYPES =[
		'processes' => 'custom.googleapis.com/php_fpm/processes',
		'queues'    => 'custom.googleapis.com/php_fpm/queues',
		'requests'  => 'custom.googleapis.com/php_fpm/requests',
	];

	public function __construct(string $projectId, FpmStatus $fpmStatus)
	{
		$this->projectId = $projectId;
		$this->fpmStatus = $fpmStatus;
	}

	public function createMetricDescriptor()
	{
	    $metrics = new MetricServiceClient([
			'projectId' => $this->projectId,
		]);
		$projectName = $metrics->projectName($this->projectId);
		
		$results = [];

		// This graph shows the php-fpm process manager status from pool $pool
		//  active : Active processes.  The number of active processes
		//  idle  :  Idle processes.  The number of idle processes
		//  total : Total processes.  The number of idle + active processes
		//  max: Max processes.  The maximum number of active processes since FPM has started
		$descriptor = new MetricDescriptor([
				'description' => "php-fpm processes for {$this->fpmStatus->pool}.",
				'display_name' => 'php fpm processes',
				'type'  => self::METRIC_TYPES['processes'],
				'metric_kind' => MetricDescriptor\MetricKind::GAUGE,
				'value_type' => MetricDescriptor\ValueType::INT64,
				'unit' => '{Procs}',
		]);

		$label = new LabelDescriptor([
				"key" => 'value_type',
				"value_type" => LabelDescriptor\ValueType::STRING,
				"description" => 'The type of value.',
		]);

		$labels = [$label];
		$descriptor->setLabels($labels);

		$results[] = $metrics->createMetricDescriptor($projectName, $descriptor);


		// This graph shows the php-fpm queue from pool $pool
		//  listen: Listen queue.  The number of pending requests in the queue
		//  max:    Max listen queue.    The maximum number of pending requests in the queue
		//  len:    Queue len.    The number of pending connections in the queue
		$descriptor = new MetricDescriptor([
				'description' => "php-fpm queues for {$this->fpmStatus->pool}.",
				'display_name' => 'php fpm queues',
				'type'  => self::METRIC_TYPES['queues'],
				'metric_kind' => MetricDescriptor\MetricKind::GAUGE,
				'value_type' => MetricDescriptor\ValueType::INT64,
				'unit' => '{Procs}',
		]);

		$label = new LabelDescriptor([
				"key" => 'value_type',
				"value_type" => LabelDescriptor\ValueType::STRING,
				"description" => 'The type of value.',
		]);

		$labels = [$label];
		$descriptor->setLabels($labels);

		$results[] = $metrics->createMetricDescriptor($projectName, $descriptor);


		// This graph shows the php-fpm request rate from pool $pool
		//  connections: Connections.  info evolution of connections
		//  slow: Slow requests.  evolution of slow requests (longer than request_slowlog_timeout)
		$descriptor = new MetricDescriptor([
				'description' => "php-fpm requests for {$this->fpmStatus->pool}.",
				'display_name' => 'php fpm requests',
				'type'  => self::METRIC_TYPES['requests'],
				'metric_kind' => MetricDescriptor\MetricKind::CUMULATIVE,
				'value_type' => MetricDescriptor\ValueType::INT64,
				'unit' => '{Procs}',
		]);

		$label = new LabelDescriptor([
				"key" => 'value_type',
				"value_type" => LabelDescriptor\ValueType::STRING,
				"description" => 'The type of value.',
		]);

		$labels = [$label];
		$descriptor->setLabels($labels);

		$results[] = $metrics->createMetricDescriptor($projectName, $descriptor);
		return $results;
	}


	public function deleteMetricDescriptor()
	{
		$metrics = new MetricServiceClient([
				'projectId' => $this->projectId,
		]);

		$results = [];
		foreach (self::METRIC_TYPES as $metricGrp => $metricId) {
			$metricPath = $metrics->metricDescriptorName($this->projectId, $metricId);
			$results[] = $metrics->deleteMetricDescriptor($metricPath);
		}
		return $results;
	}


	public function writeSeriesData()
	{
		$metrics = new MetricServiceClient(['projectId' => $this->projectId]);

		$projectName = $metrics->projectName($this->projectId);

		$results = [];
		foreach (self::METRIC_TYPES as $metricGrp => $metricId) {
			$endTime  = new Pb_Timestamp(['seconds' =>time()]);
			$interval = new GCM_TimeInterval([
				'end_time' => $endTime,
			]);

			if ($metricGrp == "processes") {
				$values = [
					'idle' => $this->fpmStatus->idle_processes,
					'active' => $this->fpmStatus->active_processes,
					'total' => $this->fpmStatus->total_processes,
					'max' => $this->fpmStatus->max_active_processes,
				];
			} else if ($metricGrp == 'queues') {
				if(!isset($this->fpmStatus->listen_queue)) continue;

				$values = [
					'listen' => $this->fpmStatus->listen_queue,
					'max' => $this->fpmStatus->max_listen_queue,
					'len' => $this->fpmStatus->listen_queue_len,
				];
			} else if ($metricGrp == 'requests') {
				$startTime  = new Pb_Timestamp([
					'seconds' => strtotime($this->fpmStatus->start_time),
				]);
				$interval->setStartTime($startTime);
				$values = [
					'connections' => $this->fpmStatus->accepted_conn,
					'slow' => $this->fpmStatus->slow_requests,
				];
			} else {
				throw new \LogicException("Unknown metric id");
			}

			$timeSeriesArr = [];
			foreach ($values as $value_type => $value) {
				$value = new GCM_TypedValue(['int64_value' => $value]);

				$point = new GCM_Point(['value' => $value, 'interval' => $interval]);
				$points = [$point];

				$metric = new Metric(['type' => self::METRIC_TYPES[$metricGrp]]);
				$labels = ['value_type' => $value_type];
				$metric->setLabels($labels);

				$resource = new MonitoredResource(["type" => 'global']);
				$labels = ['project_id' => $this->projectId];
				$resource->setLabels($labels);

				$timeSeries = new GCM_TimeSeries([
						'metric' => $metric,
						'resource' => $resource,
						'points' => $points,
				]);
				$timeSeriesArr[] = $timeSeries;
			}

			$result = $metrics->createTimeSeries($projectName, $timeSeriesArr);
			$results[] = $result;
		}
		return $results;
	}
}


class FpmStatus
{
	private $statusUrl;
	private $data = [];

	/**
	 *  @param string $statusUrl The URL string like  http://localhost/php-fpm-status
	 */
	public function __construct(string $statusUrl)
	{
		// TODO validate
		$this->statusUrl = $statusUrl;
	}

	public function fetch()
	{
		$content = file_get_contents($this->statusUrl);
		if (!$content) throw new \RuntimeException("Could not retrive fpm status");
		// var_dump($content);
		$this->parse($content);
	}

	/*
	 *
	 * Example output:
	 *
	 * pool:                 www
	 * process manager:      dynamic
	 * start time:           23/Jun/2019:12:13:50 +0200
	 * start since:          577793
	 * accepted conn:        37211
	 * listen queue:         0
	 * max listen queue:     0
	 * listen queue len:     0
	 * idle processes:       6
	 * active processes:     1
	 * total processes:      7
	 * max active processes: 13
	 * max children reached: 0
	 * slow requests:        0
	 */
	private function parse($content)
	{
		$content = str_replace(["\r\n","\r"],"\n", $content);
		$lines = explode("\n", $content);
		$entries = [];
		foreach ($lines as $line) {
			$matches = null;
			if (!preg_match('/^(\w[\w\s]*\w)\s*:\s*(.+)\s*$/', $line, $matches)) {
				continue;
			}
			list(,$key,$value) = $matches;
			$key = preg_replace('/\s+/','_', $key);
			$entries[$key] = $value;
		}
		$this->data = $entries;
	}

	public function __get(string $key)
	{
		return $this->data[$key] ?? null;
	}

	public function __isset(string $key){
		return isset($this->data[$key]);
	}
}

