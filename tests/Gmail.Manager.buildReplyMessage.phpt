<?php declare(strict_types=1);

use DG\Google\Gmail\{Manager, Message, Recipient, Thread};
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


function makeThread(mixed ...$args): Thread
{
	return new Thread('t1', [makeMessage(...$args)]);
}


function makeMessage(
	string $subject,
	?Recipient $sender = null,
	array $to = [],
	array $cc = [],
	array $replyTo = [],
	?string $messageId = null,
	?string $references = null,
	string $id = 'm1',
): Message
{
	return new Message(
		id: $id,
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
}


$build = (new ReflectionMethod(Manager::class, 'createReplyMessage'));


test('CRLF in incoming subject is sanitised on the reply', function () use ($build) {
	$thread = makeThread(
		subject: "Hi\r\nBcc: attacker@evil.cz",
		sender: new Recipient('them@example.com'),
	);
	$manager = makeManagerWithThread('me@example.com', $thread);
	$mail = $build->invoke($manager, 't1', 'reply body', []);
	assert($mail instanceof Nette\Mail\Message);
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


test('replying to our own last message addresses its recipients, not ourselves', function () use ($build) {
	$thread = makeThread(
		subject: 'Hi',
		sender: new Recipient('me@example.com'), // last message was sent BY us
		to: [new Recipient('client@example.com')],
		cc: [new Recipient('cc@example.com')],
	);
	$manager = makeManagerWithThread('me@example.com', $thread);
	$mail = $build->invoke($manager, 't1', 'follow-up', []);
	$raw = $mail->generateMessage();
	$head = substr($raw, 0, strpos($raw, "\r\n\r\n") ?: strlen($raw));

	Assert::contains('To: client@example.com', $raw);
	Assert::contains('Cc: cc@example.com', $raw);
	// we must not send the follow-up to ourselves
	Assert::notContains('me@example.com', $head);
});


test('our own Bcc-only last message is rejected with a clear error', function () use ($build) {
	$thread = makeThread(
		subject: 'Hi',
		sender: new Recipient('me@example.com'), // sent by us, no To recipients
	);
	$manager = makeManagerWithThread('me@example.com', $thread);
	Assert::exception(
		fn() => $build->invoke($manager, 't1', 'body', []),
		DG\Google\Gmail\Exception::class,
		'Cannot determine reply recipients:%a%',
	);
});


function makeBccReplies(): Thread
{
	return new Thread('t1', [
		makeMessage('Reminder', new Recipient('me@example.com'), to: [new Recipient('me@example.com')], messageId: '<reminder@host>'),
		makeMessage(
			'Re: Reminder',
			new Recipient('first@example.com'),
			to: [new Recipient('me@example.com')],
			messageId: '<first@host>',
			references: '<reminder@host>',
			id: 'm2',
		),
		makeMessage(
			'RE: Reminder',
			new Recipient('second@example.com'),
			to: [new Recipient('me@example.com')],
			cc: [new Recipient('boss@example.com')],
			messageId: '<second@host>',
			references: '<reminder@host>',
			id: 'm3',
		),
	]);
}


test('without messageId the reply answers the last message', function () use ($build) {
	$manager = makeManagerWithThread('me@example.com', makeBccReplies());
	$raw = $build->invoke($manager, 't1', 'body', [])->generateMessage();

	Assert::contains('To: second@example.com', $raw);
	Assert::contains('Cc: boss@example.com', $raw);
	Assert::contains('Subject: RE: Reminder', $raw);
	Assert::contains('In-Reply-To: <second@host>', $raw);
});


test('messageId answers that message of the thread', function () use ($build) {
	$manager = makeManagerWithThread('me@example.com', makeBccReplies());
	$raw = $build->invoke($manager, 't1', 'body', [], 'm2')->generateMessage();
	$head = substr($raw, 0, strpos($raw, "\r\n\r\n") ?: strlen($raw));

	Assert::contains('To: first@example.com', $head);
	Assert::notContains('second@example.com', $head);
	Assert::notContains('boss@example.com', $head);
	Assert::contains('Subject: Re: Reminder', $head);
	Assert::contains('In-Reply-To: <first@host>', $head);
	Assert::contains('References: <reminder@host> <first@host>', $head);
});


test('messageId of our own message addresses its recipients', function () use ($build) {
	$thread = new Thread('t1', [
		makeMessage('Hi', new Recipient('me@example.com'), to: [new Recipient('client@example.com')]),
		makeMessage('Re: Hi', new Recipient('other@example.com'), id: 'm2'),
	]);
	$manager = makeManagerWithThread('me@example.com', $thread);
	$raw = $build->invoke($manager, 't1', 'body', [], 'm1')->generateMessage();

	Assert::contains('To: client@example.com', $raw);
});


test('messageId outside the thread is refused', function () use ($build) {
	$manager = makeManagerWithThread('me@example.com', makeBccReplies());
	Assert::exception(
		fn() => $build->invoke($manager, 't1', 'body', [], 'other-thread-msg'),
		InvalidArgumentException::class,
		'Message other-thread-msg is not in thread t1.',
	);
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
