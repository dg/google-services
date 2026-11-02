<?php declare(strict_types=1);

use DG\Google\Calendar\Manager;
use Google\Service\Calendar;
use Google\Service\Calendar\{Event, EventAttendee};
use Google\Service\Calendar\Resource\Events;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// Builds the manager without the constructor around a fake events resource and returns
// the attendees of the request it sends, as the API would receive them.
$remove = static function (array $present, array $removed): mixed {
	$events = new class ($present) extends Events {
		public ?Event $sent = null;


		public function __construct(
			private array $present,
		) {
		}


		public function get($calendarId, $eventId, $optParams = [], $expectedClass = null): Event
		{
			$event = new Event;
			$event->setAttendees(array_map(fn(string $email) => new EventAttendee(['email' => $email]), $this->present));
			return $event;
		}


		public function update($calendarId, $eventId, Event $postBody, $optParams = [], $expectedClass = null): Event
		{
			return $this->sent = $postBody;
		}
	};

	$ref = new ReflectionClass(Manager::class);
	$manager = $ref->newInstanceWithoutConstructor();
	$service = (new ReflectionClass(Calendar::class))->newInstanceWithoutConstructor();
	$service->events = $events;
	$ref->getProperty('service')->setValue($manager, $service);
	$ref->getProperty('calendarId')->setValue($manager, 'calendar');

	$manager->removeAttendees('event', $removed);
	return $events->sent === null
		? null
		: json_decode(json_encode($events->sent->toSimpleObject()), associative: true)['attendees'];
};


test('removing the first attendee keeps the others as a list', function () use ($remove) {
	Assert::same(
		[['email' => 'b@example.com'], ['email' => 'c@example.com']],
		$remove(['a@example.com', 'b@example.com', 'c@example.com'], ['a@example.com']),
	);
});


test('removing an attendee from the middle keeps the others as a list', function () use ($remove) {
	Assert::same(
		[['email' => 'a@example.com'], ['email' => 'c@example.com']],
		$remove(['a@example.com', 'b@example.com', 'c@example.com'], ['B@example.com']),
	);
});


test('nothing is sent when no attendee matches', function () use ($remove) {
	Assert::null($remove(['a@example.com'], ['b@example.com']));
});
