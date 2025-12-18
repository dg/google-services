<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';


function createAuthenticator(): DG\Google\Authenticator
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
	$authenticator = createAuthenticator();
	try {
		return $authenticator->authenticate();
	} catch (RuntimeException) {
		header('Location: ' . $authenticator->getAuthUrl());
		echo $authenticator->getAuthUrl();
		exit;
	}
}
