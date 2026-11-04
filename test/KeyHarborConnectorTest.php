<?php declare(strict_types=1);

namespace KeyHarborClient\Microservice {

	function curl_init(string $url): \stdClass {
		$GLOBALS['khc_test_url'] = $url;
		$GLOBALS['khc_test_calls']++;
		return new \stdClass();
	}

	function curl_setopt_array(\stdClass $handle, array $options): bool {
		$GLOBALS['khc_test_options'] = $options;
		return $GLOBALS['khc_test_options_ok'];
	}

	function curl_exec(\stdClass $handle): string|false {
		return $GLOBALS['khc_test_response'];
	}

	function curl_getinfo(\stdClass $handle, int $option): int {
		return $GLOBALS['khc_test_status'];
	}

	function curl_error(\stdClass $handle): string {
		return 'Simulated transport failure';
	}

	function curl_close(\stdClass $handle): void {}
}

namespace KeyHarborClient\Test {

	use KeyHarborClient\Microservice\KeyHarborConnector;
	use KeyHarborClient\Test\Support\ITestMicroservice;
	use KeyHarborClient\Test\Support\TestConfiguration;
	use RuntimeException;

	if (!defined('DIR_ROOT')) define('DIR_ROOT', dirname(__DIR__, 3) . '/');
	if (!defined('DIR_SRC')) define('DIR_SRC', DIR_ROOT . 'src/');
	if (!defined('DIR_CNF')) define('DIR_CNF', DIR_ROOT . 'cnf/');
	if (!defined('DIR_PLUGIN')) define('DIR_PLUGIN', DIR_ROOT . 'plugin/');
	if (!defined('DIR_TEST')) define('DIR_TEST', DIR_ROOT . 'test/');
	require_once DIR_SRC . 'Core/Autoloader.php';
	\Base3\Core\Autoloader::register();

	foreach ([
		'CURLOPT_POST', 'CURLOPT_POSTFIELDS', 'CURLOPT_HTTPHEADER',
		'CURLOPT_RETURNTRANSFER', 'CURLOPT_FOLLOWLOCATION',
		'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT',
		'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST',
		'CURLINFO_HTTP_CODE'
	] as $constant) {
		if (!defined($constant)) define($constant, count(get_defined_constants()) + 100);
	}

	function check(bool $condition, string $message): void {
		if (!$condition) throw new RuntimeException('Test failed: ' . $message);
	}

	function expectFailure(callable $action, string $message): void {
		try {
			$action();
		} catch (RuntimeException) {
			return;
		}
		throw new RuntimeException('Expected failure: ' . $message);
	}

	$token = 'b3k_' . str_repeat('c', 20) . '_' . str_repeat('d', 43);
	$configuration = new TestConfiguration($token);
	$GLOBALS['khc_test_calls'] = 0;
	$GLOBALS['khc_test_options_ok'] = true;
	$GLOBALS['khc_test_status'] = 200;
	$GLOBALS['khc_test_response'] = '[]';

	check(is_subclass_of(KeyHarborConnector::class, \Base3\Microservice\AbstractMicroserviceConnector::class), 'inherits BASE3 transport protocol');
	$connector = new KeyHarborConnector(
		'https://xrm.example.org/examplemicroservice.json?source=website',
		ITestMicroservice::class,
		$configuration,
		'remote',
		'token',
		['getRecords' => 'list', 'getRecord' => '?array', 'getEnabled' => 'bool']
	);
	check($connector->getMicroserviceUrl() === 'https://xrm.example.org/examplemicroservice.json?source=website', 'endpoint retained');
	check($configuration->reads === 0 && $GLOBALS['khc_test_calls'] === 0, 'construction is side-effect free');
	check($connector->getRecords('photo') === [], 'default parameter result');
	check($configuration->reads === 1 && $GLOBALS['khc_test_calls'] === 1, 'credential read on request only');

	$options = $GLOBALS['khc_test_options'];
	check($GLOBALS['khc_test_url'] === $connector->getMicroserviceUrl(), 'exact URL');
	check($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'TLS and redirect policy');
	parse_str($options[CURLOPT_POSTFIELDS], $form);
	check($form['call'] === 'getRecords', 'method name');
	check(json_decode($form['params'], true, 512, JSON_THROW_ON_ERROR) === ['category' => 'photo', 'limit' => 5], 'reflection and default argument mapping');
	$headers = [];
	foreach ($options[CURLOPT_HTTPHEADER] as $header) {
		[$name, $value] = explode(': ', $header, 2);
		$headers[$name] = $value;
	}
	check($headers['Authorization'] === 'Bearer ' . $token, 'service credential');
	$canonical = implode("\n", ['POST', '/examplemicroservice.json', 'source=website', $headers['X-Base3-Timestamp'], $headers['X-Base3-Nonce'], hash('sha256', $options[CURLOPT_POSTFIELDS])]);
	check($headers['X-Base3-Signature'] === hash_hmac('sha256', $canonical, str_repeat('d', 43)), 'canonical path and query HMAC');

	$GLOBALS['khc_test_response'] = '{"id":42}';
	check($connector->getRecord(42) === ['id' => 42], 'optional array result');
	$GLOBALS['khc_test_response'] = 'null';
	check($connector->getRecord(1000) === null, 'genuine missing result');
	$GLOBALS['khc_test_response'] = 'true';
	check($connector->getEnabled() === true, 'service-independent return shape');

	$GLOBALS['khc_test_response'] = '{"error":"unexpected"}';
	expectFailure(fn() => $connector->getRecords('photo'), 'object response cannot become a complete list');
	$GLOBALS['khc_test_response'] = 'not json';
	expectFailure(fn() => $connector->getRecord(42), 'invalid json');
	$GLOBALS['khc_test_response'] = '[]';
	$GLOBALS['khc_test_status'] = 403;
	expectFailure(fn() => $connector->getRecords('photo'), 'denied');
	$GLOBALS['khc_test_status'] = 200;
	$GLOBALS['khc_test_response'] = false;
	expectFailure(fn() => $connector->getRecords('photo'), 'transport failure');
	$GLOBALS['khc_test_response'] = '[]';
	$GLOBALS['khc_test_options_ok'] = false;
	expectFailure(fn() => $connector->getRecords('photo'), 'curl setup failure');
	$GLOBALS['khc_test_options_ok'] = true;

	$calls = $GLOBALS['khc_test_calls'];
	expectFailure(fn() => $connector->deleteRecord(42), 'not in allowed operation map');
	expectFailure(fn() => $connector->getRecords(), 'missing argument');
	expectFailure(fn() => $connector->getRecords('photo', 5, 2), 'extra argument');
	check($GLOBALS['khc_test_calls'] === $calls, 'disallowed or malformed calls do not reach network');

	$configuration->token = 'invalid';
	expectFailure(fn() => $connector->getRecords('photo'), 'credential rejected before transport');
	check($GLOBALS['khc_test_calls'] === $calls, 'credential rejected before network');
	expectFailure(fn() => new KeyHarborConnector('http://xrm.example.org/api.json', ITestMicroservice::class, $configuration, 'remote', 'token', ['getRecords' => 'list']), 'HTTPS required');
	expectFailure(fn() => new KeyHarborConnector('https://xrm.example.org/api.json', ITestMicroservice::class, $configuration, 'remote', 'token', ['deleteRecord' => 'mixed']), 'interface method required');

	echo "KeyHarborClient generic connector: OK\n";
}
