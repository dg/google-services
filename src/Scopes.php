<?php declare(strict_types=1);

namespace DG\Google;

use Google\Service\Calendar;
use Google\Service\Gmail;
use Google\Service\Slides;


/**
 * Single source of truth for the OAuth scopes the MCP server requires. server.php authorizes with
 * exactly this list; demo/bootstrap.php (the script the user runs to obtain the token) spreads it
 * into its own superset, so the token it mints is guaranteed to cover everything the server needs.
 * Previously the two lists were maintained independently and had already drifted apart.
 */
final class Scopes
{
	/** Scopes needed by the tools the MCP server currently exposes (Gmail, read-only Calendar, Slides). */
	public const McpServer = [
		Gmail::GMAIL_MODIFY,
		Calendar::CALENDAR_READONLY,
		Slides::PRESENTATIONS,
	];
}
