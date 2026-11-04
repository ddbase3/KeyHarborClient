# Privacy and data handling: KeyHarborClient

This document describes the implementation in this repository, not a certification of legal compliance or a complete deployment privacy notice.

## Purpose and data flow

`KeyHarborConnector` sends server-to-server BASE3 microservice requests to a URL selected by the consuming application. The request contains a method name, named method arguments encoded as JSON inside a form body, a KeyHarbor service credential in an HTTP Authorization header, a timestamp, a nonce and a request signature. Arguments may contain personal data, depending on the consuming service and the requested operation. Responses may also contain personal data or file content. The caller and the receiving service determine the processing purpose and legal basis.

The client accepts only HTTPS endpoints, validates TLS certificates and hostnames and does not follow redirects. The destination server and its infrastructure receive the request data, technical source address, and service credential. The plugin does not decide which data the server may release: operation authorization and resource access remain owned by the receiving service.

## Storage, retention and deletion

The connector holds the configured token, request arguments, response data and signature only in PHP memory while processing each call. It reads the service token from the application's existing `IConfiguration` on each call; it does not write or cache its own credentials, personal data, responses or files and does not create database tables. The deployment's configuration backend, PHP runtime, server/network logs, backups, receiving endpoint and consuming application may store related information independently. Their retention, deletion and data subject handling cannot be determined from this client source alone.

The connector does not write tokens, response bodies or file contents to logs. For failed transports it may raise an exception containing the cURL error message, and for failed HTTP responses it raises an exception containing the HTTP status and method name, without response body. Invalid-token diagnostics include token length and trimmed syntax validity but not token contents. Calling jobs may log these exceptions under their own policies.

## Recipients, transfers and safeguards

Recipients and processing locations depend on the HTTPS endpoint the host project configures. The client uses a signed HMAC-SHA-256 request with a fresh nonce and timestamp, and checks remote TLS certificates, but revocation, replay-window enforcement, operation grants, and security/audit logs are the responsibility of the KeyHarbor server. The operator must restrict access to the existing secret-bearing configuration and provision least-privileged credentials.

## Known gaps and deployment checks

The actual controller/processor roles, legal bases, external recipients, data export locations, server-side logs, retention periods, incident response, deletion workflow, and credential rotation must be verified for each deployment. A consumer must not mistake a successful transport signature for authorization to read or publish specific content. This plugin has no user-facing interface; where applications expose results, their owning UI is responsible for accessibility and localization.

The signature itself is computed through the stateless `CredentialFoundation\Security\HmacRequestSigner`; no signing key is persisted or copied into this plugin by that helper.
