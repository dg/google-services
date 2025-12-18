<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';


function createAuthenticator()
{
	return new DG\Google\Authenticator([
		Google\Service\Drive::DRIVE,
		Google\Service\Calendar::CALENDAR_EVENTS,
		Google\Service\Calendar::CALENDAR_READONLY,
		// ...
	], __DIR__ . '/tokens');
}


function googleAuthenticate(): Google\Client
{
	try {
		$authenticator = createAuthenticator();
		return $authenticator->authenticate();

	} catch (RuntimeException $e) {
		header('Location: ' . $authenticator->getAuthUrl());
		echo $authenticator->getAuthUrl();
		exit;
	}
}
