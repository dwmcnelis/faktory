<?php

namespace Faktory;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

use Faktory\Exception\BadResponseException;
use Faktory\Exception\CommandException;
use Faktory\Exception\NotConnectedException;
use Faktory\Exception\ParseException;
use Faktory\Exception\UnsupportedProtocolException;

class Client implements LoggerAwareInterface
{
	/**
	 * @var array
	 */
	private $params;

	/**
	 * @var resource
	 */
	private $stream;

	 /**
	 * @var LoggerInterface
	 */
	private $logger;

	public function __construct(array $params, LoggerInterface $logger, array $options = array())
	{
		$this->params = array_merge($params, $options);
		$this->stream = null;
		$this->logger = $logger;
		$this->open();
	}

	public function __destruct()
	{
		$this->close();
	}

	/**
	 * Use TLS connection?.
	 *
	 * @return boolean $use
	 */
	public function tls($url)
	{
		// Support TLS with this convention: "tcp+tls://:password@myhostname:port/"
		return preg_match('/tls/', $url);
	}

	/**
	 * Opens the connection to Faktory.
	 */
	public function open()
	{
		$this->log('open','debug');
		if (array_key_exists('url', $this->params)) {
			$url = $this->params['url'];
		} else {
			$scheme = array_key_exists('scheme', $this->params) ? $this->params['scheme'] : 'tcp';
			$host = array_key_exists('host', $this->params) ? $this->params['host'] : '127.0.0.1';
			$port = array_key_exists('port', $this->params) ? $this->params['port'] : 7419;
			$url = $scheme. '://' . $host . ':' . $port;
		}
		if ($this->tls($url)) {
		} else {
			$stream = stream_socket_client($url, $errno, $errstr);
		}
		if (!$stream) {
			throw new NotConnectedException;
		}
		$this->stream = $stream;

		$resp = $this->result();
		$this->log('hello: from server: '.$resp, 'debug');
		if (!preg_match('/\AHI (.*)/', $resp)) {
			throw new BadResponseException('expected HI, got: '.$resp);
		}
		$server = json_decode(trim(substr($resp, 3)), true);
		$this->log('server: '.json_encode($server), 'debug');

		$version = array_key_exists('v', $server) ? $server['v'] : -1;
		$salt = array_key_exists('s', $server) ? $server['s'] : '';
		$iter = array_key_exists('i', $server) ? $server['i'] : 0;
		$this->log('version: '.$version, 'debug');
		$this->log('salt: '.$salt, 'debug');
		$this->log('iters: '.$iter, 'debug');

		if ($version !== 2) {
			throw new UnsupportedProtocolException('version: '.$version);
		}

		$payload = array(
			'hostname' => gethostname(),
			'pid' => getmypid(),
			'labels' => array('php'),
			'v' => $version
		);

		if (!empty($salt) || !empty($iter)) {
			$password = array_key_exists('password', $this->params) ? $this->params['password'] : null;
			if (empty($password)) {
				throw new NotConnectedException('password required');
			}
			$payload['pwdhash'] = $this->hasher($iter, $password, $salt);
		}

		$wid = array_key_exists('wid', $this->params) ? $this->params['wid'] : null;
		if (!empty($wid)) {
			$payload['wid'] = $wid;
		}

		$this->command('HELLO', json_encode($payload));
		$this->ok();

	}

	/**
	 * Closes the connection to Faktory.
	 */
	public function close()
	{
		$this->log('close','debug');
		if (is_null($this->stream)) {
			return;
		}
		$this->command('END');
		fclose($this->stream);
		$this->stream = null;
	}

	/**
	 * Calculate password hash.
	 *
	 * @param int $iter
	 * @param int $password
	 * @param int $salt
	 * @return string hash
	 */
	private function hasher($iter, $password, $salt)
	{
		$data = $password . $salt;
		for ($i=0; $i < $iter; $i++) {
			$data = hash('sha256', $data, true);
		}
		return bin2hex($data);
	}

	/**
	 * Writes a comand string to Faktory server.
	 *
	 * @param strings $args Command and args.
	 *
	 * @return
	 */
	public function command(...$args)
	{
		$cmd = join(' ', $args);
		$n = fwrite($this->stream, $cmd . "\r\n", strlen($cmd)+2);
		$this->log('command: '.$cmd.' ('.$n.')', 'debug');
		return true;
	}

