<?php declare(strict_types=1);

use DG\Google\Slides\McpTools;
use Google\Service\Slides\Page;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// elements() shapes the per-slide element list for slides_get_presentation. In outline mode it
// must drop the `text` field (so a large deck doesn't blow the context window) while keeping the
// object IDs and isTitle flag.
$elements = (new ReflectionMethod(McpTools::class, 'elements'))->getClosure();

$page = new Page(['pageElements' => [
	['objectId' => 'title', 'shape' => [
		'placeholder' => ['type' => 'TITLE'],
		'text' => ['textElements' => [['textRun' => ['content' => 'My title']]]],
	]],
	['objectId' => 'body', 'shape' => [
		'text' => ['textElements' => [['textRun' => ['content' => 'Body text']]]],
	]],
	['objectId' => 'empty', 'shape' => ['text' => ['textElements' => []]]],
]]);


test('full mode returns object IDs, isTitle and text; empty shapes are skipped', function () use ($elements, $page) {
	Assert::same([
		['objectId' => 'title', 'isTitle' => true, 'text' => 'My title'],
		['objectId' => 'body', 'isTitle' => false, 'text' => 'Body text'],
	], $elements($page, false));
});


test('outline mode omits the text field but keeps object IDs and isTitle', function () use ($elements, $page) {
	Assert::same([
		['objectId' => 'title', 'isTitle' => true],
		['objectId' => 'body', 'isTitle' => false],
	], $elements($page, true));
});
