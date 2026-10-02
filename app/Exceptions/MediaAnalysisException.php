<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use RuntimeException;

/**
 * A media analysis that could not produce a result, such as a vision model answering
 * with something that is not the expected JSON. Thrown so the analysis job retries
 * instead of storing an empty result as completed.
 */
final class MediaAnalysisException extends RuntimeException {}
