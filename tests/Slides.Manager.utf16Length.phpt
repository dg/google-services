<?php declare(strict_types=1);

use DG\Google\Slides\Manager;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// utf16Length backs the diff and trailing-newline maths in setShapeText: the Slides API addresses
// text in UTF-16 code units, where a non-BMP character (most emoji) counts as two. It is computed by
// re-encoding to UTF-16 and halving the byte count.
$len = (new ReflectionMethod(Manager::class, 'utf16Length'))
	->getClosure();


test('ASCII and control characters count one unit each', function () use ($len) {
	Assert::same(0, $len(''));
	Assert::same(5, $len('Hello'));
	Assert::same(1, $len("\n"));
	Assert::same(1, $len("\x0b")); // soft line break (vertical tab)
});


test('BMP characters (incl. accented Latin) count as one unit', function () use ($len) {
	Assert::same(6, $len('Osobní'));   // í is in the BMP
	Assert::same(1, $len('–'));        // en dash U+2013
});


test('non-BMP characters count as two UTF-16 units (surrogate pair)', function () use ($len) {
	Assert::same(2, $len('🎭'));        // U+1F3AD
	Assert::same(4, $len('🎭🎨'));
	Assert::same(3, $len('a🎭'));       // 1 + 2
	Assert::same(3, $len('👤 '));       // 👤 (2) + space (1)
});
