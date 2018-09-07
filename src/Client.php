<?php

namespace Faktory;

use Faktory\Exception\NotConnectedException;
use Faktory\Exception\BadResponseException;
use Faktory\Exception\UnsupportedProtocolException;
use Faktory\Exception\WriteFailedException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

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

	public function __construct(array $params, array $options = array())
	{
		$this->params = array_merge($params, $options);
		$this->stream = null;
	}

	public function __destruct()
	{
		if (isset($this->params['persistent']) && $this->params['persistent']) {
			return;
		}
		$this->disconnect();
	}

	/**
	 * Opens the connection to Faktory.
	 */
	public function connect()
	{
		if (!is_null($this->stream)) {
			return $this->stream;
		} else {
			if (array_key_exists('url', $this->params)) {
				$url = $this->params['url'];
			} else {
				$scheme = array_key_exists('scheme', $this->params) ? $this->params['scheme'] : 'tcp';
				$host = array_key_exists('host', $this->params) ? $this->params['host'] : '127.0.0.1';
				$port = array_key_exists('port', $this->params) ? $this->params['port'] : 7419;
				$url = $scheme. '://' . $host . ':' . $port;
			}
			$stream = stream_socket_client($url, $errno, $errstr);
			if (!$stream) {
				throw new NotConnectedException;
			}
			$this->stream = $stream;
			$this->handshake();
		}
		return $this->stream;
	}

	/**
	 * Closes the connection to Faktory.
	 */
	public function disconnect()
	{
		if (!is_null($this->stream)) {
			fclose($this->stream);
		}
		$this->stream = null;
	}

	/**
	 * Checks if the connection to Faktory is considered open.
	 *
	 * @return bool
	 */
	public function isConnected()
	{
		return !is_null($this->stream);
	}

	/**
	 * Initial handshake.
	 */
	public function handshake()
	{
		$response = $this->readResponse();
		$this->log('hello: from server: '.$response, 'debug');
		if (!preg_match('/HI\s/', $response)) {
			throw new BadResponseException('expected HI, got: '.$response);
		}
		$server = json_decode(trim(substr($response, 3)), true);
		$this->log('server: '.json_encode($server), 'debug');

		$version = array_key_exists('v', $server) ? $server['v'] : -1;
		$nonce = array_key_exists('s', $server) ? $server['s'] : '';
		$iter = array_key_exists('i', $server) ? $server['i'] : 0;
		$this->log('version: '.$version, 'debug');
		$this->log('nonce: '.$nonce, 'debug');
		$this->log('iters: '.$iter, 'debug');

		if ($version !== 2) {
			throw new UnsupportedProtocolException('version: '.$version);
		}

		$hello = array(
			'hostname' => gethostname(),
			'pid' => getmypid(),
			'labels' => array('php'),
			'v' => $version
		);

		if (!empty($nonce) || !empty($iter)) {
			$hello['pwdhash'] = $this->passwordHash($nonce, $iter);
		}

		$wid = array_key_exists('wid', $this->params) ? $this->params['wid'] : null;
		if (!empty($wid)) {
			$hello['wid'] = $wid;
		}

		$response = $this->executeCommand('HELLO', $hello);
		if ($response !== "OK") {
			throw new BadResponseException('expected OK, got: '.$response);
		}
	}

	/**
	 * Calculate password hash.
	 *
	 * @return string hash
	 */
	private function passwordHash($nonce, $iter)
	{
		$password = array_key_exists('password', $this->params) ? $this->params['password'] : '';
		$data = $password . $nonce;
		for ($i=0; $i < $iter; $i++) {
			$data = hash('sha256', $data, true);
		}
		return bin2hex($data);
	}

	/**
	 * Writes the request for the given command over the connection.
	 *
	 * @param string $command Command.
	 * @param mixed $data Data.
	 *
	 * @return
	 */
	public function writeRequest($command, $data)
	{
		if (!$this->connect()) {
			throw new NotConnectedException;
		}
		$buffer = $command . ' ' . (is_string($data) ? $data : json_encode($data)) . "\r\n";
		$length = strlen($buffer);
		$written = fwrite($this->stream, $buffer, strlen($buffer));
		if ($written !== $length) {
			throw new WriteFailedException;
		}
		$this->log('write: '.$buffer, 'debug');
		return true;
	}

	/**
	 * Reads the response from Factory.
	 *
	 * @return string
	 */
	public function readResponse(int $length = 1024)
	{
		if (!$this->connect()) {
			throw new NotConnectedException;
		}
		$bytes = fread($this->stream, $length);
		//$this->log('read: bytes: '.$bytes, 'debug');

		while (strpos($bytes, "\r\n") === false) {
			$bytes .= fread($this->stream, $length - strlen($bytes));
			//$this->log('read: bytes: '.$bytes, 'debug');
		}

		$char = $bytes[0];
		if ($char === '$') {
			//$this->log('read: $', 'debug');
			$count = (int)substr($bytes, 1);
			//$this->log('read: count: '.$count, 'debug');
			if ($count > 0) {
				$offset = strlen($count) + 3;
				//$this->log('read: offset: '.$offset, 'debug');
				$response = substr($bytes, $offset);
				$this->log('read: response: '.$response, 'debug');
				return $response;
			} else {
				$response = '';
				$this->log('read: response: '.$response, 'debug');
				return $response;
			}
		} elseif ($char === '-' || $char === '+') {
			$response = trim(substr($bytes, 1, strpos($bytes, "\r\n")));
			$this->log('read: response: '.$response, 'debug');
			return $response;
		}
		return null;
	}

	/**
	 * Writes a request for the given command over the connection and reads back
	 * the response returned by Facktory.
	 *
	 * @param string $command Command.
	 * @param mixed $data Data.
	 *
	 * @return mixed
	 */
	public function executeCommand($command, $data)
	{
		if (!$this->connect()) {
			throw new NotConnectedException;
		}
		$this->writeRequest($command, $data);
		return $this->readResponse();
	}

	private function trim($response)
	{
		return trim(substr($response, 0, strpos($response, "\r\n")));
	}

	/**
	 * Initial handshake.
	 */
	public function heartbeat(string $wid)
	{
		if (!$this->connect()) {
			throw new NotConnectedException;
		}
		$response = $this->executeCommand('BEAT', array('wid' => $wid));
		if ($response !== "OK") {
			return 'OK';
		} else {
			$status = json_decode($response, true);
			if (array_key_exists('state', $status)) {
				$state = $status['state'];
				return strtoupper($state);
			}
		}
		return 'NOT_OK';
	}

	public function push(Job $job) : string
	{
		if (!$this->connect()) {
			throw new NotConnectedException;
		}
		$response = $this->executeCommand('PUSH', json_encode($job));
		if ($response !== "OK") {
			throw new BadResponseException('expected OK, got: '.$response);
		}
		return $job->getId();
	}

	public function fetch(array $queues)
	{
		if (!$this->connect()) {
			throw new NotConnectedException;
		}
		$response = $this->executeCommand('FETCH', implode(' ', $queues));
		return empty($response) ? null : json_decode($response);
	}

	public function ack(string $jobId) : void
	{
		if (!$this->connect()) {
			throw new NotConnectedException;
		}
		$job = new Job();
		$job->id($jobId);
		$response = $this->executeCommand('ACK', json_encode($job));
		if ($response !== "OK") {
			throw new BadResponseException('expected OK, got: '.$response);
		}
	}

	public function fail(string $jobId) : void
	{
		if (!$this->connect()) {
			throw new NotConnectedException;
		}
		$job = new Job();
		$job->id($jobId);
		$response = $this->executeCommand('FAIL', json_encode($job));
		if ($response !== "OK") {
			throw new BadResponseException('expected OK, got: '.$response);
		}
	}

	public function info()
	{
		if (!$this->connect()) {
			throw new NotConnectedException;
		}
		$response = $this->executeCommand('INFO', '');
		$this->log('info: '.$response, 'debug');
		return json_decode($response);
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
