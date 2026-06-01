<?php declare(strict_types=1);

use DG\Google\Gmail\Manager;
use DG\Google\Gmail\Message;
use DG\Google\Gmail\Recipient;
use DG\Google\Gmail\Thread;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


function makeManagerWithThread(string $myAddress, Thread $thread): Manager
{
	// anonymous subclass skips the Gmail client and returns the precanned thread
	$manager = new class ($thread) extends Manager {
		public function __construct(
			public Thread $stubThread,
		) {
		}


		public function fetchThread(string $threadId, string $format = 'full'): Thread
		{
			return $this->stubThread;
		}
	};
	(new ReflectionProperty(Manager::class, 'myAddress'))->setValue($manager, $myAddress);
	return $manager;
}


function makeThread(
	string $subject,
	?Recipient $sender = null,
	array $to = [],
	array $cc = [],
	array $replyTo = [],
	?string $messageId = null,
	?string $references = null,
): Thread
{
	$msg = new Message(
		id: 'm1',
		date: new DateTimeImmutable('2026-01-01T00:00:00Z'),
		sender: $sender ?? new Recipient('them@example.com', 'Them'),
		toRecipients: $to,
		ccRecipients: $cc,
		replyToRecipients: $replyTo,
		subject: $subject,
		snippet: '',
		plaintextBody: null,
		htmlBody: null,
		messageIdHeader: $messageId,
		referencesHeader: $references,
	);
	return new Thread('t1', [$msg]);
}


$build = (new ReflectionMethod(Manager::class, 'createReplyMessage'));


test('CRLF in incoming subject is sanitised on the reply', function () use ($build) {
	$thread = makeThread(
		subject: "Hi\r\nBcc: attacker@evil.cz",
		sender: new Recipient('them@example.com'),
	);
	$manager = makeManagerWithThread('me@example.com', $thread);
	$mail = $build->invoke($manager, 't1', 'reply body', []);
	\assert($mail instanceof Nette\Mail\Message);
	$raw = $mail->generateMessage();

	// "Re: " prefix added; CR/LF collapsed to a single space inside the Subject value
	Assert::contains('Subject: Re: Hi Bcc: attacker@evil.cz', $raw);
	// no real Bcc header was created (no line starts with "Bcc:")
	Assert::false((bool) preg_match('/^Bcc:/m', $raw));
	// no real Message-Id splice via References either
	Assert::false((bool) preg_match('/^References:/m', $raw));
});


test('Re: prefix is not duplicated on already-prefixed subjects', function () use ($build) {
	$thread = makeThread(subject: 'Re: Hello', sender: new Recipient('them@example.com'));
	$manager = makeManagerWithThread('me@example.com', $thread);
	$mail = $build->invoke($manager, 't1', 'body', []);
	$raw = $mail->generateMessage();

	Assert::contains('Subject: Re: Hello', $raw);
	Assert::notContains('Subject: Re: Re:', $raw);
});


test('reply prefers Reply-To over From, and excludes self from Cc', function () use ($build) {
	$thread = makeThread(
		subject: 'Hi',
		sender: new Recipient('original@example.com'),
		to: [new Recipient('me@example.com'), new Recipient('other@example.com')],
		cc: [new Recipient('cc@example.com')],
		replyTo: [new Recipient('reply-here@example.com')],
	);
	$manager = makeManagerWithThread('me@example.com', $thread);
	$mail = $build->invoke($manager, 't1', 'body', []);
	$raw = $mail->generateMessage();

	Assert::contains('To: reply-here@example.com', $raw);
	// own address removed from Cc; original From not promoted into recipients
	Assert::notContains('me@example.com', substr($raw, 0, strpos($raw, "\r\n\r\n") ?: strlen($raw)));
	Assert::contains('Cc: other@example.com,cc@example.com', $raw);
});


test('In-Reply-To and References headers are wired when available', function () use ($build) {
	$thread = makeThread(
		subject: 'Hi',
		sender: new Recipient('them@example.com'),
		messageId: '<abc@host>',
		references: '<earlier@host>',
	);
	$manager = makeManagerWithThread('me@example.com', $thread);
	$mail = $build->invoke($manager, 't1', 'body', []);
	$raw = $mail->generateMessage();

	Assert::contains('In-Reply-To: <abc@host>', $raw);
	Assert::contains('References: <earlier@host> <abc@host>', $raw);
});


test('empty thread is rejected with Gmail\Exception', function () use ($build) {
	$thread = new Thread('empty-id', []);
	$manager = makeManagerWithThread('me@example.com', $thread);
	Assert::exception(
		fn() => $build->invoke($manager, 'empty-id', 'body', []),
		DG\Google\Gmail\Exception::class,
		'Thread empty-id has no messages.',
	);
});
