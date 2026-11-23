<?php declare(strict_types=1);

use DG\Google\Gmail\Manager;
use Google\Service\Gmail;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


function message(string $from, string $to = '', string $cc = ''): Gmail\Message
{
	$headers = [];
	foreach (['From' => $from, 'To' => $to, 'Cc' => $cc] as $name => $value) {
		if ($value !== '') {
			$headers[] = new Gmail\MessagePartHeader(['name' => $name, 'value' => $value]);
		}
	}
	$payload = new Gmail\MessagePart;
	$payload->setHeaders($headers);
	$message = new Gmail\Message;
	$message->setPayload($payload);
	return $message;
}


function awaiting(array $messages): array
{
	$recipients = (new ReflectionMethod(Manager::class, 'findAwaitingReply'))->invoke(null, $messages, 'me@example.com');
	return array_map(fn($r) => $r->email, $recipients);
}


test('every reply to a BCC mass mail waits until it is answered', function () {
	Assert::same(['a@example.com', 'b@example.com', 'c@example.com'], awaiting([
		message('David <me@example.com>'),
		message('A <a@example.com>', 'me@example.com'),
		message('B <b@example.com>', 'me@example.com'),
		message('C <c@example.com>', 'me@example.com'),
	]));
});


test('a reply of mine addressing someone in To or Cc settles only them', function () {
	Assert::same(['c@example.com', 'a@example.com'], awaiting([
		message('David <me@example.com>'),
		message('A <a@example.com>', 'me@example.com'),
		message('B <b@example.com>', 'me@example.com'),
		message('C <c@example.com>', 'me@example.com'),
		message('me@example.com', 'a@example.com', 'B <B@Example.com>'),
		message('A <a@example.com>', 'me@example.com'),
	]));
});


test('a thread ending with my reply to its only correspondent awaits nobody', function () {
	Assert::same([], awaiting([
		message('A <a@example.com>', 'me@example.com'),
		message('Me <ME@example.com>', 'a@example.com'),
	]));
});
