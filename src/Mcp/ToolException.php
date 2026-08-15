<?php

declare(strict_types=1);

namespace Promises\Mcp;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * A tool could not do what was asked.
 *
 * Distinct from a protocol error on purpose. MCP draws the line like this: if
 * the *request* was malformed — unknown method, missing params — that is a
 * JSON-RPC error and the model never sees it. If the request was well formed
 * but the work failed — no member with that id, slot already taken — that is a
 * successful response carrying isError: true, because the model is supposed to
 * read it and try something else.
 *
 * Throwing this puts a failure in the second category. Anything else escaping
 * a tool is caught by Server and reported the same way, since a model can act
 * on "that didn't work" far better than the caller can act on a 500.
 */
class ToolException extends \RuntimeException
{
}
