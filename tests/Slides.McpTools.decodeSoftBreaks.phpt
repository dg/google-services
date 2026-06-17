<?php declare(strict_types=1);

use DG\Google\Slides\McpTools;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// A raw U+000B (vertical-tab soft line break) does not survive the MCP host->server transport, so
// the model sends the two-char ASCII "\v" and the write tools expand it here. \\v escapes back to a
// literal "\v"; \n / \t and a lone \\ must pass through untouched (they survive the transport, and
// code samples on the slides legitimately contain them).
$decode = (new ReflectionMethod(McpTools::class, 'decodeSoftBreaks'))
	->getClosure();

$VT = "\x0b";


test('\v becomes U+000B', function () use ($decode, $VT) {
	Assert::same("- a{$VT}- b{$VT}- c", $decode('- a\v- b\v- c'));
});


test('\v escapes to a literal \v (no expansion)', function () use ($decode) {
	Assert::same('- a\v- b', $decode('- a\\\v- b'));
});


test('\n and \t pass through unchanged', function () use ($decode) {
	Assert::same('line\ntab\tend', $decode('line\ntab\tend'));
});


test('a lone backslash and \ pairs are untouched', function () use ($decode) {
	Assert::same('C:\path\to', $decode('C:\path\to'));
	Assert::same('regex \d+ and \\', $decode('regex \d+ and \\'));
});


test('text without backslashes is returned verbatim', function () use ($decode) {
	Assert::same('plain text 🎭', $decode('plain text 🎭'));
	Assert::same('', $decode(''));
});


test('mixed: real soft break next to an escaped one', function () use ($decode, $VT) {
	// "a\vb" -> soft break; "c\\vd" -> literal \v
	Assert::same("a{$VT}b and c\\vd", $decode('a\vb and c\\\vd'));
});
