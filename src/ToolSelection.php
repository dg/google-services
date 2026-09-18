<?php declare(strict_types=1);

namespace DG\Google;

use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Registry\ToolReference;
use function array_keys, array_unique, array_values, explode, implode, is_array, preg_match, preg_quote, preg_split, str_contains, str_replace, str_starts_with, strstr, substr;


/**
 * Selects the MCP tools the server exposes, according to the GOOGLE_TOOLS rules.
 *
 * Rules are separated by commas and applied left to right, starting from an empty set:
 *   slides               whole service up to the write level
 *   gmail:read           service up to the given level (read, write, send)
 *   gmail_create_draft   single tool
 *   gmail_label_*        tools matching the pattern, never a send-level one
 *   default              the default selection
 *   -rule                removes the tools instead (a service without a level)
 * Send-level tools must always be named explicitly: by `service:send` or by their full name.
 */
final class ToolSelection
{
	public const Default = 'gmail, calendar:read, slides';


	/**
	 * Discovers all tools the server offers, keyed by name.
	 * @return array<string, ToolReference>
	 */
	public static function discover(): array
	{
		return (new Discoverer)
			->discover(__DIR__, ['.'], [], ['*Tools.php'])
			->getTools();
	}


	/**
	 * @param  array<string, ToolReference>  $tools
	 * @return array<string, ToolReference>
	 * @throws \InvalidArgumentException  when the rules are invalid
	 */
	public static function select(string $rules, array $tools): array
	{
		$levels = array_map(self::getLevel(...), $tools);
		$selected = [];
		$adds = false;
		foreach (self::parseRules($rules) as $rule) {
			if (str_starts_with($rule, '-')) {
				$selected = array_diff_key($selected, self::matchRule(substr($rule, 1), $levels, remove: true));
			} else {
				$selected += self::matchRule($rule, $levels, remove: false);
				$adds = true;
			}
		}

		if (!$adds) {
			throw new \InvalidArgumentException("GOOGLE_TOOLS contains no rule that enables a tool, so the server would expose nothing. To remove tools from the default selection, write 'default, -tool_name'.");
		}
		return array_intersect_key($tools, $selected);
	}


	public static function getLevel(ToolReference $tool): AccessLevel
	{
		$handler = $tool->handler;
		$attrs = is_array($handler)
			? (new \ReflectionMethod(...$handler))->getAttributes(Access::class)
			: [];
		return ($attrs[0] ?? null)?->newInstance()->level
			?? throw new \LogicException("Tool {$tool->tool->name} is missing the #[Access] attribute.");
	}


	/**
	 * Returns services (tool name prefixes) the tools belong to.
	 * @param  array<string, mixed>  $tools
	 * @return list<string>
	 */
	public static function getServices(array $tools): array
	{
		return array_values(array_unique(array_map(self::serviceOf(...), array_keys($tools))));
	}


	/** The service a tool belongs to is the part of its name before the first underscore. */
	private static function serviceOf(string $toolName): string
	{
		return (string) strstr($toolName, '_', before_needle: true);
	}


	/**
	 * Describes the selection per service, e.g. "gmail 18/20 tools, calendar off, slides 12/12 tools".
	 * @param  array<string, mixed>  $all
	 * @param  array<string, mixed>  $selected
	 */
	public static function describeStatus(array $all, array $selected): string
	{
		$counts = array_count_values(array_map(self::serviceOf(...), array_keys($selected)));
		$res = [];
		foreach (array_count_values(array_map(self::serviceOf(...), array_keys($all))) as $service => $total) {
			$count = $counts[$service] ?? 0;
			$res[] = $count ? "$service $count/$total tools" : "$service off";
		}
		return implode(', ', $res);
	}


	/**
	 * Describes what the selection leaves out, e.g. "calendar (whole service), gmail_send_reply".
	 * @param  array<string, mixed>  $all
	 * @param  array<string, mixed>  $selected
	 */
	public static function describeDisabled(array $all, array $selected): string
	{
		$enabledServices = self::getServices($selected);
		$res = [];
		foreach (self::getServices($all) as $service) {
			if (!in_array($service, $enabledServices, true)) {
				$res[] = "$service (whole service)";
			}
		}
		foreach (array_keys(array_diff_key($all, $selected)) as $name) {
			if (in_array(self::serviceOf($name), $enabledServices, true)) {
				$res[] = $name;
			}
		}
		return implode(', ', $res);
	}


	/** @return list<string> */
	private static function parseRules(string $rules): array
	{
		$res = [];
		foreach (preg_split('/[\s,]+/', $rules, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $rule) {
			if ($rule === '-default') {
				throw new \InvalidArgumentException("GOOGLE_TOOLS: '-default' is not supported, rules start from nothing anyway; name the services or tools to remove.");

			} elseif ($rule === 'default') {
				foreach (explode(',', self::Default) as $item) {
					$res[] = trim($item);
				}
			} else {
				$res[] = $rule;
			}
		}
		return $res;
	}


	/**
	 * @param  array<string, AccessLevel>  $levels
	 * @return array<string, true>
	 */
	private static function matchRule(string $rule, array $levels, bool $remove): array
	{
		$matched = [];

		if (str_contains($rule, '*')) {
			$re = '#^' . str_replace('\*', '.*', preg_quote($rule, '#')) . '$#D';
			foreach ($levels as $name => $level) {
				if (preg_match($re, $name) && ($remove || $level !== AccessLevel::Send)) {
					$matched[$name] = true;
				}
			}
			if (!$matched) {
				throw new \InvalidArgumentException("GOOGLE_TOOLS: pattern '$rule' matches no tool" . ($remove ? '.' : ' (send-level tools must be named explicitly).'));
			}

		} elseif (str_contains($rule, '_')) {
			if (!isset($levels[$rule])) {
				throw new \InvalidArgumentException("GOOGLE_TOOLS: unknown tool '$rule'.");
			}
			$matched[$rule] = true;

		} else {
			[$service, $levelName] = explode(':', $rule, 2) + [1 => null];
			$services = self::getServices($levels);
			if (!in_array($service, $services, true)) {
				throw new \InvalidArgumentException("GOOGLE_TOOLS: unknown service '$service', expected one of: " . implode(', ', $services) . '.');
			} elseif ($remove && $levelName !== null) {
				throw new \InvalidArgumentException("GOOGLE_TOOLS: '-$rule' is ambiguous; remove the whole service with '-$service', or name the tools.");
			}

			$limit = $levelName === null
				? ($remove ? AccessLevel::Send : AccessLevel::Write)
				: (AccessLevel::tryFrom($levelName) ?? throw new \InvalidArgumentException("GOOGLE_TOOLS: unknown level '$levelName' in '$rule', expected read, write or send."));
			foreach ($levels as $name => $level) {
				if (self::serviceOf($name) === $service && $limit->includes($level)) {
					$matched[$name] = true;
				}
			}
		}

		return $matched;
	}
}
