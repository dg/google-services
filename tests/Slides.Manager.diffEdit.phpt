<?php declare(strict_types=1);

use DG\Google\Slides\Manager;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// diffEdit backs setShapeText: instead of clearing a shape and re-typing (which resets every run
// to the default style, the original formatting-loss bug), it rewrites only the differing middle
// so the common prefix/suffix keep their styling. It returns the delete range in UTF-16 code units
// (matching the Slides API) plus the substring to insert. Inputs already have the trailing newline
// stripped (see stripTrailingNewline below).
$diff = (new ReflectionMethod(Manager::class, 'diffEdit'))
	->getClosure();

$strip = (new ReflectionMethod(Manager::class, 'stripTrailingNewline'))
	->getClosure();


test('middle change keeps common prefix and suffix', function () use ($diff) {
	// "ABCDEF" -> "ABXYEF": prefix "AB", suffix "EF", rewrite the "CD"->"XY" middle
	Assert::same([2, 4, 'XY'], $diff('ABCDEF', 'ABXYEF'));
});


test('identical text is a no-op (empty delete, empty insert)', function () use ($diff) {
	Assert::same([3, 3, ''], $diff('ABC', 'ABC'));
});


test('empty existing is a clean insert', function () use ($diff) {
	Assert::same([0, 0, 'Hello'], $diff('', 'Hello'));
});


test('empty new clears the whole body', function () use ($diff) {
	Assert::same([0, 5, ''], $diff('Hello', ''));
});


test('append: change is a pure suffix insert', function () use ($diff) {
	Assert::same([2, 2, 'CD'], $diff('AB', 'ABCD'));
});


test('prepend: change is a pure prefix insert', function () use ($diff) {
	Assert::same([0, 0, 'AB'], $diff('CD', 'ABCD'));
});


test('prefix does not overshoot into the shared suffix', function () use ($diff) {
	// "aaa" -> "aa": delete exactly one 'a', do not over-match
	Assert::same([2, 3, ''], $diff('aaa', 'aa'));
});


test('delete range is in UTF-16 units past a leading non-BMP emoji', function () use ($diff) {
	// "🎭ABC" -> "🎭XYZ": 🎭 (U+1F3AD) = 2 UTF-16 units, so the middle starts at index 2
	Assert::same([2, 5, 'XYZ'], $diff('🎭ABC', '🎭XYZ'));
});


test('inserted text containing a non-BMP emoji is returned verbatim', function () use ($diff) {
	// prefix "a", then "b" -> "🎭" in the middle, suffix "c"
	Assert::same([1, 2, '🎭'], $diff('abc', 'a🎭c'));
});


test('stripTrailingNewline removes exactly one trailing newline', function () use ($strip) {
	Assert::same('abc', $strip("abc\n"));
	Assert::same('abc', $strip('abc'));
	Assert::same('', $strip(''));
	Assert::same("abc\n", $strip("abc\n\n"));
	Assert::same('', $strip("\n"));
	Assert::same("a\nb", $strip("a\nb"));
});


// setShapeText diffs the body after stripTrailingNewline. The key invariant it relies on: the
// delete range never reaches past the deletable body, because the Slides API refuses to delete the
// shape's final newline ("end index should not be greater than the existing text length"). This
// exercises the strip+diff combination the way setShapeText composes it (getElementText returns the
// existing text WITH the trailing newline; the new text usually comes without one).
test('delete range stays within the deletable body for realistic edits', function () use ($diff, $strip) {
	$utf16Len = static fn(string $s): int => array_sum(array_map(
		static fn(string $ch): int => mb_ord($ch, 'UTF-8') > 0xFFFF ? 2 : 1,
		preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [],
	));
	$cases = [
		["staré\n", 'nové'],                               // existing has the API's trailing newline
		["- a\x0b- b\x0b- c\n", "- x\x0b- y"],             // soft breaks, fewer lines
		["ABCDEF\n", ''],                                  // clear
		["\n", 'x'],                                       // effectively-empty shape (only trailing newline)
		["🎭ABC\n", '🎭X'],                                 // non-BMP prefix
		["multi\npara\n", 'multi'],                        // delete an internal newline too
	];
	foreach ($cases as [$existing, $new]) {
		$body = $strip($existing);
		[$start, $end, $insert] = $diff($body, $strip($new));
		Assert::true($start >= 0 && $start <= $end, 'start in [0, end]');
		Assert::true($end <= $utf16Len($body), 'delete end within the deletable body');
	}
});
