<?php declare(strict_types=1);

use DG\Google\Gmail\Manager;
use DG\Google\Gmail\Recipient;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$split = (new ReflectionMethod(Manager::class, 'splitAddressList'))
	->getClosure();

/** @return array{string, ?string}[] */
$pairs = static fn(array $list): array => array_map(static fn(Recipient $r): array => [$r->email, $r->name], $list);


test('empty string returns empty array', function () use ($split, $pairs) {
	Assert::same([], $pairs($split('')));
});


test('bare email', function () use ($split, $pairs) {
	Assert::same([['a@b.cz', null]], $pairs($split('a@b.cz')));
});


test('display name with email', function () use ($split, $pairs) {
	Assert::same([['a@b.cz', 'Plain Name']], $pairs($split('Plain Name <a@b.cz>')));
});


test('quoted display name with email', function () use ($split, $pairs) {
	Assert::same([['a@b.cz', 'Doe']], $pairs($split('"Doe" <a@b.cz>')));
});


test('multiple bare emails', function () use ($split, $pairs) {
	Assert::same(
		[['a@b.cz', null], ['c@d.cz', null]],
		$pairs($split('a@b.cz, c@d.cz')),
	);
});


test('multiple addresses with display names', function () use ($split, $pairs) {
	Assert::same(
		[['a@b.cz', 'Plain'], ['c@d.cz', 'Other']],
		$pairs($split('Plain <a@b.cz>, Other <c@d.cz>')),
	);
});


test('quoted display name containing comma is kept as one address', function () use ($split, $pairs) {
	Assert::same(
		[['xKrausT@seznam.cz', 'Kraus, Tomáš']],
		$pairs($split('"Kraus, Tomáš" <xKrausT@seznam.cz>')),
	);
});


test('multiple addresses each with comma inside quoted display name', function () use ($split, $pairs) {
	Assert::same(
		[['a@b.cz', 'Doe, J.'], ['c@d.cz', 'Smith, B.']],
		$pairs($split('"Doe, J." <a@b.cz>, "Smith, B." <c@d.cz>')),
	);
});


test('mixed quoted and plain display names', function () use ($split, $pairs) {
	Assert::same(
		[['a@b.cz', 'Kraus, T.'], ['c@d.cz', 'Plain Name'], ['e@f.cz', null]],
		$pairs($split('"Kraus, T." <a@b.cz>, Plain Name <c@d.cz>, e@f.cz')),
	);
});
