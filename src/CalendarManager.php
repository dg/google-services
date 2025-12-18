<?php

declare(strict_types=1);

namespace DG\Google;

use Google;
use Google\Service\Calendar;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\EventAttendee;


class CalendarManager
{
	public const PrimaryCalendar = 'primary';
	private Calendar $service;


	public function __construct(
		Google\Client $client,
		private string $calendarId = self::PrimaryCalendar,
	) {
		$this->service = new Calendar($client);
	}


	public function createEvent(CalendarEvent $event): GoogleEvent
	{
		$blueprint = new GoogleEvent;
		$blueprint->setSummary($event->summary);
		$blueprint->setStart(new Calendar\EventDateTime([
			'dateTime' => $event->start->format($event->start::RFC3339),
			'timeZone' => $event->start->getTimezone()->getName(),
		]));
		$blueprint->setEnd(new Calendar\EventDateTime([
			'dateTime' => $event->end->format($event->end::RFC3339),
			'timeZone' => $event->end->getTimezone()->getName(),
		]));
		$blueprint->setLocation($event->location);
		$blueprint->setDescription($event->description);
		$blueprint->setGuestsCanInviteOthers(false);
		$blueprint->setGuestsCanSeeOtherGuests(false);

		if ($event->reminder) {
			$reminder = new Calendar\EventReminder;
			$reminder->method = 'popup';
			$now = new \DateTimeImmutable('', new \DateTimeZone('UTC'));
			$reminder->minutes = (int) round(($now->modify($event->reminder)->getTimestamp() - $now->getTimestamp()) / 60);
			$blueprint->setReminders(new Calendar\EventReminders(['useDefault' => false, 'overrides' => [$reminder]]));
		}

		if ($event->repeatCount > 1) {
			$rrule = 'RRULE:FREQ=DAILY';
			if ($event->repeatIntervalDays > 1) {
				$rrule .= ';INTERVAL=' . $event->repeatIntervalDays;
			}
			$rrule .= ';COUNT=' . $event->repeatCount;
			$blueprint->setRecurrence([$rrule]);
		}

		$res = $this->service->events->insert($this->calendarId, $blueprint);
		if ($event->createMeeting) {
			return $this->createMeeting($res->getId());
		}
		return $res;
	}


	private function createMeeting(string $eventId): GoogleEvent
	{
		$patch = new GoogleEvent;
		$patch->setConferenceData(new Calendar\ConferenceData([
			'createRequest' => new Calendar\CreateConferenceRequest([
				'requestId' => bin2hex(random_bytes(8)),
				'conferenceSolutionKey' => new Calendar\ConferenceSolutionKey(['type' => 'hangoutsMeet']),
			]),
		]));

		$patchOptions = [
			'conferenceDataVersion' => 1,
		];

		return $this->service->events->patch($this->calendarId, $eventId, $patch, $patchOptions);
	}


	public function addAttendees(string $eventId, array $emails, bool $sendNotification = true): void
	{
		$event = $this->service->events->get($this->calendarId, $eventId);
		$attendees = $event->getAttendees() ?? [];
		$attendeeEmails = [];
		foreach ($attendees as $attendee) {
			if ($attendee instanceof EventAttendee && $attendee->getEmail()) {
				$attendeeEmails[strtolower($attendee->getEmail())] = true;
			}
		}

		$addedNew = false;
		foreach ($this->normalizeEmails($emails) as $email => $_) {
			if (!isset($attendeeEmails[$email])) {
				$newAttendee = new EventAttendee;
				$newAttendee->setEmail($email);
				$attendees[] = $newAttendee;
				$addedNew = true;
			}
		}

		if (!$addedNew) {
			return;
		}

		$event->setAttendees($attendees);
		$updateOptions = ['sendUpdates' => $sendNotification ? 'all' : 'none'];
		$this->service->events->update($this->calendarId, $eventId, $event, $updateOptions);
	}


	public function removeAttendees(string $eventId, array $emails, bool $sendNotification = false): void
	{
		$event = $this->service->events->get($this->calendarId, $eventId);
		$emails = $this->normalizeEmails($emails);
		$attendees = $event->getAttendees() ?? [];
		$removedAny = false;
		foreach ($attendees as $key => $attendee) {
			if ($attendee instanceof EventAttendee
				&& $attendee->getEmail()
				&& isset($emails[strtolower($attendee->getEmail())])
			) {
				unset($attendees[$key]);
				$removedAny = true;
			}
		}

		if (!$removedAny) {
			return;
		}

		$event->setAttendees($attendees);
		$updateOptions = ['sendUpdates' => $sendNotification ? 'all' : 'none'];
		$this->service->events->update($this->calendarId, $eventId, $event, $updateOptions);
	}


	public function getEvent(string $eventId): GoogleEvent
	{
		return $this->service->events->get($this->calendarId, $eventId);
	}


	private function normalizeEmails(array $emails): array
	{
		$res = [];
		foreach ($emails as $email) {
			$email = strtolower(trim((string) $email));
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				trigger_error("Invalid email address: $email", E_USER_WARNING);
				continue;
			}
			$res[$email] = true;
		}
		return $res;
	}
}
