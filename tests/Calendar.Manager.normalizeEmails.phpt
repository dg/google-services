<?php declare(strict_types=1);

use DG\Google\Calendar\Manager;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// normalizeEmails() is private and touches no services; build the instance without the
// constructor and invoke the method reflectively.
$call = static function (array $emails): array {
	$ref = new ReflectionClass(Manager::class);
	$manager = $ref->newInstanceWithoutConstructor();
	return $ref->getMethod('normalizeEmails')->invoke($manager, $emails);
};


test('addresses are trimmed, lowercased and keyed', function () use ($call) {
	Assert::same(
		['alice@example.com' => true, 'bob@example.com' => true],
		$call([' Alice@Example.com ', 'bob@example.com']),
	);
});


test('an invalid address is rejected', function () use ($call) {
	Assert::exception(
		fn() => $call(['alice@example.com', 'not-an-email']),
		InvalidArgumentException::class,
		'Invalid email address: not-an-email',
	);
});
