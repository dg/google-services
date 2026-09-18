<?php declare(strict_types=1);

use DG\Google\ToolSelection;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$all = ToolSelection::discover();

$select = function (string $rules) use ($all): array {
	$names = array_keys(ToolSelection::select($rules, $all));
	sort($names);
	return $names;
};


test('service without a level means write, never send', function () use ($select) {
	$names = $select('gmail');
	Assert::contains('gmail_create_draft', $names);
	Assert::notContains('gmail_send_draft', $names);
	Assert::notContains('gmail_send_reply', $names);
	Assert::notContains('slides_get_presentation', $names);
});


test('service with a level', function () use ($select) {
	Assert::same(['calendar_list_calendars', 'calendar_list_events'], $select('calendar:read'));
	Assert::same(['slides_get_presentation', 'slides_get_text_styles'], $select('slides:read'));
	Assert::contains('gmail_send_reply', $select('gmail:send'));
	Assert::contains('calendar_add_attendees', $select('calendar:send'));
	Assert::notContains('calendar_add_attendees', $select('calendar:write'));
});


test('default', function () use ($select) {
	$names = $select('default');
	Assert::same($select(ToolSelection::Default), $names);
	Assert::contains('gmail_create_draft', $names);
	Assert::contains('calendar_list_events', $names);
	Assert::notContains('calendar_create_event', $names);
	Assert::contains('slides_set_shape_text', $names);
	Assert::notContains('gmail_send_draft', $names);
});


test('the selection keeps the catalog order, not the order of the rules', function () use ($all) {
	$names = array_keys(ToolSelection::select('slides_move_slide, slides_add_slide', $all));
	Assert::same(array_keys(ToolSelection::select('slides_add_slide, slides_move_slide', $all)), $names);
	Assert::same(['slides_add_slide', 'slides_move_slide'], $names);
});


test('single tool, including a send-level one named explicitly', function () use ($select) {
	Assert::same(['gmail_send_draft'], $select('gmail_send_draft'));
	$expected = [...$select('gmail:read'), 'gmail_create_draft'];
	sort($expected);
	Assert::same($expected, $select('gmail:read, gmail_create_draft'));
});


test('pattern never adds a send-level tool', function () use ($select) {
	Assert::same(['gmail_create_draft', 'gmail_delete_draft', 'gmail_update_draft'], $select('gmail_*_draft'));
	Assert::exception(
		fn() => $select('gmail_send_*'),
		InvalidArgumentException::class,
		"GOOGLE_TOOLS: pattern 'gmail_send_*' matches no tool (send-level tools must be named explicitly).",
	);
});


test('removal', function () use ($select) {
	Assert::notContains('slides_delete_object', $select('slides, -slides_delete_object'));
	Assert::notContains('gmail_trash_thread', $select('default, -gmail_trash_thread'));
	Assert::same([], array_filter($select('gmail:send, -gmail_send_*'), fn($name) => str_starts_with($name, 'gmail_send')));
	Assert::same($select('slides'), $select('default, -gmail, -calendar'));
});


test('rules are applied left to right', function () use ($select) {
	Assert::contains('slides_delete_object', $select('-slides_delete_object, slides'));
	Assert::same(['slides_get_presentation'], $select('slides_get_presentation, slides_get_presentation'));
});


test('separators are lenient', function () use ($select) {
	Assert::same($select('gmail:read,slides'), $select(" gmail:read ,\n slides, "));
});


test('invalid rules', function () use ($select) {
	Assert::exception(fn() => $select('-gmail_trash_thread'), InvalidArgumentException::class, '%A%no rule that enables a tool%A%');
	Assert::exception(fn() => $select(''), InvalidArgumentException::class, '%A%no rule that enables a tool%A%');
	Assert::exception(fn() => $select('gmial'), InvalidArgumentException::class, "GOOGLE_TOOLS: unknown service 'gmial', expected one of: %a%.");
	Assert::exception(fn() => $select('gmail:all'), InvalidArgumentException::class, "GOOGLE_TOOLS: unknown level 'all' in 'gmail:all', expected read, write or send.");
	Assert::exception(fn() => $select('gmail_send_raply'), InvalidArgumentException::class, "GOOGLE_TOOLS: unknown tool 'gmail_send_raply'.");
	Assert::exception(fn() => $select('slides, -gmail_send_raply'), InvalidArgumentException::class, "GOOGLE_TOOLS: unknown tool 'gmail_send_raply'.");
	Assert::exception(fn() => $select('slides, -slides_x*'), InvalidArgumentException::class, "GOOGLE_TOOLS: pattern 'slides_x*' matches no tool.");
	Assert::exception(fn() => $select('default, -gmail:send'), InvalidArgumentException::class, '%A%ambiguous%A%');
	Assert::exception(fn() => $select('slides, -default'), InvalidArgumentException::class, "GOOGLE_TOOLS: '-default' is not supported%a%");
});


test('a tool without the #[Access] attribute is refused', function () use ($all) {
	$tool = new Mcp\Capability\Registry\ToolReference(reset($all)->tool, [DG\Google\ToolSelection::class, 'discover']);
	Assert::exception(fn() => ToolSelection::getLevel($tool), LogicException::class, '%A%missing the #[Access] attribute%A%');
});


test('services, status and disabled description', function () use ($all) {
	$selected = ToolSelection::select('gmail:read, slides', $all);
	Assert::same(['gmail', 'slides'], ToolSelection::getServices($selected));
	Assert::match('calendar off, gmail 6/20 tools, slides 12/12 tools', ToolSelection::describeStatus($all, $selected));
	Assert::match('calendar (whole service), gmail_%a%', ToolSelection::describeDisabled($all, $selected));
	Assert::same('', ToolSelection::describeDisabled($all, $all));
});
