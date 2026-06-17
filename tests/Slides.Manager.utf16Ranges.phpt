<?php declare(strict_types=1);

use DG\Google\Slides\Manager;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// The Slides API addresses text by UTF-16 code units: a non-BMP character (most emoji) is a
// surrogate pair counting as 2, a variation selector adds another. utf16Ranges is what lets
// styleText() accept a literal substring instead of hand-counted indexes (the calc_bold.js job).
$ranges = (new ReflectionMethod(Manager::class, 'utf16Ranges'))
	->getClosure();


test('plain ASCII substring', function () use ($ranges) {
	Assert::same([[6, 11]], $ranges('hello world', 'world'));
});


test('substring not found', function () use ($ranges) {
	Assert::same([], $ranges('abc', 'z'));
});


test('accented (BMP) characters count as one code unit each', function () use ($ranges) {
	// "x " = 2 units, "příkaz" = 6 units
	Assert::same([[2, 8]], $ranges('x příkaz', 'příkaz'));
});


test('BMP emoji counts as one code unit', function () use ($ranges) {
	// ⚡ (U+26A1) = 1, space = 1 → "test" starts at 2
	Assert::same([[2, 6]], $ranges('⚡ test', 'test'));
});


test('non-BMP emoji counts as two code units', function () use ($ranges) {
	// 🎭 (U+1F3AD) = 2, space = 1 → "AI" starts at 3
	Assert::same([[3, 5]], $ranges('🎭 AI', 'AI'));
});


test('emoji with variation selector counts as three code units', function () use ($ranges) {
	// 🛠️ = U+1F6E0 (2) + U+FE0F (1) = 3, space = 1 → "X" starts at 4
	Assert::same([[4, 5]], $ranges('🛠️ X', 'X'));
});


test('every occurrence is returned, non-overlapping', function () use ($ranges) {
	Assert::same([[0, 1], [4, 5]], $ranges('a x a x', 'a'));
});


test('range of a substring that itself contains a non-BMP emoji', function () use ($ranges) {
	// needle "🎭 AI" = 2 + 1 + 2 = 5 units, found at start 0
	Assert::same([[0, 5]], $ranges('🎭 AI nerozliší', '🎭 AI'));
});
