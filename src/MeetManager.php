<?php declare(strict_types=1);

namespace DG\Google;

use Google;
use Google\Service\Meet;


class MeetManager
{
	private Meet $service;


	public function __construct(Google\Client $client)
	{
		$this->service = new Meet($client);
	}


	/**
	 * Creates a standalone Meet space not linked to any calendar event.
	 */
	public function createSpace(): Meet\Space
	{
		return $this->service->spaces->create(new Meet\Space);
	}
}