	/**
	 * Read result back from Faktory server.
	 *
	 * I love pragmatic, simple protocols.  Thanks antirez!
	 * https://redis.io/topics/protocol
	 *
	 * @return string $result
	 */
	public function result(int $length = 1024)
	{
		$line = fgets($this->stream, $length);
		$this->log('line: '.$line.' ('.(empty($line) ? '': strlen($line)).')', 'debug');
		if (empty($line)) {
			throw new BadResponseException;
		}

		$chr = $line[0];
		if ($chr === '+') {
			$result = trim(substr($line, 1, strpos($line, "\r\n")));
			$this->log('result: '.$result, 'debug');
			return $result;
		} elseif ($chr === '$') {
			$count = (int)trim(substr($line, 1, strpos($line, "\r\n")));
			if ($count === -1) {
				$this->log('result: <NULL>', 'debug');
				return null;
			} elseif ($count > 0) {
				$result = fgets($this->stream, $count);
				$waste = fgets($this->stream, $length); // read extra linefeeds
				$this->log('result: '.$result, 'debug');
				return $result;
			} else {
				$this->log('result: <NULL>', 'debug');
				return null;
			}
		} elseif ($chr === '-') {
			throw new CommandException;
		} else {
			$result = trim(substr($line, 1, strpos($line, "\r\n")));
			throw new ParseException($result);
		}

	}

	/**
	 * Read result back from Faktory server and assert it is OK.
	 *
	 * @return boolean $ok
	 */
	public function ok()
	{
		$resp = $this->result();
		if ($resp !== 'OK') {
			throw new CommandException;
		}
		return true;
	}

	/**
	 * Attempt resilent transaction (re-open connection if lost)
	 *
	 * @param callable $callback
	 * @return mixed $return
	 */
	public function transaction(callable $callback)
	{
		$return = null;
		$retryable = true;

    while ($retryable) {
			try {
				$return = $callback();
		    $retryable = false;
			} catch (Exception $e) {
				$this->logger->alert('Transaction failure {exception}', array(
					'exception' => $e
				));
				if ($retryable) {
					$this->logger->alert('Retrying...');
					$this->open();
				}
			}
		}
		return $return;
	}

	/**
	 * Warning: this clears all job data in Faktory.
	 */
	public function flush()
	{
		$self = $this;
		return $this->transaction(function () use (&$self) {
			$self->command('FLUSH');
			$self->ok();
		});
	}

	/**
	 * Push a job to Faktory.
	 *
	 * @param Job $job
	 * @return string $jid
	 */
	public function push(Job $job) : string
	{
		$self = $this;
		return $this->transaction(function () use (&$self, $job) {
			$self->command('PUSH', json_encode($job));
			$self->ok();
			return $job->getId();
		});
	}

	/**
	 * Fetch a job from Faktory.
	 *
	 * @param array $queues
	 * @return array $job
	 */
	public function fetch(array $queues)
	{
		$self = $this;
		return $this->transaction(function () use (&$self, $queues) {
			$self->command('FETCH', implode(' ', $queues));
			$resp = $this->result();
			return empty($resp) ? null : json_decode($resp);
		});
	}

	/**
	 * Acknowledge job as completed in Faktory.
	 *
	 * @param string $jid Job ID
	 */
	public function ack(string $jid) : void
	{
		$self = $this;
		$this->transaction(function () use (&$self, $jid) {
			$self->command('ACK', '{"jid":"'.$jid.'"}');
			$self->ok();
		});
	}

	/**
	 * Mark job as failed in Faktory.
	 *
	 * @param string $jid Job ID
	 * @param Exception $ex
	 */
	public function fail(string $jid, \Exception $ex) : void
	{
		$self = $this;
		$this->transaction(function () use (&$self, $jid, $ex) {
			$payload = array(
				'jid' => $jid,
				'message' => substr($ex->getMessage(), 0, 1000),
				'backtrace' => $ex->getTrace()
			);
			$self->command('FAIL', json_encode($payload));
			$self->ok();
		});
	}


	/**
	 * Send heart beat to Faktory.
	 *
	 * @param string $wid Worker ID
	 * @return array $state
	 */
	public function beat(string $wid)
	{
		$self = $this;
		return $this->transaction(function () use (&$self, $wid) {
			$self->command('BEAT', '{"wid":"'.$wid.'"}');
			$resp = $this->result();
			if ($resp !== "OK") {
				$self->log('beat: '.$resp, 'debug');
				return $resp;
			} else {
				$hash = json_decode($resp, true);
				$self->log('beat: state: '.$hash['state'], 'debug');
				return $hash['state'];
			}
		});
	}

	/**
	 * Get info from Faktory
	 *
	 * @return array $info
	 */
	public function info()
	{
		$self = $this;
		return $this->transaction(function () use (&$self) {
			$self->command('INFO');
			$resp = $this->result();
			$self->log('info: '.$resp, 'debug');
			return !empty($resp) ? json_decode($resp, true) : null;
		});
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

}
