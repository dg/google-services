<?php declare(strict_types=1);

namespace DG\Google\Calendar;

use Google;
use Google\Service\Calendar;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\EventAttendee;


class Manager
{
	public const PrimaryCalendar = 'primary';
	private Calendar $service;


	public function __construct(
		Google\Client $client,
		private readonly string $calendarId = self::PrimaryCalendar,
	) {
		$this->service = new Calendar($client);
	}


	public function createEvent(Event $event): GoogleEvent
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
		if ($event->location !== null) {
			$blueprint->setLocation($event->location);
		}
		if ($event->description !== null) {
			$blueprint->setDescription($event->description);
		}
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


	/** @param  string[]  $emails */
	public function addAttendees(string $eventId, array $emails, bool $sendNotifications = false): void
	{
		$event = $this->service->events->get($this->calendarId, $eventId);
		$existing = $event->getAttendees() ?? [];
		$existingEmails = [];
		foreach ($existing as $attendee) {
			if ($attendee instanceof EventAttendee && $attendee->getEmail()) {
				$existingEmails[strtolower($attendee->getEmail())] = true;
			}
		}

		$newEmails = array_keys(array_diff_key($this->normalizeEmails($emails), $existingEmails));
		$newAttendees = array_map(
			static fn(string $email) => new EventAttendee(['email' => $email]),
			$newEmails,
		);

		if (!$newAttendees) {
			return;
		}

		$merged = array_merge($existing, $newAttendees);

		if (!$sendNotifications || !$existing) {
			$event->setAttendees($merged);
			$this->service->events->update($this->calendarId, $eventId, $event, ['sendUpdates' => $sendNotifications ? 'all' : 'none']);
			return;
		}

		// Google's sendUpdates=all would notify everyone on the event. Detach the old crowd silently,
		// invite the newcomers alone (so only they receive the email), then restore the full list silently.
		// try/finally guarantees the restore even if the invitation step fails.
		$update = fn(string $sendUpdates) => $this->service->events->update($this->calendarId, $eventId, $event, ['sendUpdates' => $sendUpdates]);

		$event->setAttendees([]);
		$update('none');
		try {
			$event->setAttendees($newAttendees);
			$update('all');
		} finally {
			$event->setAttendees($merged);
			$update('none');
		}
	}


	/** @param  string[]  $emails */
	public function removeAttendees(string $eventId, array $emails): void
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
		$updateOptions = ['sendUpdates' => 'none'];
		$this->service->events->update($this->calendarId, $eventId, $event, $updateOptions);
	}


	public function getEvent(string $eventId): GoogleEvent
	{
		return $this->service->events->get($this->calendarId, $eventId);
	}


	public function updateDescription(string $eventId, string $description): void
	{
		$patch = new GoogleEvent;
		$patch->setDescription($description);
		$this->service->events->patch($this->calendarId, $eventId, $patch, [
			'sendUpdates' => 'none',
		]);
	}


	/**
	 * @param  string[]  $emails
	 * @return array<string, true>
	 */
	private function normalizeEmails(array $emails): array
	{
		$res = [];
		foreach ($emails as $email) {
			$email = strtolower(trim($email));
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				trigger_error("Invalid email address: $email", E_USER_WARNING);
				continue;
			}
			$res[$email] = true;
		}
		return $res;
	}
}
