<?php declare(strict_types=1);

use DG\Google\Gmail\Manager;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$decode = (new ReflectionMethod(Manager::class, 'decodeHeader'))->getClosure();


test('plain ASCII passes through unchanged', function () use ($decode) {
	Assert::same('Hello world', $decode('Hello world'));
	Assert::same('<abc@host>', $decode('<abc@host>'));
});


test('RFC 2047 B-encoded UTF-8 subject', function () use ($decode) {
	// "Příliš žluťoučký" base64-encoded
	$encoded = '=?UTF-8?B?' . base64_encode('Příliš žluťoučký') . '?=';
	Assert::same('Příliš žluťoučký', $decode($encoded));
});


test('RFC 2047 Q-encoded display name', function () use ($decode) {
	Assert::same('Žižka', $decode('=?utf-8?Q?=C5=BDi=C5=BEka?='));
});


test('mixed encoded word inside an address header', function () use ($decode) {
	$in = '=?UTF-8?B?' . base64_encode('Jan Novák') . '?= <jan@example.cz>';
	Assert::same('Jan Novák <jan@example.cz>', $decode($in));
});
