<?php declare(strict_types=1);

use DG\Google\Slides\Manager;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// setShapeRequests is the pure, network-free core of setShapeText: it turns the shape's existing
// text into the new text and applies the styles list, returning [requests, report]. These tests
// pin two things that bit us in production:
//   1) a shape ending in a blank paragraph must NOT collapse the edit into the whole tail (the bug
//      where a single bold word before the edit bled bold to the end of the shape), and
//   2) the styles list must neutralize the accents the rewritten run would inherit, then apply only
//      the listed styles — deterministically.
$plan = (new ReflectionMethod(Manager::class, 'setShapeRequests'))
	->getClosure();


/**
 * Flattens a list of Slides API Request objects into readable assertions-friendly arrays.
 *
 * @param  list<Google\Service\Slides\Request>  $requests
 * @return list<array<string, mixed>>
 */
function describeRequests(array $requests): array
{
	$ops = [];
	foreach ($requests as $request) {
		if (($d = $request->getDeleteText()) !== null) {
			$range = $d->getTextRange();
			$ops[] = ['op' => 'delete', 'start' => $range->getStartIndex(), 'end' => $range->getEndIndex()];
		} elseif (($i = $request->getInsertText()) !== null) {
			$ops[] = ['op' => 'insert', 'at' => $i->getInsertionIndex(), 'text' => $i->getText()];
		} elseif (($s = $request->getUpdateTextStyle()) !== null) {
			$range = $s->getTextRange();
			$style = $s->getStyle();
			$ops[] = [
				'op' => 'style',
				'start' => $range->getStartIndex(),
				'end' => $range->getEndIndex(),
				'fields' => $s->getFields(),
				'bold' => $style->getBold(),
				'italic' => $style->getItalic(),
				'underline' => $style->getUnderline(),
			];
		}
	}
	return $ops;
}


test('trailing blank paragraph does not collapse the edit into the whole tail', function () use ($plan) {
	// getElementText returns the existing text WITH the API's final newline; here the shape also has a
	// blank last paragraph, so it ends in "\n\n". The new text drops it. The old prefix/suffix diff saw
	// the last characters differ ("\n" vs "o"), zeroed the common suffix and rewrote everything from the
	// change to the end — that is the bold-bleed bug. The fix pulls trailing newlines out first.
	[$requests, $report] = $plan('OBJ', "Hello world\nfoo\n\n", "Hello WORLD\nfoo", []);
	$ops = describeRequests($requests);

	// The core text rewrite stays local: it inserts exactly "WORLD" and never re-inserts the tail "foo".
	$inserts = array_values(array_filter($ops, fn($o) => $o['op'] === 'insert'));
	foreach ($inserts as $insert) {
		Assert::notContains('foo', $insert['text']);
	}
	Assert::same('WORLD', end($inserts)['text']);
	// The extra blank-paragraph newline is removed by its own tiny delete at the very end (coreLen 15).
	Assert::contains(['op' => 'delete', 'start' => 15, 'end' => 16], $ops);
	Assert::same([], $report);
});


test('styles clears accents on the rewritten run, then applies only the listed styles', function () use ($plan) {
	[$requests, $report] = $plan('OBJ', "AAA old BBB\n", 'AAA the shiny BBB', [
		['substring' => 'shiny', 'bold' => true],
	]);
	$ops = describeRequests($requests);

	// core diff: prefix "AAA ", suffix " BBB"; delete "old" [4,7], insert "the shiny" → run [4,13]
	Assert::contains(['op' => 'insert', 'at' => 4, 'text' => 'the shiny'], $ops);
	// the rewritten run [4,13] gets bold/italic/underline explicitly cleared (no inherited accent)
	Assert::contains(
		['op' => 'style', 'start' => 4, 'end' => 13, 'fields' => 'bold,italic,underline', 'bold' => false, 'italic' => false, 'underline' => false],
		$ops,
	);
	// only "shiny" [8,13] is bolded; the rest of the rewritten run stays normal
	Assert::contains(
		['op' => 'style', 'start' => 8, 'end' => 13, 'fields' => 'bold', 'bold' => true, 'italic' => null, 'underline' => null],
		$ops,
	);
	Assert::same([['substring' => 'shiny', 'occurrences' => 1]], $report);
});


test('without styles no updateTextStyle is emitted (legacy inherit behavior preserved)', function () use ($plan) {
	[$requests, $report] = $plan('OBJ', "AAA old BBB\n", 'AAA new BBB', []);
	$styleOps = array_values(array_filter(describeRequests($requests), fn($o) => $o['op'] === 'style'));
	Assert::same([], $styleOps);
	Assert::same([], $report);
});


test('a styles substring that does not match reports 0 occurrences and styles nothing', function () use ($plan) {
	[$requests, $report] = $plan('OBJ', "Hello\n", 'Hello world', [
		['substring' => 'NOPE', 'bold' => true],
	]);
	Assert::same([['substring' => 'NOPE', 'occurrences' => 0]], $report);
	$bold = array_values(array_filter(describeRequests($requests), fn($o) => $o['op'] === 'style' && $o['bold'] === true));
	Assert::same([], $bold);
});


test('adding a trailing blank paragraph is a lone newline insert at the end', function () use ($plan) {
	[$requests, $report] = $plan('OBJ', "foo\n", "foo\n\n", []);
	Assert::same([['op' => 'insert', 'at' => 3, 'text' => "\n"]], describeRequests($requests));
	Assert::same([], $report);
});


test('a styles entry without a substring is rejected', function () use ($plan) {
	Assert::exception(
		fn() => $plan('OBJ', "x\n", 'y', [['bold' => true]]),
		InvalidArgumentException::class,
		'Each styles entry needs a non-empty "substring".',
	);
});
