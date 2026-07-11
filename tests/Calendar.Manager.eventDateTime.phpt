<?php declare(strict_types=1);

use DG\Google\Calendar\Manager;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$build = (new ReflectionMethod(Manager::class, 'eventDateTime'))->getClosure();


test('IANA zone is passed through as timeZone', function () use ($build) {
	$dt = new DateTimeImmutable('2026-06-01T10:00:00', new DateTimeZone('Europe/Prague'));
	$edt = $build($dt);
	Assert::same('Europe/Prague', $edt->getTimeZone());
	Assert::contains('2026-06-01T10:00:00', $edt->getDateTime());
});


test('offset-only datetime omits timeZone (offset lives in dateTime)', function () use ($build) {
	$dt = new DateTimeImmutable('2026-06-01T10:00:00+02:00');
	$edt = $build($dt);
	// "+02:00" is not a valid IANA name, so it must NOT be sent as timeZone
	Assert::null($edt->getTimeZone());
	Assert::contains('+02:00', $edt->getDateTime());
});


test('UTC counts as a valid zone name', function () use ($build) {
	$dt = new DateTimeImmutable('2026-06-01T10:00:00', new DateTimeZone('UTC'));
	Assert::same('UTC', $build($dt)->getTimeZone());
});
