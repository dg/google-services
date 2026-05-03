<?php declare(strict_types=1);

namespace DG\Google\Gmail;


class Recipient
{
	public function __construct(
		public string $email,
		public ?string $name = null,
	) {
	}


	public function toString(): string
	{
		return $this->name === null
			? $this->email
			: "$this->name <$this->email>";
	}
}
