<?php declare(strict_types=1);

use DG\Google\Gmail\Manager;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$build = Manager::createMessage(...);


test('plain ASCII draft has correct headers and body', function () use ($build) {
	$mail = $build(['to@example.com'], 'Hello world', 'Body text', [], [], []);
	assert($mail instanceof Nette\Mail\Message);
	$raw = $mail->generateMessage();

	Assert::contains('To: to@example.com', $raw);
	Assert::contains('Subject: Hello world', $raw);
	Assert::contains('Body text', $raw);
	Assert::contains('MIME-Version: 1.0', $raw);
});


test('multiple To/Cc/Bcc recipients land in the right headers', function () use ($build) {
	$mail = $build(
		['a@example.com', 'b@example.com'],
		'Subj',
		'Body',
		['c@example.com'],
		['d@example.com'],
		[],
	);
	assert($mail instanceof Nette\Mail\Message);
	$raw = $mail->generateMessage();

	// Nette joins multi-value address headers with a bare comma (no space)
	Assert::contains('To: a@example.com,b@example.com', $raw);
	Assert::contains('Cc: c@example.com', $raw);
	// Bcc IS emitted by Nette\Mail::generateMessage(); Gmail's API strips it before delivery.
	Assert::contains('Bcc: d@example.com', $raw);
});


test('empty to[] is rejected', function () use ($build) {
	Assert::exception(
		fn() => $build([], 'Subj', 'Body', [], [], []),
		DG\Google\Gmail\Exception::class,
		'At least one "to" recipient is required.',
	);
});


test('UTF-8 subject is RFC 2047 encoded, not literal in output', function () use ($build) {
	$mail = $build(['to@example.com'], 'Žluťoučký kůň', 'Body', [], [], []);
	assert($mail instanceof Nette\Mail\Message);
	$raw = $mail->generateMessage();

	Assert::contains('=?UTF-8?B?', $raw);
	Assert::notContains('Žluťoučký', $raw);
});


test('CRLF in subject is sanitized so no Bcc header is injected', function () use ($build) {
	$mail = $build(['to@example.com'], "Hi\r\nBcc: attacker@evil.cz", 'Body', [], [], []);
	assert($mail instanceof Nette\Mail\Message);
	$raw = $mail->generateMessage();

	// CR/LF were collapsed to a single space inside the Subject value
	Assert::contains('Subject: Hi Bcc: attacker@evil.cz', $raw);
	// no real Bcc header was created (no line starts with "Bcc:")
	Assert::false((bool) preg_match('/^Bcc:/m', $raw));
});


test('attachment with quote in filename is escaped, body present', function () use ($build) {
	$tmp = tempnam(sys_get_temp_dir(), 'att');
	file_put_contents($tmp, 'attachment-content');
	try {
		$mail = $build(['to@example.com'], 'Subj', 'Body', [], [], [
			['filename' => 'we"ird.txt', 'path' => $tmp],
		]);
		assert($mail instanceof Nette\Mail\Message);
		$raw = $mail->generateMessage();

		Assert::contains('Content-Disposition: attachment;', $raw);
		Assert::contains('filename="we\"ird.txt"', $raw);
		Assert::contains(base64_encode('attachment-content'), $raw);
	} finally {
		unlink($tmp);
	}
});


test('invalid recipient email is rejected by Nette validators', function () use ($build) {
	Assert::exception(
		fn() => $build(['not-an-email'], 'Subj', 'Body', [], [], []),
		Nette\Utils\AssertionException::class,
	);
});


test('missing attachment file throws Gmail\Exception (caught by safe())', function () use ($build) {
	// buildMessage pre-checks the file via filesize() so an oversize file can't be slurped
	// into RAM before the cap throws; the missing-file branch reports through Gmail\Exception.
	Assert::exception(
		fn() => $build(['to@example.com'], 'Subj', 'Body', [], [], [
			['filename' => 'x.txt', 'path' => '/no/such/file/here.txt'],
		]),
		DG\Google\Gmail\Exception::class,
		'%A%not a readable file%A%',
	);
});
