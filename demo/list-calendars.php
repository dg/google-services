<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';


$googleClient = googleAuthenticate();
$service = new Google\Service\Calendar($googleClient);

$calendarList = $service->calendarList->listCalendarList();

echo "Found calendars:\n";

foreach ($calendarList->getItems() as $calendarListEntry) {
	echo 'ID: ' . $calendarListEntry->getId() . "\n";
	echo 'Name: ' . $calendarListEntry->getSummary() . "\n";
	echo 'Description: ' . $calendarListEntry->getDescription() . "\n";
	echo "--------------------------\n";
}
