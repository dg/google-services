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
	// an empty BODY placeholder, as on a freshly added slide — must stay addressable
	[
		'objectId' => 'emptybody',
		'shape' => ['placeholder' => ['type' => 'BODY'], 'text' => ['textElements' => []]],
	],
	// an empty non-placeholder decoration — must be dropped
	['objectId' => 'empty', 'shape' => ['text' => ['textElements' => []]]],
]]);


test('full mode keeps text, placeholderType, and empty placeholders; drops empty decorations', function () use ($elements, $page) {
	Assert::same([
		['objectId' => 'title', 'isTitle' => true, 'placeholderType' => 'TITLE', 'text' => 'My title'],
		['objectId' => 'body', 'isTitle' => false, 'text' => 'Body text'],
		['objectId' => 'emptybody', 'isTitle' => false, 'placeholderType' => 'BODY', 'text' => ''],
	], $elements($page, false));
});


test('outline mode omits text but keeps object IDs, isTitle and placeholderType', function () use ($elements, $page) {
	Assert::same([
		['objectId' => 'title', 'isTitle' => true, 'placeholderType' => 'TITLE'],
		['objectId' => 'body', 'isTitle' => false],
		['objectId' => 'emptybody', 'isTitle' => false, 'placeholderType' => 'BODY'],
	], $elements($page, true));
});
