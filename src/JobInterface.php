<?php

namespace Faktory;

use \Exception;

/**
 * JobInterface
 *
 * Implement this to use a custom class hierarchy
 */
interface JobInterface
{
	/**
	 * @param string $queue
	 * @return void
	 */
	public function setQueue($queue);

	/**
	 * @param array $payload
	 * @return void
	 */
	public function setPayload(array $payload);

	/**
	 * @return string
	 */

	public function getQueue();

	/**
	 * @return array
	 */
	public function getPayload();

	/**
	 * @return array
	 */
	public function getArgs();

	/**
	 * Actually performs the work of the job
	 *
	 * @return void
	 */
	public function perform();

}
