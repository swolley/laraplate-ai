<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;

/**
 * Rebuilds the documentation (RAG) indexes for an embedding profile during a model switch, in
 * three parts: {@see self::prepare()} recreates the index mappings sized for the profile's vectors
 * (once), {@see self::plan()} splits the documentation into chunks, and {@see self::writeChunk()}
 * embeds and stores the documents of one chunk with the profile. A chunk has the shape of an index
 * chunk of the switch: `model` is `rag:<documentation profile>`, `from` and `to` bound a range of
 * source names (inclusive, exclusive, open when null).
 */
interface IRagIndexRebuilder
{
    /**
     * Recreates the documentation indexes for `$target`; writes no document.
     */
    public function prepare(EmbeddingModelProfile $target): void;

    /**
     * The chunks that write every documentation document once, by an id unique in the plan; none
     * when the documentation is not rebuilt by a switch.
     *
     * @return array<string, array{model: string, from: int|string|null, to: int|string|null}>
     */
    public function plan(int $sourcesPerChunk): array;

    /**
     * Writes the documents of one chunk embedded with `$target`, replacing what the index holds for
     * each of its sources, so writing it again writes the same documents again.
     *
     * @param  array{model: string, from: int|string|null, to: int|string|null}  $chunk
     */
    public function writeChunk(EmbeddingModelProfile $target, array $chunk): void;
}
