# KeyHarborClient

Client-only connector for existing BASE3 HTTP microservices protected by the KeyHarbor HMAC service-credential protocol. This plugin does not install KeyHarbor's server-side administration, credential repository, database tables, migrations, jobs or authentication middleware.

## Use

The application composes a regular ResourceFoundation proxy (or another existing service proxy) with the generic connector. No additional HTTP client or service-specific transport class is required.

```php
use KeyHarborClient\Microservice\KeyHarborConnector;
use ResourceFoundation\Api\IEntityDataService;
use ResourceFoundation\Proxy\EntityDataProxy;

$service = new EntityDataProxy(new KeyHarborConnector(
	'https://xrm.example.org/entitydatamicroservice.json',
	IEntityDataService::class,
	$configuration,
	'contourz',
	'xrm_token',
	['getEntries' => 'list', 'getEntry' => '?array']
));
$entries = $service->getEntries(['type' => 'project']);
```

`KeyHarborConnector` extends `Base3\Microservice\AbstractMicroserviceConnector`. It uses the inherited call format, parameter-name mapping, response decoder and proxy behavior. Only required/default argument checks, KeyHarbor HTTP transport and result validation are local. HMAC canonicalization and signing are delegated to `CredentialFoundation\Security\HmacRequestSigner`, not reimplemented. The existing legacy parent constructor is intentionally not called because it loads the masterpass and ServiceLocator; the client uses only the parent protocol methods.

The first argument is the approved HTTPS microservice URL. The second is the real method interface, used via reflection to map positional arguments to the parameter names the BASE3 receiver expects. The next three arguments select the existing BASE3 `IConfiguration` and its group/key; the client does not install or manage credentials. The final map both restricts callable methods and validates their JSON result shapes. Accepted shapes are `list`, `array`, `?array`, `string`, `?string`, `bool`, `int`, `float`, and `mixed`.

There is no remote writable operation enabled unless the consuming project deliberately lists it **and** the authoritative server permits that operation. The service interface and method map must come from trusted project composition, never from an external HTTP request. The remote authority makes the final authorization decision.

## Runtime behavior

The connector reads the `b3k_...` service credential only on real method calls, not during DI construction or worker class discovery. It sends named arguments in the original BASE3 microservice `call` / `params` / `binarystream` / `serialized` form body and signs the exact POST method, URL path, query, timestamp, random nonce and SHA-256 body digest with `CredentialFoundation\Security\HmacRequestSigner` (HMAC-SHA-256). Server-side KeyHarbor validates the signature and the credential's permitted operations. A different nonce is generated for every request.

The client requires HTTPS, checks the TLS peer and host, follows no redirects, and returns the decoded JSON value only after HTTP 200, valid JSON and the configured response-shape check. Missing/invalid credentials, unsuccessful HTTP responses, malformed JSON and invalid result shapes throw; failed reads are **not** converted to an empty list. It does not retry or cache remote reads.

Credentials must not be committed to source control. The deployment owns provisioning, storage permissions, rotation, transport availability and server-side permissions. The connector does not log tokens, response bodies or retrieved file bytes. For personal data and deployment-dependent obligations, see [PRIVACY.md](PRIVACY.md).

## Tests

From a BASE3 installation root:

```sh
php plugin/KeyHarborClient/test/KeyHarborConnectorTest.php
```

The Contourz integration is separately tested with:

```sh
php plugin/ContourzWebsite/tests/KeyHarborClientIntegrationTest.php
```
