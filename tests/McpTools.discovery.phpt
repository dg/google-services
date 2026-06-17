<?php declare(strict_types=1);

use DG\Google\Gmail\McpTools;
use Mcp\Capability\Discovery\Discoverer;
use Mcp\Exception\ToolCallException;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$state = (new Discoverer)->discover(__DIR__ . '/..', ['src']);
$tools = $state->getTools();

$names = array_keys($tools);
sort($names);

Assert::same([
	'calendar_list_calendars',
	'calendar_list_events',
	'gmail_archive_thread',
	'gmail_create_draft',
	'gmail_create_draft_reply',
	'gmail_delete_draft',
	'gmail_get_attachment',
	'gmail_get_thread',
	'gmail_label_message',
	'gmail_label_thread',
	'gmail_list_attachments',
	'gmail_list_drafts',
	'gmail_list_labels',
	'gmail_search_threads',
	'gmail_send_draft',
	'gmail_send_reply',
	'gmail_unlabel_message',
	'gmail_unlabel_thread',
	'slides_add_slide',
	'slides_delete_object',
	'slides_duplicate_slide',
	'slides_format_text',
	'slides_get_presentation',
	'slides_get_text_styles',
	'slides_insert_text',
	'slides_replace_all_text',
	'slides_set_shape_text',
], $names);


$annotations = [];
foreach ($tools as $name => $ref) {
	$a = $ref->tool->annotations?->jsonSerialize() ?? [];
	$annotations[$name] = [
		'readOnlyHint' => $a['readOnlyHint'] ?? null,
		'destructiveHint' => $a['destructiveHint'] ?? null,
		'idempotentHint' => $a['idempotentHint'] ?? null,
	];
}


// every tool must carry a human-readable title (Anthropic Directory review criteria)
foreach ($tools as $name => $ref) {
	Assert::truthy($ref->tool->title, "tool $name is missing a title");
}

Assert::same([
	'readOnlyHint' => true,
	'destructiveHint' => null,
	'idempotentHint' => null,
], $annotations['calendar_list_events']);

Assert::same([
	'readOnlyHint' => true,
	'destructiveHint' => null,
	'idempotentHint' => null,
], $annotations['calendar_list_calendars']);

Assert::same([
	'readOnlyHint' => true,
	'destructiveHint' => null,
	'idempotentHint' => null,
], $annotations['gmail_search_threads']);

Assert::same([
	'readOnlyHint' => true,
	'destructiveHint' => null,
	'idempotentHint' => null,
], $annotations['gmail_get_thread']);

Assert::same([
	'readOnlyHint' => false,
	'destructiveHint' => false,
	'idempotentHint' => null,
], $annotations['gmail_create_draft_reply']);

Assert::same([
	'readOnlyHint' => false,
	'destructiveHint' => true,
	'idempotentHint' => false,
], $annotations['gmail_send_reply']);

Assert::same([
	'readOnlyHint' => false,
	'destructiveHint' => true,
	'idempotentHint' => true,
], $annotations['gmail_archive_thread']);

Assert::same([
	'readOnlyHint' => false,
	'destructiveHint' => true,
	'idempotentHint' => true,
], $annotations['gmail_delete_draft']);


// attachments parameter is a typed array of objects, not a JSON string
$schema = $tools['gmail_create_draft']->tool->inputSchema;
Assert::same('array', $schema['properties']['attachments']['type']);
Assert::same('object', $schema['properties']['attachments']['items']['type']);
Assert::same(['filename', 'path'], $schema['properties']['attachments']['items']['required']);


// gmail_create_draft now accepts list<string> for to/cc/bcc with at least one to-recipient
Assert::same('array', $schema['properties']['to']['type']);
Assert::same('string', $schema['properties']['to']['items']['type']);
Assert::same(1, $schema['properties']['to']['minItems']);
Assert::same('array', $schema['properties']['cc']['type']);
Assert::same('array', $schema['properties']['bcc']['type']);
Assert::contains('to', $schema['required']);
Assert::notContains('cc', $schema['required']);
Assert::notContains('bcc', $schema['required']);


// gmail_get_thread exposes maxMessages with sane bounds
$schema = $tools['gmail_get_thread']->tool->inputSchema;
Assert::same('integer', $schema['properties']['maxMessages']['type']);
Assert::same(1, $schema['properties']['maxMessages']['minimum']);
Assert::same(200, $schema['properties']['maxMessages']['maximum']);


