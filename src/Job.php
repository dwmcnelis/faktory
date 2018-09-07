<?php

namespace Faktory;

class Job implements \JsonSerializable
{

// "queue": "default" - push this job to a particular queue. The default queue is, unsurprisingly, "default".
// "priority": 5 - priority within the queue, may be 1-9, default is 5. 9 is high priority, 1 is low priority.
// "reserve_for": 600 - set the reservation timeout for a job, in seconds. When a worker fetches a job, it has up to N seconds to ACK or FAIL the job. After N seconds, the job will be requeued for execution by another worker. Default is 1800 seconds or 30 minutes, minimum is 60 seconds.
// "at": "2017-12-20T15:30:17.111222333Z" - schedule a job to run at a point in time. The job will be enqueued within a few seconds of that point in time. Note the string must be in Go's RFC3339Nano time format.
// "retry": 3 - set the number of retries to perform if this job fails. Default is 25 (which, with exponential backoff, means Faktory will retry the job over a 21 day period). A value of 0 means the job will not be retried and will be discarded if it fails. A value of -1 means don't retry but move the job immediately to the Dead set if it fails.
// "backtrace": 10 - retain up to N lines of backtrace given to the FAIL command. Default is 0. Faktory is not designed to be a full-blown error service, best practice is to integrate your workers with an existing error service, but you can enable this to get a better view of why a job is retrying in the Web UI.

// "custom"


  /**
   * @var string
   */
  private $queue;

	/**
	 * @var string
	 */
	private $id;

	/**
	 * @var string
	 */
	private $type;

  /**
   * @var array
   */
  private $args;

  /**
   * @var array
   */
  private $priority;

  /**
   * @var int
   */
  private $reserve_for;

  /**
   * @var array
   */
  private $at;

  /**
   * @var int
   */
  private $retry;

  /**
   * @var int
   */
  private $backtrace;

  /**
   * @var array
   */
  private $custom;

	public function __construct(string $type='', array $args = [])
	{
		$this->id = md5(uniqid('', true));
		$this->type = $type;
    $this->args = $args;
    $this->priority = null;
    $this->reserve_for = null;
    $this->at = null;
    $this->retry = null;
    $this->backtrace = null;
    $this->custom = null;
	}

  public function getId()
  {
    return $this->id;
  }

  public function getType()
  {
    return $this->type;
  }

  public function getArgs()
  {
    return $this->args;
  }

  public function id(string $id)
  {
    return $this->id = $id;
  }

  public function queue(string $queue)
  {
    return $this->queue = $queue;
  }

  public function priority(int $priority)
  {
    return $this->priority = $priority;
  }

  public function reserve(int $for)
  {
    return $this->reserve_for = $for;
  }

  public function at(\DateTime $at)
  {
    return $this->at = $at;
  }

  public function retry(int $retry)
  {
    return $this->retry = $retry;
  }

  public function backtrace(int $lines)
  {
    return $this->backtrace = $lines;
  }

  public function custom(array $hash)
  {
    return $this->custom = $hash;
  }

	public function jsonSerialize() : array
	{
    $data = array();
    if (!empty($this->id)) {
      $data['jid'] = $this->id;
    }
    if (!empty($this->type)) {
      $data['jobtype'] = $this->type;
    }
    if (!empty($this->args)) {
      $data['args'] = array($this->args);
    }
    if (!empty($this->queue)) {
      $data['queue'] = $this->queue;
    }
    if (!empty($this->priority)) {
      $data['priority'] = $this->priority;
    }
    if (!empty($this->reserve_for)) {
      $data['reserve_for'] = $this->reserve_for;
    }
    if (!empty($this->at)) {
      $data['at'] = date_format($this->at,"Y-m-d\TH:i:s.u\Z");
    }
    if (!empty($this->retry)) {
      $data['retry'] = $this->retry;
    }
    if (!empty($this->backtrace)) {
      $data['backtrace'] = $this->backtrace;
    }
    if (!empty($this->custom)) {
      $data['custom'] = $this->custom;
    }
		return $data;
	}
}
