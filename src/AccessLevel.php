<?php declare(strict_types=1);

namespace DG\Google;


/**
 * How far a tool reaches. Each level includes the lower ones.
 */
enum AccessLevel: string
{
	/** only reads */
	case Read = 'read';

	/** changes the user's own account, invisible to anybody else */
	case Write = 'write';

	/** something leaves the account and reaches third parties */
	case Send = 'send';


	public function includes(self $level): bool
	{
		return $this->rank() >= $level->rank();
	}


	private function rank(): int
	{
		return match ($this) {
			self::Read => 1,
			self::Write => 2,
			self::Send => 3,
		};
	}
}
