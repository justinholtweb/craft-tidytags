<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Listeners;

use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Entry\Elements\Entry;
use JustinHoltWeb\TidyTags\Services\DuplicateDetector;

/**
 * Retires cached duplicate scans when a canonical entry is saved or deleted, so the Duplicates
 * screen and the "did you mean?" warning never answer from stale titles. Drafts and revisions
 * don't change what a scan sees, so they're ignored.
 */
class ForgetScans
{
    public function __construct(private readonly DuplicateDetector $detector) {}

    public function handle(ElementSaved|ElementDeleted $event): void
    {
        $element = $event->element;

        if ($element instanceof Entry && ! $element->getIsDraft() && ! $element->getIsRevision()) {
            $this->detector->forget();
        }
    }
}
