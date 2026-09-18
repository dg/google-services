<?php declare(strict_types=1);

namespace DG\Google;

use Google\Service\Calendar;
use Google\Service\Gmail;
use Google\Service\Slides;


/**
 * Single source of truth for the OAuth scopes the MCP server requires. server.php authorizes with
 * the scopes of the enabled services; demo/bootstrap.php (the script the user runs to obtain the
 * token) requests the same, so the token it mints is guaranteed to cover everything the server needs.
 */
final class Scopes
{
	/**
	 * Scope needed by each service the MCP server exposes. Calendar uses the full read-write scope
	 * because calendar_create_event writes; which tools the model gets is decided by GOOGLE_TOOLS,
	 * so granting the scope does not by itself let the model change the calendar.
	 */
	public const Services = [
		'gmail' => Gmail::GMAIL_MODIFY,
		'calendar' => Calendar::CALENDAR,
		'slides' => Slides::PRESENTATIONS,
	];


	/**
	 * @param  list<string>  $services
	 * @return list<string>
	 */
	public static function forServices(array $services): array
	{
		return array_values(array_intersect_key(self::Services, array_flip($services)));
	}
}
