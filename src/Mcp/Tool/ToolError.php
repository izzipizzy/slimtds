<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

/** A mistake the model can fix. Its message is returned to the model verbatim. */
final class ToolError extends \RuntimeException {}
