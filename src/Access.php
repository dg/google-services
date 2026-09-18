<?php declare(strict_types=1);

namespace DG\Google;


/**
 * Access level of an MCP tool, which GOOGLE_TOOLS selects by. Every #[McpTool] must carry it.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Access
{
	public function __construct(
		public readonly AccessLevel $level,
	) {
	}
}
