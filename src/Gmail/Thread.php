<?php declare(strict_types=1);

namespace DG\Google\Gmail;


class Thread
{
	public function __construct(
		public string $id,
		/** @var list<Message> */
		public array $messages,
	) {
	}
}
