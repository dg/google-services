<?php declare(strict_types=1);

use DG\Google\Calendar\Manager;
use Google\Service\Calendar;
use Google\Service\Calendar\Resource\Events;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('the event is deleted from the default calendar without notifying attendees', function () {
	$events = new class extends Events {
		public array $deleted = [];


		public function __construct()
		{
		}


		public function delete($calendarId, $eventId, $optParams = [])
		{
			$this->deleted[] = [$calendarId, $eventId, $optParams];
		}
	};

	$ref = new ReflectionClass(Manager::class);
	$manager = $ref->newInstanceWithoutConstructor();
	$service = (new ReflectionClass(Calendar::class))->newInstanceWithoutConstructor();
	$service->events = $events;
	$ref->getProperty('service')->setValue($manager, $service);
	$ref->getProperty('calendarId')->setValue($manager, 'calendar');

	$manager->deleteEvent('event');
	Assert::same([['calendar', 'event', ['sendUpdates' => 'none']]], $events->deleted);
});
