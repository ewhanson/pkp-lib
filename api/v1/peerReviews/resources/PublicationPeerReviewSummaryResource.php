<?php

/**
 * @file api/v1/peerReviews/resources/PublicationPeerReviewSummaryResource.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING
 *
 * @class PublicationPeerReviewSummaryResource
 *
 * @ingroup api_v1_peerReviews
 *
 * @brief Resource that maps a publication to a summary of its peer reviews.
 */

namespace PKP\API\v1\peerReviews\resources;

use APP\core\Application;
use APP\facades\Repo;
use APP\publication\Publication;
use Illuminate\Http\Resources\Json\JsonResource;
use PKP\context\Context;
use PKP\db\DAORegistry;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submission\reviewRound\ReviewRound;
use PKP\submission\reviewRound\ReviewRoundDAO;

class PublicationPeerReviewSummaryResource extends JsonResource
{
    use ReviewerRecommendationSummary;

    /** @var array|null Preloaded data to excess DB queries */
    private ?array $preloadedData = null;

    /**
     * Inject preloaded data into the resource.
     *
     * @param array $data Preloaded collections from repository batch loading
     * PR_TODO: Add note about source of preloaded data
     * 
     * @return self
     */
    public function withPreloadedData(array $data): self
    {
        $this->preloadedData = $data;
        return $this;
    }

    public function toArray(\Illuminate\Http\Request $request)
    {
        /** @var Publication $publication */
        $publication = $this->resource;

        if ($this->preloadedData) {
            // Use preloaded data
            $context = $this->preloadedData['contextsBySubmissionId']->get($publication->getData('submissionId'));
            // PR_TODO: This isn't used in this code path.
            $allAssociatedPublicationIds = $this->preloadedData['sourcePublicationIds']->get($publication->getId(), [$publication->getId()]);
            $reviewRounds = $this->preloadedData['reviewRoundsByPublicationId']->get($publication->getId(), collect());

            $reviewAssignments = collect();
            foreach ($reviewRounds as $round) {
                $assignments = $this->preloadedData['reviewAssignmentsByRoundId']->get($round->getId(), collect());
                $reviewAssignments = $reviewAssignments->merge($assignments);
            }
        } else {
            // Fallback to naive behavior
            $submission = Repo::submission()->get($publication->getData('submissionId'));
            $contextDao = Application::getContextDAO();
            /** @var Context $context */
            $context = $contextDao->getById($submission->getData('contextId'));

            $allAssociatedPublicationIds = Repo::publication()->getWithSourcePublicationsIds([$publication->getId()])->all();

            // Include reviews from the Publication's Source Publication so that reviews that are to be copied forward are accounted for.
            $reviewAssignments = Repo::reviewAssignment()->getCollector()
                ->filterByIsPubliclyVisible(true)
                ->filterByPublicationIds($allAssociatedPublicationIds)
                ->getMany();

            /** @var ReviewRoundDAO $reviewRoundDao */
            $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
            $reviewRounds = collect($reviewRoundDao->getByPublicationIds($allAssociatedPublicationIds)->toArray());
        }

        $reviewRoundsKeyedById = $reviewRounds->keyBy(fn ($rr) => $rr->getId());

        $assignmentsByRound = $reviewAssignments
            ->groupBy(fn (ReviewAssignment $ra) => $ra->getReviewRoundId())
            ->sortKeys();

        $roundsData = [];
        /** @var ReviewRound $reviewRound */
        foreach ($reviewRoundsKeyedById as $roundId => $reviewRound) {
            $roundAssignments = $assignmentsByRound->get($roundId, collect());
            $publicStatus = $reviewRound->getPublicReviewStatus($roundAssignments);

            $roundsData[] = [
                'roundId' => $reviewRound->getId(),
                'round' => $reviewRound->getRound(),
                'status' => $publicStatus['status']->value,
                'dateStarted' => $publicStatus['dateStarted'],
                'dateInProgress' => $publicStatus['dateInProgress'],
                'dateCompleted' => $publicStatus['dateCompleted'],
            ];
        }

        $publishedPublications = $submission->getPublishedPublications();

        return [
            'publicationId' => $publication->getId(),
            'reviewerRecommendations' => $this->getReviewerRecommendationsSummary($reviewAssignments, $context),
            // Number of published versions of the publication's submission
            'submissionPublishedVersionsCount' => count($publishedPublications),
            'reviewerCount' => $this->getReviewerCount($reviewAssignments),
            // Latest published publication for the submission associated with this publication
            'submissionCurrentVersion' => $this->getSubmissionLatestPublishedPublication($submission),
            'reviewRounds' => $roundsData,
        ];
    }
}
