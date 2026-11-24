<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Data;

use CraftCms\Cms\Section\Data\Section;

/**
 * A tag-like source: a channel section an admin has marked as a vocabulary.
 *
 * Read-only unless the section is also in `writableSectionUids`. Entries carry URLs, content and
 * authors, so rename, merge and delete stay off until someone says the section is a vocabulary
 * rather than content. Merged and deleted entries go to the trash.
 */
final readonly class Source
{
    /** Kept for templates and JSON written against 5.x, where tag groups were sources too. */
    public const string TYPE_ENTRY = 'entry';

    public string $type;

    public function __construct(
        public int $id,
        public string $uid,
        public string $name,
        public string $handle,
        public bool $writable = false,
    ) {
        $this->type = self::TYPE_ENTRY;
    }

    public static function fromSection(Section $section, bool $writable = false): self
    {
        return new self(
            id: (int) $section->id,
            uid: (string) $section->uid,
            name: (string) $section->name,
            handle: (string) $section->handle,
            writable: $writable,
        );
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function typeLabel(): string
    {
        return 'Entries';
    }

    public function cpPath(): string
    {
        return 'tidytags/section/'.$this->id;
    }
}
