<?php declare(strict_types=1);

namespace KeyHarborClient\Microservice;

use Base3\Configuration\Api\IConfiguration;
use Base3\Microservice\AbstractMicroserviceConnector;
use CredentialFoundation\Dto\HmacAuthenticationRequest;
use CredentialFoundation\Security\HmacRequestSigner;
use ReflectionClass;
use RuntimeException;

/**
 * Reuses BASE3's microservice call/parameter/JSON protocol with KeyHarbor HMAC transport.
 * The endpoint, interface, credential setting and permitted operations are project-owned.
 */
final class KeyHarborConnector extends AbstractMicroserviceConnector {

	private readonly string $path;
	private readonly string $query;
	private readonly array $defaults;

	/**
	 * @param string $url Full HTTPS URL of the existing microservice.
	 * @param class-string $interface Interface declaring the remote methods.
	 * @param IConfiguration $configuration Existing BASE3 configuration.
	 * @param string $credentialGroup Configuration group holding the KeyHarbor token.
	 * @param string $credentialKey Configuration key holding the KeyHarbor token.
	 * @param array<string,string> $operations Allowed method names mapped to JSON result shapes.
	 */
	public function __construct(
		string $url,
		string $interface,
		private readonly IConfiguration $configuration,
		private readonly string $credentialGroup,
		private readonly string $credentialKey,
		private readonly array $operations
	) {
		$parts = parse_url($url);
		if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' ||
			!isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
			throw new RuntimeException('KeyHarbor microservice requires an HTTPS endpoint.');
		}
		$this->path = (string)($parts['path'] ?? '/');
		$this->query = (string)($parts['query'] ?? '');
		if ($this->path === '' || $this->path[0] !== '/' || !interface_exists($interface) || $operations === []) {
			throw new RuntimeException('Invalid KeyHarbor microservice contract.');
		}

		$reflection = new ReflectionClass($interface);
		if (!$reflection->isInterface()) {
			throw new RuntimeException('The microservice contract must be an interface.');
		}
		$methods = [];
		$defaults = [];
		foreach ($operations as $name => $shape) {
			if (!is_string($name) || !$reflection->hasMethod($name) ||
				!in_array($shape, ['list', 'array', '?array', 'string', '?string', 'bool', 'int', 'float', 'mixed'], true)) {
				throw new RuntimeException('Invalid KeyHarbor microservice operation definition.');
			}
			$parameters = [];
			$methodDefaults = [];
			foreach ($reflection->getMethod($name)->getParameters() as $position => $parameter) {
				if ($parameter->isVariadic()) {
					throw new RuntimeException('Variadic microservice arguments are not supported.');
				}
				$parameters[] = $parameter->getName();
				if ($parameter->isDefaultValueAvailable()) {
					$methodDefaults[$position] = $parameter->getDefaultValue();
				}
			}
			$methods[] = ['name' => $name, 'params' => $parameters];
			$defaults[$name] = $methodDefaults;
		}
		$this->defaults = $defaults;

		// The legacy parent constructor retrieves ServiceLocator/masterpass. Its transport
		// is overridden here; only the existing call protocol and proxy behavior are used.
		$this->url = $url;
		$this->service = ['name' => $reflection->getShortName(), 'interfaces' => [$interface], 'methods' => $methods];
		$this->flags = 0;
	}

	/**
	 * Completes optional interface arguments, then delegates method encoding and
	 * response decoding to AbstractMicroserviceConnector::__call().
	 */
	public function __call($method, $args) {
		if (!array_key_exists($method, $this->operations)) {
			throw new RuntimeException('KeyHarbor microservice operation is not permitted: ' . $method);
		}
		$methodData = parent::getMethodData($method);
		if ($methodData === null || count($args) > count($methodData['params'])) {
			throw new RuntimeException('Invalid microservice arguments for ' . $method . '.');
		}
		foreach ($methodData['params'] as $position => $name) {
			if (array_key_exists($position, $args)) continue;
			if (!array_key_exists($position, $this->defaults[$method])) {
				throw new RuntimeException('Missing microservice argument for ' . $method . '.');
			}
			$args[$position] = $this->defaults[$method][$position];
		}
		return parent::__call($method, $args);
	}

	/**
	 * Sends only HMAC-authenticated POST requests and refuses unsuccessful or
	 * malformed responses rather than allowing a failure to appear as null/[].
	 */
	protected function httpPost($url, $data) {
		$operation = $data['call'] ?? null;
		if ($url !== $this->url || !is_string($operation) || !array_key_exists($operation, $this->operations)) {
			throw new RuntimeException('Unsupported KeyHarbor request.');
		}

		$token = $this->configuration->getString($this->credentialGroup, $this->credentialKey);
		if (preg_match('/^b3k_[a-f0-9]{20}_([A-Za-z0-9_-]{43})$/D', $token, $matches) !== 1) {
			throw new RuntimeException(sprintf(
				'KeyHarbor client credential is missing or invalid. [token_length=%d trimmed_valid=%s]',
				strlen($token),
				preg_match('/^b3k_[a-f0-9]{20}_[A-Za-z0-9_-]{43}$/D', trim($token)) === 1 ? 'yes' : 'no'
			));
		}

		$body = http_build_query($data);
		$timestamp = time();
		$nonce = bin2hex(random_bytes(16));
		$request = new HmacAuthenticationRequest(
			$token, 'POST', $this->path, $this->query, $timestamp, $nonce,
			'unsigned', $body
		);
		$signature = (new HmacRequestSigner())->sign($request, $matches[1]);

		$handle = curl_init($url);
		if ($handle === false) {
			throw new RuntimeException('Could not open KeyHarbor microservice connection.');
		}
		$options = [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/x-www-form-urlencoded',
				'Authorization: Bearer ' . $token,
				'X-Base3-Timestamp: ' . $timestamp,
				'X-Base3-Nonce: ' . $nonce,
				'X-Base3-Signature: ' . $signature
			],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2
		];
		if (!curl_setopt_array($handle, $options)) {
			curl_close($handle);
			throw new RuntimeException('Could not configure KeyHarbor microservice request.');
		}

		$response = curl_exec($handle);
		$status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
		$error = curl_error($handle);
		curl_close($handle);
		if ($response === false) {
			throw new RuntimeException('KeyHarbor microservice transport failed: ' . $error);
		}
		if ($status !== 200) {
			throw new RuntimeException('KeyHarbor microservice returned HTTP ' . $status . ' for ' . $operation . '.');
		}
		try {
			$result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			throw new RuntimeException('KeyHarbor microservice returned invalid JSON for ' . $operation . '.', 0, $error);
		}
		$valid = match ($this->operations[$operation]) {
			'list' => is_array($result) && array_is_list($result),
			'array' => is_array($result),
			'?array' => $result === null || is_array($result),
			'string' => is_string($result),
			'?string' => $result === null || is_string($result),
			'bool' => is_bool($result),
			'int' => is_int($result),
			'float' => is_float($result),
			'mixed' => true
		};
		if (!$valid) {
			throw new RuntimeException('KeyHarbor microservice returned an invalid result for ' . $operation . '.');
		}
		return $response;
	}
}
