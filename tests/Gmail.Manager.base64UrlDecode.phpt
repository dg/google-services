<?php declare(strict_types=1);

use DG\Google\Gmail\Manager;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$decode = (new ReflectionMethod(Manager::class, 'base64UrlDecode'))
	->getClosure();


test('decodes standard base64url payload', function () use ($decode) {
	// "Hello, world!" in base64url
	Assert::same('Hello, world!', $decode('SGVsbG8sIHdvcmxkIQ'));
});


test('handles URL-safe alphabet (- and _)', function () use ($decode) {
	// bytes 0xFB 0xEF 0xFF: standard base64 = "++//", base64url = "--__"
	Assert::same("\xFB\xEF\xFF", $decode('--__'));
});


test('decodes empty input to empty string', function () use ($decode) {
	Assert::same('', $decode(''));
});


test('invalid base64url throws RuntimeException', function () use ($decode) {
	Assert::exception(
		fn() => $decode('!!!not-base64!!!'),
		RuntimeException::class,
		'Invalid base64url data.',
	);
});
