<?php declare(strict_types=1);

namespace DG\Google;

use Mcp\Exception\ToolCallException;


/**
 * Single home for the manager-factory → tool-error conversion shared by every McpTools class. Each
 * tool group resolves its manager lazily (so an OAuth failure surfaces at the first call, not as a
 * process crash before the MCP handshake) and turns an AuthException into a self-correctable
 * ToolCallException with one consistent "re-authorize" hint. Kept as a generic static helper rather
 * than a trait so each McpTools keeps its own concretely-typed manager cache.
 */
final class ManagerResolver
{
	/**
	 * @template T of object
	 * @param \Closure(): T $factory
	 * @return T
	 */
	public static function resolve(\Closure $factory): object
	{
		try {
			return $factory();
		} catch (AuthException $e) {
			throw new ToolCallException(
				'Google authentication failed: ' . $e->getMessage() . ' Re-authorize via `php demo/authenticate.php`.',
				0,
				$e,
			);
		}
	}
}
