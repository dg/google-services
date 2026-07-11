<?php declare(strict_types=1);

use DG\Google\Authenticator;
use DG\Google\AuthException;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// assertScopesGranted() is private and only reads $this->scopes; build the instance without the
// constructor and invoke the method reflectively.
$call = static function (array $scopes, array $token): void {
	$ref = new ReflectionClass(Authenticator::class);
	$auth = $ref->newInstanceWithoutConstructor();
	$ref->getProperty('scopes')->setValue($auth, $scopes);
	$m = $ref->getMethod('assertScopesGranted');
	$m->invoke($auth, $token);
};


test('all required scopes granted passes', function () use ($call) {
	Assert::noError(fn() => $call(['a', 'b'], ['scope' => 'a b c']));
});


test('a missing scope is rejected', function () use ($call) {
	Assert::exception(
		fn() => $call(['a', 'b', 'x'], ['scope' => 'a b c']),
		AuthException::class,
		'%A%missing required scope(s): x%A%',
	);
});


test('token without a scope field is left unchecked', function () use ($call) {
	Assert::noError(fn() => $call(['a', 'b'], ['access_token' => 'AT']));
});


test('non-string scope field is left unchecked', function () use ($call) {
	Assert::noError(fn() => $call(['a'], ['scope' => ['a']]));
});


test('a broader granted scope satisfies a narrower required one', function () use ($call) {
	// full mail.google.com covers gmail.modify; full calendar covers calendar.readonly
	Assert::noError(fn() => $call(
		['https://www.googleapis.com/auth/gmail.modify', 'https://www.googleapis.com/auth/calendar.readonly'],
		['scope' => 'https://mail.google.com/ https://www.googleapis.com/auth/calendar'],
	));
});


test('drive covers presentations', function () use ($call) {
	Assert::noError(fn() => $call(
		['https://www.googleapis.com/auth/presentations'],
		['scope' => 'https://www.googleapis.com/auth/drive'],
	));
});
