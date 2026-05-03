<?php declare(strict_types=1);

namespace DG\Google;


/**
 * Authentication / re-authorization is required (no token, refresh token revoked
 * or expired, transport failure, malformed token file). Distinct from a generic
 * \RuntimeException so that callers (notably Gmail\McpTools::getManager) can
 * convert it into a self-correctable tool error with a clear "re-authorize"
 * hint, instead of letting it crash the MCP transport.
 */
class AuthException extends \RuntimeException
{
}
