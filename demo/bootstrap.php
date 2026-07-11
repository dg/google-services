<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';


function createAuthenticator(): DG\Google\Authenticator
{
	// Spread the server's required scopes so the token this demo mints always covers what the MCP
	// server needs (see DG\Google\Scopes), then add the extra scopes the demo scripts here use.
	return new DG\Google\Authenticator([
		...DG\Google\Scopes::McpServer,
		Google\Service\Drive::DRIVE,
		Google\Service\Meet::MEETINGS_SPACE_CREATED,
		// ...
	], __DIR__ . '/tokens');
}


function googleAuthenticate(): Google\Client
{
	$authenticator = createAuthenticator();
	try {
		return $authenticator->authenticate();
	} catch (RuntimeException) {
		header('Location: ' . $authenticator->getAuthUrl());
		echo $authenticator->getAuthUrl();
		exit;
	}
}
