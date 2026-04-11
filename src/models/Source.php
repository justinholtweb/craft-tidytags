<?php

namespace justinholtweb\tidytags\models;

use craft\models\Section;
use craft\models\TagGroup;

/**
 * Value object describing a tag-like source — either a native tag group or an
 * entry section that an admin has designated as tag-like (typically because
 * their tags were converted with `php craft entrify/tags`).
 *
 * Entry sources are read-only within Tidy Tags: rename, merge, and delete
 * operate on Tag elements only, because entries carry URLs, bodies, drafts,
 * and authorship that this plugin cannot safely reason about.
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

    public static function fromSection(Section $section): self
    {
        return new self(
            type: self::TYPE_ENTRY,
            id: (int)$section->id,
            uid: (string)$section->uid,
            name: (string)$section->name,
            handle: (string)$section->handle,
        );
    }

    public function isWritable(): bool
    {
        return $this->type === self::TYPE_TAG;
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
