<?php declare(strict_types=1);

namespace DG\Google\Gmail;


/**
 * Domain-level error from Gmail\Manager (e.g. invalid input that's the caller's fault,
 * size caps exceeded, missing-thread state, payload that violates Gmail's contract).
 * Caught explicitly by McpTools::safe so it surfaces as a tool error the model can
 * self-correct on, without the wider net of a blanket \RuntimeException catch (which
 * would also swallow genuine bugs).
 */
class Exception extends \RuntimeException
{
}
