<?php

declare(strict_types=1);

namespace Promises\Mcp;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The request named a method that exists, but its params are unusable.
 *
 * Becomes a JSON-RPC -32602, which the model never sees — the client is
 * expected to fix its own call. Contrast ToolException, which is a tool
 * declining work it understood and which the model *is* meant to read.
 */
class InvalidParamsException extends \InvalidArgumentException
{
}
