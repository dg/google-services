<?php declare(strict_types=1);

use DG\Google\Slides\Manager;
use Google\Service\Slides\PageElement;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// Google\Model maps nested arrays to typed DTOs in its constructor, so we can build realistic
// page elements without touching the API.
$shape = static fn(array $runs, ?string $placeholderType = null): array => [
	'shape' => array_filter([
		'placeholder' => $placeholderType !== null ? ['type' => $placeholderType] : null,
		'text' => ['textElements' => $runs],
	]),
];


test('shape text concatenates its runs', function () use ($shape) {
	$el = new PageElement(['objectId' => 'a'] + $shape([
		['textRun' => ['content' => 'Hello ']],
		['textRun' => ['content' => 'world']],
	]));
	Assert::same('Hello world', Manager::extractText($el));
});


test('auto-text counts as characters (so indexes stay aligned)', function () use ($shape) {
	$el = new PageElement(['objectId' => 'n'] + $shape([
		['autoText' => ['content' => '5']],
		['textRun' => ['content' => ' / 10']],
	]));
	Assert::same('5 / 10', Manager::extractText($el));
});


test('paragraph markers contribute nothing', function () use ($shape) {
	$el = new PageElement(['objectId' => 'p'] + $shape([
		['paragraphMarker' => []],
		['textRun' => ['content' => 'Body']],
	]));
	Assert::same('Body', Manager::extractText($el));
});


test('table cells are joined by " | " (rows by newline)', function () {
	$cell = static fn(string $t): array => ['text' => ['textElements' => [['textRun' => ['content' => $t]]]]];
	$el = new PageElement([
		'objectId' => 't',
		'table' => ['tableRows' => [
			['tableCells' => [$cell('A'), $cell('B')]],
			['tableCells' => [$cell('C'), $cell('D')]],
		]],
	]);
	Assert::same("A | B\nC | D", Manager::extractText($el));
});


test('walkElements flattens groups into leaf elements', function () use ($shape) {
	$leaf = static fn(string $id): array => ['objectId' => $id] + $shape([['textRun' => ['content' => $id]]]);
	$elements = [
		new PageElement($leaf('top')),
		new PageElement(['objectId' => 'g', 'elementGroup' => ['children' => [
			$leaf('child1'),
			['objectId' => 'inner', 'elementGroup' => ['children' => [$leaf('child2')]]],
		]]]),
	];

	$ids = array_map(static fn(PageElement $e) => $e->getObjectId(), Manager::walkElements($elements));
	Assert::same(['top', 'child1', 'child2'], $ids);
});
