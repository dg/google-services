<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';


/**
 * With GOOGLE_TOOLS set, the token covers just the services those rules enable (and lands in
 * GOOGLE_TOKEN_DIR), e.g. a Slides-only token for a server that must never reach the mailbox.
 */
function createAuthenticator(): DG\Google\Authenticator
{
	$tokenDir = getenv('GOOGLE_TOKEN_DIR') ?: __DIR__ . '/tokens';
	$rules = getenv('GOOGLE_TOOLS') ?: null;
	if ($rules !== null) {
		$tools = DG\Google\ToolSelection::select($rules, DG\Google\ToolSelection::discover());
		return new DG\Google\Authenticator(DG\Google\Scopes::forServices(DG\Google\ToolSelection::getServices($tools)), $tokenDir);
	}

	// all the server's scopes (see DG\Google\Scopes) plus the extra ones the demo scripts here use
	return new DG\Google\Authenticator([
		...array_values(DG\Google\Scopes::Services),
		Google\Service\Drive::DRIVE,
		Google\Service\Meet::MEETINGS_SPACE_CREATED,
		// ...
	], $tokenDir);
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
