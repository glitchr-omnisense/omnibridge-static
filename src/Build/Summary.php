<?php

namespace Omnibridge\Static\Build;

/**
 * What an export did to reach its files, beside what it wrote (Report): the
 * renderings it made or took from the cache, and what changed in the folder.
 * Not written in omnibridge-static.json: a full export and an incremental one
 * of the same application write the same report.
 */
final class Summary
{
    public const FULL = 'full';
    public const INCREMENTAL = 'incremental';

    /**
     * @param string      $mode       self::FULL (no answer taken from the cache, the folder rewritten) or self::INCREMENTAL
     * @param string|null $why        why a full export: asked (--full), or the earlier export made by another version
     * @param int         $rendered   addresses rendered (pages, redirections, 404.html, the files the pages refer to)
     * @param int         $reused     addresses whose answer was taken from the cache: their inputs unchanged
     * @param int         $targeted   of the rendered, those an --only pattern names
     * @param int         $undeclared of the rendered, those whose inputs are not declared (Site::dependsOn()): nothing
     *                                proves them unaffected, so they are rendered every time
     * @param int         $written    files of the folder written (new or changed)
     * @param int         $unchanged  files left as they were: the same bytes
     * @param int         $removed    files of the earlier export no longer exported, removed
     * @param int|null    $matched    the addresses the --only patterns matched (null: no --only)
     */
    public function __construct(
        public readonly string $mode,
        public readonly ?string $why,
        public readonly int $rendered,
        public readonly int $reused,
        public readonly int $targeted,
        public readonly int $undeclared,
        public readonly int $written,
        public readonly int $unchanged,
        public readonly int $removed,
        public readonly ?int $matched = null,
    ) {
    }

    public function isIncremental(): bool
    {
        return self::INCREMENTAL === $this->mode;
    }

    /** One line: "incremental: 3 rendered, 40 reused; 2 written, 41 unchanged, 1 removed". */
    public function describe(): string
    {
        return \sprintf(
            '%s%s: %d rendered, %d reused%s; %d written, %d unchanged, %d removed',
            $this->mode,
            null !== $this->why ? ' ('.$this->why.')' : '',
            $this->rendered,
            $this->reused,
            $this->undeclared > 0 ? \sprintf(' (%d rendered for want of declared inputs)', $this->undeclared) : '',
            $this->written,
            $this->unchanged,
            $this->removed,
        );
    }
}
