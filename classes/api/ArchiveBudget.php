<?php

namespace SRAG\PegasusHelper\api;

/**
 * Class ArchiveBudget
 *
 * Tracks running totals while {@see LearningModuleZipBuilder} repacks a
 * learning-module archive, and throws as soon as any limit is crossed --
 * *before* the offending entry's content is actually read/decompressed into
 * memory. Guards against a maliciously (or just very badly) sized module
 * archive exhausting server memory or disk during repacking (SEC-10):
 *  - too many entries
 *  - a single entry that decompresses to an implausible size
 *  - the whole archive decompressing to an implausible total size
 *  - a small compressed entry that expands far beyond a plausible ratio
 *    (a classic zip-bomb pattern), checked only once an entry is already
 *    large enough that the ratio is meaningful
 *
 * @author  Jakub Niewelt <jakub@deepeacademy.com>
 */
final class ArchiveBudget
{
    /**
     * @var int
     */
    private $maxEntries;

    /**
     * @var int
     */
    private $maxEntryBytes;

    /**
     * @var int
     */
    private $maxTotalBytes;

    /**
     * @var int
     */
    private $ratioCap;

    /**
     * @var int entries smaller than this (uncompressed) are never ratio-checked
     */
    private $ratioCheckThresholdBytes;

    /**
     * @var int
     */
    private $entries = 0;

    /**
     * @var int
     */
    private $totalBytes = 0;

    public function __construct(int $maxEntries, int $maxEntryBytes, int $maxTotalBytes, int $ratioCap, int $ratioCheckThresholdBytes)
    {
        $this->maxEntries = $maxEntries;
        $this->maxEntryBytes = $maxEntryBytes;
        $this->maxTotalBytes = $maxTotalBytes;
        $this->ratioCap = $ratioCap;
        $this->ratioCheckThresholdBytes = $ratioCheckThresholdBytes;
    }

    /**
     * Accounts for one more entry. Call this *before* reading/decompressing
     * the entry's content.
     *
     * @param int $uncompressedBytes
     * @param int $compressedBytes    pass the same value as $uncompressedBytes
     *                                for an entry with no separate compressed
     *                                size (e.g. a plain filesystem file), which
     *                                always yields a 1:1 ratio and so never
     *                                trips the ratio check
     *
     * @throws ApiException 500 (reason 'archive_budget_exceeded') if any limit is exceeded
     */
    public function account(int $uncompressedBytes, int $compressedBytes): void
    {
        $this->entries++;
        if ($this->entries > $this->maxEntries) {
            throw ApiException::serverError('Learning module archive has too many entries')->withReason('archive_budget_exceeded');
        }

        if ($uncompressedBytes > $this->maxEntryBytes) {
            throw ApiException::serverError('Learning module archive entry is too large')->withReason('archive_budget_exceeded');
        }

        $this->totalBytes += max(0, $uncompressedBytes);
        if ($this->totalBytes > $this->maxTotalBytes) {
            throw ApiException::serverError('Learning module archive is too large')->withReason('archive_budget_exceeded');
        }

        if ($uncompressedBytes > $this->ratioCheckThresholdBytes && $compressedBytes > 0) {
            $ratio = $uncompressedBytes / $compressedBytes;
            if ($ratio > $this->ratioCap) {
                throw ApiException::serverError('Learning module archive entry expands suspiciously')->withReason('archive_budget_exceeded');
            }
        }
    }
}
