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
