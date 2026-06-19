<?php declare(strict_types=1);

namespace DG\Google;

use Google\Service\Exception as GoogleException;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\ToolCallException;
use Nette\Utils\AssertionException;


/**
 * Centralizes the error→ToolCallException conversion for every MCP tool in one place, so the
 * individual McpTools methods don't each have to wrap their body in a `safe()` closure. Decorates
 * the SDK's ReferenceHandler (set via Server\Builder::setReferenceHandler) and converts anything a
 * tool body throws — including failures from the SDK's own argument casting — into a
 * ToolCallException, which the SDK renders as a CallToolResult with isError=true so the model can
 * self-correct. Without this, an unexpected \Throwable escaping a tool becomes an opaque JSON-RPC
 * -32603 ("Error while executing tool") with no message.
 *
 * Conversion rules (first match wins):
 *   - ToolCallException: already shaped by the tool itself (auth via getManager, the send-gate, the
 *     attachment sandbox) — rethrown unchanged.
 *   - Google\Service\Exception: the diagnostic $onApiError hook fires (token-snapshot logging),
 *     then it becomes "Google API error: <upstream message>".
 *   - InvalidArgumentException / Nette AssertionException: caller-input errors are self-explanatory,
 *     so the clean message is forwarded without debug noise.
 *   - any other \Throwable (domain Exception, TypeError, "method on null", a bare RuntimeException):
 *     wrapped with message + class + file:line so genuine bugs surface with context.
 */
final class McpToolCallGuard implements ReferenceHandlerInterface
{
	/**
	 * @param ?\Closure(GoogleException): void $onApiError  Diagnostic hook invoked with every Google
	 *   API exception before it is converted, used by the server to log a token snapshot when an auth
	 *   failure (401 "Invalid Credentials") occurs in a long-running process. Null disables it.
	 */
	public function __construct(
		private readonly ReferenceHandlerInterface $handler,
		private readonly ?\Closure $onApiError = null,
	) {
	}


	/**
	 * @param array<string, mixed> $arguments
	 */
	public function handle(ElementReference $reference, array $arguments): mixed
	{
		// The typed catches are reachable (the handler runs tool code via reflection); PHPStan
		// flags them dead because the SDK interface under-declares @throws — scoped-ignored in phpstan.neon.
		try {
			return $this->handler->handle($reference, $arguments);
		} catch (ToolCallException $e) {
			// Already shaped by the tool (auth/getManager, send-gate, sandbox) — pass through.
			throw $e;
		} catch (GoogleException $e) {
			if ($this->onApiError !== null) {
				($this->onApiError)($e);
			}
			throw new ToolCallException('Google API error: ' . self::extractGoogleError($e), 0, $e);
		} catch (\InvalidArgumentException | AssertionException $e) {
			throw new ToolCallException($e->getMessage(), 0, $e);
		} catch (\Throwable $e) {
			throw new ToolCallException(
				sprintf('%s (%s in %s:%d)', $e->getMessage(), $e::class, basename($e->getFile()), $e->getLine()),
				0,
				$e,
			);
		}
	}


	private static function extractGoogleError(GoogleException $e): string
	{
		$errors = $e->getErrors();
		if (is_array($errors) && $errors) {
			$first = $errors[0];
			if (isset($first['message'])) {
				return (string) $first['message'];
			}
		}
		return $e->getMessage();
	}
}
