<?php

namespace Monitor;


use HttpHelper\Request;
use HttpHelper\RequestException;

class Client {

	const ERROR = 'error';
	const OK = 'ok';
	const WARN = 'warn';

	/** @var string */
	private $token;

	/** @var string */
	private $url;

	/** @var Request */
	private $request;

	/**
	 * @param string $monitorURL
	 * @param string $token
	 */
	public function __constructor($monitorURL, $token){
		if (substr($monitorURL, -1) == "/") {
			$monitorURL = substr($monitorURL, 0, strlen($monitorURL)-1);
		}
		$this->url = $monitorURL;
		$this->token = $token;
		$this->request = new Request();
		$this->request->setHeaders(array(
			'Token' => $token
		));
	}

	/**
	 * @param $json
	 * @param int $options
	 * @return mixed
	 * @throws \Exception
	 */
	private function decodeJSON($json, $options = 0) {
		$json = (string) $json;
		if (!preg_match('##u', $json)) {
			throw new \Exception('Invalid UTF-8 sequence', 5); // workaround for PHP < 5.3.3 & PECL JSON-C
		}

		$forceArray = (bool) ($options & self::FORCE_ARRAY);
		if (!$forceArray && preg_match('#(?<=[^\\\\]")\\\\u0000(?:[^"\\\\]|\\\\.)*+"\s*+:#', $json)) { // workaround for json_decode fatal error when object key starts with \u0000
			throw new \Exception(static::$messages[JSON_ERROR_CTRL_CHAR]);
		}
		$args = array($json, $forceArray, 512);
		if (PHP_VERSION_ID >= 50400 && !(defined('JSON_C_VERSION') && PHP_INT_SIZE > 4)) { // not implemented in PECL JSON-C 1.3.2 for 64bit systems
			$args[] = JSON_BIGINT_AS_STRING;
		}
		$value = call_user_func_array('json_decode', $args);

		if ($value === NULL && $json !== '' && strcasecmp($json, 'null')) { // '' is not clearing json_last_error
			$error = json_last_error();
			throw new \Exception(isset(static::$messages[$error]) ? static::$messages[$error] : 'Unknown error', $error);
		}
		return $value;
	}

	/**
	 * @param int $taskId
	 * @param array|\Exception|string|null $msg
	 * @param string $status
	 * @returns array|string
	 * @throws ClientException
	 */
	public function log($taskId, $msg, $status = Client::OK) {
		$data = array();
		$now = new \DateTime();
		$data['timestamp'] = $now->format(\DateTime::ISO8601);
		if ($msg instanceof \Exception) {
			$msg = array(
				'code' => $msg->getCode(),
				'message' => $msg->getMessage(),
				'file' => $msg->getFile(),
				'line' => $msg->getLine(),
				'type' => get_class($msg)
			);
			$status = Client::ERROR;
		}
		if (!is_array($msg) || !is_string($msg)) {
			throw new \InvalidArgumentException('$msg must be an array, string or instance of Exception');
		}
		$data['data'] = $msg;
		$data['status'] = $status;
		$data['task_id'] = $taskId;
		$this->request->setUrl($this->url . '/api/events');
		$this->request->setMethod(Request::POST);
		try {
			$response = $this->request->send();
			try {
				$data = $this->decodeJSON($response->getBody(), true);
			} catch (\Exception $e) {
				$data = $response->getBody();
			}
			if ($response->getCode() == 200) {
				return $data;
			} else {
				if (is_array($data)) {
					if (array_key_exists('error', $data)) {
						throw new ClientException($data['error'],$response->getCode());
					}
					throw new ClientException($response->getBody(), $response->getCode());
				} else {
					throw new ClientException($data, $response->getCode());
				}
			}
		} catch (RequestException $e) {
			throw new ClientException($e->getMessage(), $e->getCode(), $e);
		}
	}

	/**
	 * @param int $taskId
	 * @param array|\Exception|string|null $msg
	 * @return array|string
	 * @throws ClientException
	 */
	public function ok($taskId, $msg) {
		return $this->log($taskId, $msg);
	}

	/**
	 * @param int $taskId
	 * @param array|\Exception|string|null $msg
	 * @return array|string
	 * @throws ClientException
	 */
	public function error($taskId, $msg) {
		return $this->log($taskId, $msg, Client::ERROR);
	}

	/**
	 * @param int $taskId
	 * @param array|\Exception|string|null $msg
	 * @return array|string
	 * @throws ClientException
	 */
	public function warn($taskId, $msg) {
		return $this->log($taskId, $msg, Client::WARN);
	}

}