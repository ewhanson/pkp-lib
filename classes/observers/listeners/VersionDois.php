<?php

declare(strict_types=1);

/**
 * @file classes/observers/listeners/VersionDois.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2000-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class VersionDois
 *
 * @ingroup observers_listeners
 *
 * @brief Listener fired when publication's published
 */

namespace PKP\observers\listeners;

use APP\facades\Repo;
use APP\publication\Publication;
use Illuminate\Events\Dispatcher;
use PKP\context\Context;
use PKP\observers\events\PublicationPublished;

class VersionDois
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(
            PublicationPublished::class,
            self::class . '@handlePublishedEvent'
        );
    }

    /**
     * Handle DOI assignment at the publication stage and versioning
     *
     * If DOI versioning is enabled, a new DOI should be created for each version. If DOI versioning is disabled,
     * DOI IDs should be transferred from the previous publication to the new, currently-being-published publication.
     */
    public function handlePublishedEvent(PublicationPublished $event): void
    {
        $submission = $event->submission;
        $newPublication = $event->publication;
        $context = $event->context;

        $doisEnabled = $context->getData(Context::SETTING_ENABLE_DOIS);

        if (!$doisEnabled) {
            return;
        }

        $shouldVersionPublication = $context->getData(Context::SETTING_DOI_VERSIONING);

        if ($shouldVersionPublication) {
            $_failureResults = Repo::submission()->createDois($submission);
        } else {
            // Explicitly exclude publication currently being published
            // as at this stage in the workflow it has been flagged as "published,"
            // but it shouldn't be considered as a "previously published" publication.
            $publishedPublications = collect($submission->getPublishedPublications())
                ->filter(fn (Publication $publication) => $publication->getId() !== $newPublication->getId())
                ->reverse();

            if ($publishedPublications->count() == 0) {
                $_failureResults = Repo::submission()->createDois($submission);
            } else {
                $previousPublication = $publishedPublications->first();
                Repo::publication()->clearOldDois($context, $previousPublication, $newPublication);

                $submission = Repo::submission()->get($submission->getId());
                Repo::submission()->createDois($submission);
            }
        }
    }
}
