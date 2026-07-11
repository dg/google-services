<?php declare(strict_types=1);

use DG\Google\Gmail\Manager;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartHeader;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$decode = (new ReflectionMethod(Manager::class, 'decodeTextPart'))->getClosure();

$base64url = static fn(string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

$part = static function (?string $contentType): MessagePart {
	$p = new MessagePart;
	$p->setMimeType('text/plain');
	if ($contentType !== null) {
		$h = new MessagePartHeader;
		$h->setName('Content-Type');
		$h->setValue($contentType);
		$p->setHeaders([$h]);
	}
	return $p;
};


test('UTF-8 body passes through', function () use ($decode, $part, $base64url) {
	$text = 'Příliš žluťoučký kůň';
	Assert::same($text, $decode($part('text/plain; charset="UTF-8"'), $base64url($text)));
});


test('ISO-8859-2 body is converted to UTF-8', function () use ($decode, $part, $base64url) {
	$text = 'Příliš žluťoučký kůň';
	$iso = iconv('UTF-8', 'ISO-8859-2', $text);
	Assert::same($text, $decode($part('text/plain; charset="ISO-8859-2"'), $base64url($iso)));
});


test('windows-1250 body is converted to UTF-8', function () use ($decode, $part, $base64url) {
	$text = 'Žluťoučký';
	$win = iconv('UTF-8', 'WINDOWS-1250', $text);
	Assert::same($text, $decode($part('text/plain; charset=windows-1250'), $base64url($win)));
});


test('no charset and invalid bytes yields valid UTF-8', function () use ($decode, $part, $base64url) {
	// a lone 0xFF is not valid UTF-8; fixEncoding must strip it so json_encode can't fail later
	$out = $decode($part(null), $base64url("abc\xFFdef"));
	Assert::true(mb_check_encoding($out, 'UTF-8'));
});
