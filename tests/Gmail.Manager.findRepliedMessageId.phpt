<?php declare(strict_types=1);

use DG\Google\Gmail\{Manager, Message, Recipient, Thread};
use Google\Service\Gmail;
use Google\Service\Gmail\Resource\UsersDrafts;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


function makeManager(?string $inReplyTo, Thread $thread): Manager
{
	$drafts = new class ($inReplyTo) extends UsersDrafts {
		public function __construct(
			private ?string $inReplyTo,
		) {
		}


		public function get($userId, $id, $optParams = [])
		{
			$headers = [new Gmail\MessagePartHeader(['name' => 'Subject', 'value' => 'Re: Hi'])];
			if ($this->inReplyTo !== null) {
				$headers[] = new Gmail\MessagePartHeader(['name' => 'In-Reply-To', 'value' => $this->inReplyTo]);
			}
			$payload = new Gmail\MessagePart;
			$payload->setHeaders($headers);
			$message = new Gmail\Message;
			$message->setPayload($payload);
			$draft = new Gmail\Draft;
			$draft->setMessage($message);
			return $draft;
		}
	};

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
	$service = (new ReflectionClass(Gmail::class))->newInstanceWithoutConstructor();
	$service->users_drafts = $drafts;
	(new ReflectionProperty(Manager::class, 'service'))->setValue($manager, $service);
	(new ReflectionProperty(Manager::class, 'userId'))->setValue($manager, 'me');
	return $manager;
}


function makeMessage(string $id, string $messageIdHeader): Message
{
	return new Message(
		id: $id,
		date: new DateTimeImmutable('2026-01-01T00:00:00Z'),
		sender: new Recipient("$id@example.com"),
		toRecipients: [],
		ccRecipients: [],
		replyToRecipients: [],
		subject: 'Hi',
		snippet: '',
		plaintextBody: null,
		htmlBody: null,
		messageIdHeader: $messageIdHeader,
		referencesHeader: null,
	);
}


$thread = new Thread('t1', [
	makeMessage('m1', '<one@example.com>'),
	makeMessage('m2', '<two@example.com>'),
	makeMessage('m3', '<three@example.com>'),
]);


test('the draft answers the message its In-Reply-To names', function () use ($thread) {
	Assert::same('m2', makeManager('<two@example.com>', $thread)->findRepliedMessageId('d1', 't1'));
});


test('a draft without In-Reply-To, or answering a message outside the thread, answers none', function () use ($thread) {
	Assert::null(makeManager(null, $thread)->findRepliedMessageId('d1', 't1'));
	Assert::null(makeManager('<other@example.com>', $thread)->findRepliedMessageId('d1', 't1'));
});
