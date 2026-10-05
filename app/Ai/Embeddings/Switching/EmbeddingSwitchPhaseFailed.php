<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use RuntimeException;

/**
 * A phase of an embedding model switch found a condition it cannot complete under: the switch is
 * marked failed in that phase with this message, without retrying.
 */
final class EmbeddingSwitchPhaseFailed extends RuntimeException {}
