<?php declare(strict_types=1);

namespace DG\Google;

use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;


/**
 * Registers only the tools selected by ToolSelection; server.php uses it instead of the SDK's discovery.
 */
final class ToolLoader implements LoaderInterface
{
	public function __construct(
		/** @var array<string, ToolReference> */
		private readonly array $tools,
	) {
	}


	public function load(RegistryInterface $registry): void
	{
		foreach ($this->tools as $ref) {
			$registry->registerTool($ref->tool, $ref->handler);
		}
	}
}
