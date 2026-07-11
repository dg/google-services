<?php declare(strict_types=1);

use DG\Google\Gmail\Manager;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('strips tags and decodes entities', function () {
	Assert::same('Hello world & friends', Manager::htmlToPlainText('<p>Hello <b>world</b> &amp; friends</p>'));
});


test('br and div boundaries become newlines (Gmail wraps lines in div)', function () {
	Assert::same("Line 1\nLine 2\nLine 3", Manager::htmlToPlainText('Line 1<br>Line 2<div>Line 3</div>'));
});


test('script and style content is dropped', function () {
	$html = '<style>.a{color:red}</style><p>Visible</p><script>alert(1)</script>';
	Assert::same('Visible', Manager::htmlToPlainText($html));
});


test('list items break onto separate lines', function () {
	Assert::same("one\ntwo", Manager::htmlToPlainText('<ul><li>one</li><li>two</li></ul>'));
});


test('links are rendered as text and url', function () {
	Assert::same('Click here <https://x.cz>', Manager::htmlToPlainText('<p>Click <a href="https://x.cz">here</a></p>'));
});
