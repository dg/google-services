<?php declare(strict_types=1);

use DG\Google\Gmail\McpTools;
use Mcp\Exception\ToolCallException;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


/** @return array{0: McpTools, 1: string} [tools, sandbox dir] */
function makeToolsWithSandbox(): array
{
	$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gs-sandbox-' . bin2hex(random_bytes(4));
	mkdir($dir);
	$tools = new McpTools(static fn() => throw new RuntimeException('factory must not run'), allowSend: false, filesDir: $dir);
	return [$tools, realpath($dir)];
}


function callValidateAttachments(McpTools $tools, array $attachments): mixed
{
	return (new ReflectionMethod(McpTools::class, 'validateAttachments'))->invoke($tools, $attachments);
}


test('constructor refuses a non-existent files dir', function () {
	Assert::exception(
		fn() => new McpTools(static fn() => throw new RuntimeException, false, '/no/such/dir/ever'),
		InvalidArgumentException::class,
		'%A%does not point to an existing directory%A%',
	);
});


test('without sandbox, attachments[] uploads are rejected', function () {
	$tools = new McpTools(static fn() => throw new RuntimeException);
	Assert::exception(
		fn() => callValidateAttachments($tools, [['filename' => 'x.pdf', 'path' => 'x.pdf']]),
		ToolCallException::class,
		'%A%GOOGLE_FILES_DIR%A%',
	);
});


test('without sandbox, gmail_get_attachment refuses', function () {
	$tools = new McpTools(static fn() => throw new RuntimeException);
	Assert::exception(
		fn() => $tools->getAttachment('msg', 'att'),
		ToolCallException::class,
		'%A%GOOGLE_FILES_DIR%A%',
	);
});


test('paths must be plain filenames (no separators, no `.`/`..`, no null byte)', function () {
	[$tools] = makeToolsWithSandbox();
	$bad = [
		'',                              // empty
		'.',                             // current dir
		'..',                            // path traversal
		'/etc/passwd',                   // unix root (`/`)
		'C:/Windows/file.ini',           // drive + `/`
		'C:\Windows\file.ini',         // drive + `\`
		'\\\server\share\x',          // UNC (`\`)
		'file:///etc/passwd',            // protocol (`/`)
		'../escape.txt',                 // leading `..` (also `/`)
		'sub/foo.txt',                   // subdir (`/`)
		'sub\foo.txt',                  // subdir (`\`)
		"foo\0bar.txt",                  // null byte
	];
	foreach ($bad as $path) {
		Assert::exception(
			fn() => callValidateAttachments($tools, [['filename' => 'x', 'path' => $path]]),
			ToolCallException::class,
			'%A%plain filename under GOOGLE_FILES_DIR%A%',
		);
	}
});


test('non-existent file inside sandbox is rejected', function () {
	[$tools] = makeToolsWithSandbox();
	Assert::exception(
		fn() => callValidateAttachments($tools, [['filename' => 'x', 'path' => 'missing.pdf']]),
		ToolCallException::class,
		'%A%not found in sandbox%A%',
	);
});


test('happy path: existing file inside sandbox resolves to absolute canonical path', function () {
	[$tools, $dir] = makeToolsWithSandbox();
	file_put_contents("$dir/report.pdf", 'pdf-bytes');
	$resolved = callValidateAttachments($tools, [['filename' => 'Q1.pdf', 'path' => 'report.pdf']]);
	Assert::same([['filename' => 'Q1.pdf', 'path' => realpath("$dir/report.pdf")]], $resolved);
});


test('symlink whose target is outside the sandbox is rejected', function () {
	if (DIRECTORY_SEPARATOR === '\\') {
		Tester\Environment::skip('symlink behaviour differs on Windows');
	}
	[$tools, $dir] = makeToolsWithSandbox();
	$outside = sys_get_temp_dir() . '/gs-outside-' . bin2hex(random_bytes(4));
	file_put_contents($outside, 'secret');
	symlink($outside, "$dir/link.txt");

	Assert::exception(
		fn() => callValidateAttachments($tools, [['filename' => 'x', 'path' => 'link.txt']]),
		ToolCallException::class,
		'%A%resolves outside the sandbox%A%',
	);
	unlink($outside);
});


test('generateAttachmentFilename produces gm-<hash>.<ext> deterministically', function () {
	$gen = (new ReflectionMethod(McpTools::class, 'generateAttachmentFilename'))->getClosure();

	// PDF magic bytes
	$pdfBytes = "%PDF-1.7\n...";
	$name1 = $gen('msgA', 'attA', $pdfBytes);
	$name2 = $gen('msgA', 'attA', $pdfBytes);
	Assert::same($name1, $name2); // deterministic
	Assert::match('#^gm-[0-9a-f]{12}\.pdf$#', $name1);

	// Different (messageId, attachmentId) yields a different hash
	$name3 = $gen('msgA', 'attB', $pdfBytes);
	Assert::notSame($name1, $name3);

	// Unknown content falls back to .bin
	$nameBin = $gen('m', 'a', "\x00\x01\x02random-junk\xff\xff");
	Assert::match('#^gm-[0-9a-f]{12}\.bin$#', $nameBin);
});
