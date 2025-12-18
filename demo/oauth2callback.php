<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';


try {
	$authenticator = createAuthenticator();

	if (isset($_GET['code'])) {
		$authenticator->exchangeCodeForToken(trim($_GET['code']));
		echo '<h1>Authorization successful!</h1>';
		echo '<p>The access token has been saved. You can close this browser window and return to the command line to run the main script.</p>';

	} elseif (isset($_GET['error'])) {
		echo '<h1>Authorization error</h1>';
		echo '<p>An error occurred during authorization: ' . htmlspecialchars($_GET['error']) . '</p>';
		if (isset($_GET['error_description'])) {
			echo '<p>Error description: ' . htmlspecialchars($_GET['error_description']) . '</p>';
		}

	} else {
		echo '<h1>Error: Authorization code missing or error.</h1>';
	}

} catch (Throwable $e) {
	echo '<p>Error message: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
