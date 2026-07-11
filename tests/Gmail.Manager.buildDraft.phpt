<?php declare(strict_types=1);

use DG\Google\Gmail\Manager;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// buildDraft() is private and touches no services; build the instance without the
// constructor and invoke the method reflectively.
$build = new ReflectionMethod(Manager::class, 'buildDraft');
$manager = (new ReflectionClass(Manager::class))->newInstanceWithoutConstructor();


test('draft carries the raw RFC 2822 message, base64url-encoded', function () use ($build, $manager) {
	$mail = new Nette\Mail\Message;
	$mail->addTo('to@example.com');
	$mail->setSubject('Hello');
	$mail->setBody('Body');

	$draft = $build->invoke($manager, $mail, null);
	Assert::type(Google\Service\Gmail\Draft::class, $draft);

	$raw = $draft->getMessage()->getRaw();
	Assert::false((bool) preg_match('~[+/=]~', $raw)); // URL-safe alphabet, no padding
	$decoded = base64_decode(strtr($raw, '-_', '+/'), true);
	Assert::contains("Subject: Hello\r\n", $decoded);
	Assert::contains("To: to@example.com\r\n", $decoded);
	Assert::null($draft->getMessage()->getThreadId());
});


test('threadId is attached only when given (reply draft)', function () use ($build, $manager) {
	$mail = new Nette\Mail\Message;
	$mail->addTo('to@example.com');
	$mail->setBody('Body');

	$draft = $build->invoke($manager, $mail, 't123');
	Assert::same('t123', $draft->getMessage()->getThreadId());
});
