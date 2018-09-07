<?php
declare(strict_types=1);

namespace Faktory;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

class Worker implements LoggerAwareInterface
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    private $queues = [];

    /**
     * @var array
     */
    private $jobTypes = [];

    /**
     * @var bool
     */
    private $stop = false;

    public function __construct(Client $client, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function setQueues(array $queues) : void
    {
        $this->queues = $queues;
    }

    public function register(string $jobType, callable $callable) : void
    {
        $this->jobTypes[$jobType] = $callable;
    }

	/**
	 * Logs a message
	 *
	 * @param string $message
	 * @param string $priority
	 * @return void
	 */
	public function log($message, $priority = LogLevel::INFO)
	{
		$this->logger->log($priority, $message);
	}

	/**
	 * @param LoggerInterface $logger
	 * @return void
	 */
	public function setLogger(LoggerInterface $logger)
	{
		$this->logger = $logger;
	}

	/**
	 * @return LoggerInterface
	 */
	public function getLogger()
	{
		return $this->logger;
	}

    public function run(bool $daemonize = false) : void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function ($signo) {
            exit(0);
        });

        pcntl_signal(SIGINT, function ($signo) {
            exit(0);
        });

        do {
            $job = $this->client->fetch($this->queues);

            if ($job !== null) {
                $this->logger->debug($job['jid']);

                $callable = $this->jobTypes[$job['jobtype']];

                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \Exception('Could not fork');
                }

                if ($pid > 0) {
                    pcntl_wait($status);
                } else {
                    try {
                        call_user_func($callable, $job);
                        $this->client->ack($job['jid']);
                    } catch (\Exception $e) {
                        $this->client->fail($job['jid']);
                    } finally {
                        exit(0);
                    }
                }
            }
            usleep(100);
        } while($daemonize && !$this->stop);
    }
}
