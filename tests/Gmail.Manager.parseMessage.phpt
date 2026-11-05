<?php declare(strict_types=1);

use DG\Google\Gmail\Manager;
use Google\Service\Gmail\{Message, MessagePart, MessagePartBody, MessagePartHeader};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$parse = new ReflectionMethod(Manager::class, 'parseMessage');
$manager = (new ReflectionClass(Manager::class))->newInstanceWithoutConstructor();


test('a message without labels (a scheduled one) has an empty label list', function () use ($parse, $manager) {
	$payload = new MessagePart([
		'mimeType' => 'text/plain',
		'headers' => [new MessagePartHeader(['name' => 'Subject', 'value' => 'Hello'])],
		'body' => new MessagePartBody(['data' => rtrim(strtr(base64_encode('Body'), '+/', '-_'), '=')]),
	]);
	$msg = new Message(['id' => 'm1', 'internalDate' => '1700000000000', 'snippet' => '', 'payload' => $payload]);

	$parsed = $parse->invoke($manager, $msg);
	Assert::same([], $parsed->labelIds);
	Assert::same('Hello', $parsed->subject);
});
