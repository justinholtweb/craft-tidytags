<?php

namespace justinholtweb\tidytags\models;

use craft\models\Section;
use craft\models\TagGroup;

/**
 * Value object describing a tag-like source — either a native tag group or an
 * entry section that an admin has designated as tag-like (typically because
 * their tags were converted with `php craft entrify/tags`).
 *
 * Tag groups are always writable. Entry sections are read-only unless an admin
 * also opts them into `writableSectionUids`: entries carry URLs, content and
 * authorship, so rename, merge and delete stay off until someone says the
 * section is a vocabulary rather than content. Merging entries soft-deletes
 * the losing ones, so they can be restored from the trash.
 */
final class Source
{
    public const TYPE_TAG = 'tag';
    public const TYPE_ENTRY = 'entry';

    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly string $uid,
        public readonly string $name,
        public readonly string $handle,
        public readonly bool $writable = true,
    ) {}

    public static function fromTagGroup(TagGroup $group): self
    {
        return new self(
            type: self::TYPE_TAG,
            id: (int)$group->id,
            uid: (string)$group->uid,
            name: (string)$group->name,
            handle: (string)$group->handle,
        );
    }

    public static function fromSection(Section $section, bool $writable = false): self
    {
        return new self(
            type: self::TYPE_ENTRY,
            id: (int)$section->id,
            uid: (string)$section->uid,
            name: (string)$section->name,
            handle: (string)$section->handle,
            writable: $writable,
        );
    }

    /**
     * Whether rename, merge and delete are allowed on this source's items.
     */
    public function isWritable(): bool
    {
        return $this->type === self::TYPE_TAG || $this->writable;
    }

    /**
     * Whether deleting an item sends it to the trash rather than removing it
     * for good. Entries are soft-deleted so a merge can be undone; tags have
     * no URLs or content worth restoring and are deleted outright, as before.
     */
    public function softDeletes(): bool
    {
        return $this->type === self::TYPE_ENTRY;
    }

    public function typeLabel(): string
    {
        return $this->type === self::TYPE_TAG ? 'Tags' : 'Entries';
    }

    public function cpPath(): string
    {
        return $this->type === self::TYPE_TAG
            ? 'tidytags/group/' . $this->id
            : 'tidytags/section/' . $this->id;
    }
}
