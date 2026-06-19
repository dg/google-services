<?php declare(strict_types=1);

use DG\Google\Authenticator;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// The constructor reads secret.json to build a Google\Client, which we don't have in a unit test.
// getTokenDiagnostics() only touches $this->tokenDir, $this->scopes and $this->client, so build the
// instance without the constructor and wire those three private properties by hand.
$make = static function (string $tokenDir, array $scopes = ['scope-a']): Authenticator {
	$ref = new ReflectionClass(Authenticator::class);
	$auth = $ref->newInstanceWithoutConstructor();
	$ref->getProperty('tokenDir')->setValue($auth, $tokenDir);
	$ref->getProperty('scopes')->setValue($auth, $scopes);
	$ref->getProperty('client')->setValue($auth, new Google\Client);
	return $auth;
};

$tmpDir = sys_get_temp_dir() . '/gs-diag-' . getmypid() . '-' . uniqid();
mkdir($tmpDir);
register_shutdown_function(static function () use ($tmpDir): void {
	@array_map('unlink', glob($tmpDir . '/*') ?: []);
	@rmdir($tmpDir);
});


test('missing token file', function () use ($make, $tmpDir) {
	$diag = $make($tmpDir)->getTokenDiagnostics();
	Assert::false($diag['tokenFileExists']);
	Assert::same(['scope-a'], $diag['scopes']);
	Assert::false(isset($diag['tokenFileReadable']));
});


test('malformed token file', function () use ($make, $tmpDir) {
	file_put_contents($tmpDir . '/token.json', 'not json');
	$diag = $make($tmpDir)->getTokenDiagnostics();
	Assert::true($diag['tokenFileExists']);
	Assert::false($diag['tokenFileReadable']);
});


test('valid, locally-fresh token', function () use ($make, $tmpDir) {
	$created = time() - 100;
	file_put_contents($tmpDir . '/token.json', json_encode([
		'access_token' => 'AT',
		'refresh_token' => 'RT',
		'created' => $created,
		'expires_in' => 3600,
	]));
	$diag = $make($tmpDir)->getTokenDiagnostics();
	Assert::true($diag['tokenFileReadable']);
	Assert::true($diag['hasAccessToken']);
	Assert::true($diag['hasRefreshToken']);
	Assert::same($created, $diag['created']);
	Assert::same(3600, $diag['expiresIn']);
	Assert::same(100, $diag['ageSeconds']);
	Assert::false($diag['locallyExpired']);
});


test('expired token', function () use ($make, $tmpDir) {
	file_put_contents($tmpDir . '/token.json', json_encode([
		'access_token' => 'AT',
		'created' => time() - 7200,
		'expires_in' => 3600,
	]));
	$diag = $make($tmpDir)->getTokenDiagnostics();
	Assert::true($diag['locallyExpired']);
	Assert::false($diag['hasRefreshToken']);
});


test('missing created/expires_in counts as locally expired', function () use ($make, $tmpDir) {
	file_put_contents($tmpDir . '/token.json', json_encode(['access_token' => 'AT']));
	$diag = $make($tmpDir)->getTokenDiagnostics();
	Assert::true($diag['locallyExpired']);
	Assert::null($diag['created']);
	Assert::null($diag['expiresIn']);
	Assert::null($diag['ageSeconds']);
});


test('in-memory token diverging from disk is flagged', function () use ($make, $tmpDir) {
	file_put_contents($tmpDir . '/token.json', json_encode([
		'access_token' => 'disk-token',
		'created' => time(),
		'expires_in' => 3600,
	]));
	$auth = $make($tmpDir);
	$client = (new ReflectionProperty(Authenticator::class, 'client'))->getValue($auth);
	$client->setAccessToken(['access_token' => 'memory-token', 'created' => time(), 'expires_in' => 3600]);

	$diag = $auth->getTokenDiagnostics();
	Assert::false($diag['memoryMatchesDisk']);
});


test('in-memory token matching disk', function () use ($make, $tmpDir) {
	$created = time();
	file_put_contents($tmpDir . '/token.json', json_encode([
		'access_token' => 'same-token',
		'created' => $created,
		'expires_in' => 3600,
	]));
	$auth = $make($tmpDir);
	$client = (new ReflectionProperty(Authenticator::class, 'client'))->getValue($auth);
	$client->setAccessToken(['access_token' => 'same-token', 'created' => $created, 'expires_in' => 3600]);

	$diag = $auth->getTokenDiagnostics();
	Assert::true($diag['memoryMatchesDisk']);
	Assert::same($created, $diag['memoryCreated']);
});
