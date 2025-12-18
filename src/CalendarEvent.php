<?php

declare(strict_types=1);

namespace DG\Google;


class CalendarEvent
{
	public ?string $location = null;
	public ?string $description = null;

	public ?string $reminder = null;
	public bool $createMeeting = false;
	public int $repeatCount = 1;

	/** Repeat interval in days, if $repeatCount > 1. E.g., 1 = every day, 2 = every other day. */
	public int $repeatIntervalDays = 1;


	public function __construct(
		public string $summary,
		public \DateTimeInterface $start,
		public \DateTimeInterface $end,
	) {
	}
}