// gmail_get_attachment is now idempotent: deterministic auto-generated filename in the
// sandbox, so re-calling with the same messageId+attachmentId produces the same savedPath.
Assert::same([
	'readOnlyHint' => false,
	'destructiveHint' => false,
	'idempotentHint' => true,
], $annotations['gmail_get_attachment']);


// gmail_get_attachment takes only messageId + attachmentId; savePath/overwrite are gone.
$schema = $tools['gmail_get_attachment']->tool->inputSchema;
Assert::same(['messageId', 'attachmentId'], array_keys($schema['properties']));
Assert::same(['messageId', 'attachmentId'], $schema['required']);


// Outbound send is gated by allowSend: with the default off the call fails fast,
// independently of whether a Manager is reachable.
$disabled = new McpTools(static fn() => throw new RuntimeException('factory must not run when send is disabled'));
Assert::exception(
	fn() => $disabled->sendDraft('any-id'),
	ToolCallException::class,
	'%A%GOOGLE_ALLOW_SEND=1%A%',
);
Assert::exception(
	fn() => $disabled->sendReply('any-id', 'body'),
	ToolCallException::class,
	'%A%GOOGLE_ALLOW_SEND=1%A%',
);


Assert::same([
	'readOnlyHint' => true,
	'destructiveHint' => null,
	'idempotentHint' => null,
], $annotations['slides_get_presentation']);

Assert::same([
	'readOnlyHint' => true,
	'destructiveHint' => null,
	'idempotentHint' => null,
], $annotations['slides_get_text_styles']);

Assert::same([
	'readOnlyHint' => false,
	'destructiveHint' => false,
	'idempotentHint' => null,
], $annotations['slides_add_slide']);

Assert::same([
	'readOnlyHint' => false,
	'destructiveHint' => true,
	'idempotentHint' => true,
], $annotations['slides_delete_object']);

Assert::same([
	'readOnlyHint' => false,
	'destructiveHint' => true,
	'idempotentHint' => null,
], $annotations['slides_replace_all_text']);

Assert::same([
	'readOnlyHint' => false,
	'destructiveHint' => true,
	'idempotentHint' => true,
], $annotations['slides_set_shape_text']);

Assert::same([
	'readOnlyHint' => false,
	'destructiveHint' => false,
	'idempotentHint' => true,
], $annotations['slides_format_text']);


// slides_add_slide constrains layout to the predefined set
$schema = $tools['slides_add_slide']->tool->inputSchema;
Assert::same(DG\Google\Slides\Manager::Layouts, $schema['properties']['layout']['enum']);


// Discovery only registers tools, it never invokes them — so a tool whose body calls a
// non-existent Manager method passes discovery but crashes on first call. Guard the backing
// methods of the read-only calendar tools, which previously referenced undefined methods.
Assert::true(method_exists(DG\Google\Calendar\Manager::class, 'getEvents'));
Assert::true(method_exists(DG\Google\Calendar\Manager::class, 'getCalendars'));
Assert::true(method_exists(DG\Google\Slides\Manager::class, 'getPresentation'));
Assert::true(method_exists(DG\Google\Slides\Manager::class, 'getElementRuns'));
Assert::true(method_exists(DG\Google\Slides\Manager::class, 'addSlide'));
Assert::true(method_exists(DG\Google\Slides\Manager::class, 'duplicateSlide'));
Assert::true(method_exists(DG\Google\Slides\Manager::class, 'deleteObject'));
Assert::true(method_exists(DG\Google\Slides\Manager::class, 'insertText'));
Assert::true(method_exists(DG\Google\Slides\Manager::class, 'replaceAllText'));
Assert::true(method_exists(DG\Google\Slides\Manager::class, 'setShapeText'));
Assert::true(method_exists(DG\Google\Slides\Manager::class, 'styleText'));

// The read field mask must fetch the slide-level objectId (needed to target slides for
// slides_duplicate_slide / slides_delete_object) — a refactor once dropped it, so
// slides_get_presentation returned empty slide IDs.
Assert::contains('slides(objectId', DG\Google\Slides\Manager::ContentFields);
