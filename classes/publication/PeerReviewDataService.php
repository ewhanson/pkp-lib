<?php

/**
 * @file classes/publication/PeerReviewDataService.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PeerReviewDataService
 *
 * @ingroup publication
 *
 * @brief Service class for batch loading peer review data to optimize collection endpoints.
 */

namespace PKP\publication;

use APP\core\Application;
use APP\facades\Repo;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use PKP\context\Context;
use PKP\db\DAORegistry;
use PKP\reviewForm\ReviewFormDAO;
use PKP\reviewForm\ReviewFormElementDAO;
use PKP\reviewForm\ReviewFormResponseDAO;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submission\reviewer\recommendation\ReviewerRecommendation;
use PKP\submission\reviewRound\authorResponse\AuthorResponse;
use PKP\submission\reviewRound\ReviewRoundDAO;
use PKP\submission\SubmissionCommentDAO;

/**
 * Service class for batch loading peer review data to optimize related DB calls.
 */
class PeerReviewDataService
{
    public function __construct(
        private Repository $publicationRepository
    ) {
    }

    /**
     * Load all peer review data for multiple publications in batched queries.
     *
     * @param array $publications Array of Publication objects
     *
     * @return array Preloaded data collections keyed by entity type
     */
    public function loadBatchedPeerReviewData(array $publications): array
    {
        if (empty($publications)) {
            return $this->emptyDataStructure();
        }

        // Extract publication IDs
        $publicationIds = collect($publications)->map(fn ($p) => $p->getId())->all();

        // Get source publication IDs
        $sourcePublicationIds = $this->batchLoadPublicationSourceIds($publicationIds);
        $allPublicationIds = collect($sourcePublicationIds)->flatten()->unique()->all();

        // Get review rounds for all publications
        $reviewRoundsByPublicationId = $this->batchLoadReviewRoundsByPublications($allPublicationIds);
        $allRoundIds = $reviewRoundsByPublicationId->flatten()->map(fn ($rr) => $rr->getId())->unique()->all();

        // Get review assignments for all rounds
        $reviewAssignmentsByRoundId = $this->batchLoadReviewAssignmentsByRounds($allRoundIds);
        $allAssignments = $reviewAssignmentsByRoundId->flatten();

        // Get author responses for all rounds
        $authorResponsesByRoundId = $this->batchLoadAuthorResponsesByRounds($allRoundIds);

        // Get contexts for all submissions
        $contextsBySubmissionId = $this->batchLoadContextsBySubmissions($publications);

        // Get reviewer users (only for open reviews)
        $openReviewerIds = $allAssignments
            ->filter(fn ($ra) => $ra->getReviewMethod() === ReviewAssignment::SUBMISSION_REVIEW_METHOD_OPEN)
            ->map(fn ($ra) => $ra->getReviewerId())
            ->unique()
            ->all();
        $reviewerUsersById = $this->batchLoadReviewerUsers($openReviewerIds);

        // Get unique review forms with elements
        $reviewFormIds = $allAssignments
            ->map(fn ($ra) => $ra->getReviewFormId())
            ->filter()
            ->unique()
            ->all();

        // Get first context (review forms are context-scoped)
        $firstContext = $contextsBySubmissionId->first();
        $reviewFormsById = $this->batchLoadReviewForms($reviewFormIds, $firstContext);
        $reviewFormElementsByFormId = $this->batchLoadReviewFormElements($reviewFormIds);

        // Get review form responses for all assignments
        $reviewFormResponsesByAssignmentId = $this->batchLoadReviewFormResponses($allAssignments);

        // Get reviewer comments for assignments without review forms
        $commentsAssignmentIds = $allAssignments
            ->filter(fn ($ra) => !$ra->getReviewFormId())
            ->map(fn ($ra) => $ra->getId())
            ->all();
        $reviewerCommentsByAssignmentId = $this->batchLoadReviewerComments($commentsAssignmentIds);

        // Get reviewer recommendations per unique context
        $uniqueContextIds = $contextsBySubmissionId->map(fn ($c) => $c->getId())->unique()->all();
        $reviewerRecommendationsByContextId = $this->batchLoadReviewerRecommendations($uniqueContextIds);

        // Return it all
        return [
            'sourcePublicationIds' => $sourcePublicationIds,
            'reviewRoundsByPublicationId' => $reviewRoundsByPublicationId,
            'reviewAssignmentsByRoundId' => $reviewAssignmentsByRoundId,
            'authorResponsesByRoundId' => $authorResponsesByRoundId,
            'contextsBySubmissionId' => $contextsBySubmissionId,
            'reviewerUsersById' => $reviewerUsersById,
            'reviewFormsById' => $reviewFormsById,
            'reviewFormElementsByFormId' => $reviewFormElementsByFormId,
            'reviewFormResponsesByAssignmentId' => $reviewFormResponsesByAssignmentId,
            'reviewerCommentsByAssignmentId' => $reviewerCommentsByAssignmentId,
            'reviewerRecommendationsByContextId' => $reviewerRecommendationsByContextId,
        ];
    }

    /**
     * Return empty data structure for cases with no publications.
     */
    private function emptyDataStructure(): array
    {
        return [
            'sourcePublicationIds' => collect(),
            'reviewRoundsByPublicationId' => collect(),
            'reviewAssignmentsByRoundId' => collect(),
            'authorResponsesByRoundId' => collect(),
            'contextsBySubmissionId' => collect(),
            'reviewerUsersById' => collect(),
            'reviewFormsById' => collect(),
            'reviewFormElementsByFormId' => collect(),
            'reviewFormResponsesByAssignmentId' => collect(),
            'reviewerCommentsByAssignmentId' => collect(),
            'reviewerRecommendationsByContextId' => collect(),
        ];
    }

    /**
     * Batch load source publication IDs for multiple publications.
     */
    private function batchLoadPublicationSourceIds(array $publicationIds): Collection
    {
        $results = collect();
        foreach ($publicationIds as $pubId) {
            $results->put($pubId, $this->publicationRepository->getWithSourcePublicationsIds([$pubId])->all());
        }
        return $results;
    }

    /**
     * Batch load review rounds for multiple publications.
     */
    private function batchLoadReviewRoundsByPublications(array $publicationIds): Collection
    {
        /** @var ReviewRoundDAO $reviewRoundDao */
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
        $reviewRounds = $reviewRoundDao->getByPublicationIds($publicationIds);

        return collect($reviewRounds->toArray())
            ->groupBy(fn ($rr) => $rr->getPublicationId());
    }

    /**
     * Batch load publicly visible review assignments for multiple review rounds.
     */
    private function batchLoadReviewAssignmentsByRounds(array $roundIds): Collection
    {
        if (empty($roundIds)) {
            return collect();
        }

        $assignments = Repo::reviewAssignment()
            ->getCollector()
            ->filterByReviewRoundIds($roundIds)
            ->filterByIsPubliclyVisible(true)
            ->getMany();

        return $assignments->groupBy(fn ($ra) => $ra->getReviewRoundId());
    }

    /**
     * Batch load author responses for multiple review rounds.
     */
    private function batchLoadAuthorResponsesByRounds(array $roundIds): Collection
    {
        if (empty($roundIds)) {
            return collect();
        }

        return AuthorResponse::withReviewRoundIds($roundIds)
            ->get()
            ->groupBy('reviewRoundId');
    }

    /**
     * Batch load contexts for multiple publications' submissions.
     */
    private function batchLoadContextsBySubmissions(array $publications): Collection
    {
        $submissionIds = collect($publications)->map(fn ($p) => $p->getData('submissionId'))->unique()->all();

        $submissions = Repo::submission()->getCollector()
            ->filterBySubmissionIds($submissionIds)
            ->getMany();

        $contextIds = $submissions->map(fn ($s) => $s->getData('contextId'))->unique()->all();

        $contexts = collect();
        foreach ($contextIds as $contextId) {
            $contexts->put($contextId, app()->get('context')->get($contextId));
        }

        // Key by submission ID
        return $submissions->mapWithKeys(function ($submission) use ($contexts) {
            return [$submission->getId() => $contexts->get($submission->getData('contextId'))];
        });
    }

    /**
     * Batch load user objects with affiliations for reviewers.
     */
    private function batchLoadReviewerUsers(array $reviewerIds): Collection
    {
        if (empty($reviewerIds)) {
            return collect();
        }

        $users = Repo::user()->getCollector()
            ->filterByUserIds($reviewerIds)
            ->getMany();

        return $users->keyBy(fn ($user) => $user->getId());
    }

    /**
     * Batch load review forms for multiple form IDs.
     */
    private function batchLoadReviewForms(array $formIds, ?Context $context): Collection
    {
        if (empty($formIds) || !$context) {
            return collect();
        }

        /** @var ReviewFormDAO $reviewFormDao */
        $reviewFormDao = DAORegistry::getDAO('ReviewFormDAO');

        $forms = collect();
        foreach ($formIds as $formId) {
            $form = $reviewFormDao->getById($formId, Application::getContextAssocType(), $context->getId());
            if ($form) {
                $forms->put($formId, $form);
            }
        }

        return $forms;
    }

    /**
     * Batch load review form elements for multiple forms.
     */
    private function batchLoadReviewFormElements(array $formIds): Collection
    {
        if (empty($formIds)) {
            return collect();
        }

        /** @var ReviewFormElementDAO $reviewFormElementDao */
        $reviewFormElementDao = DAORegistry::getDAO('ReviewFormElementDAO');

        $elementsByFormId = collect();
        foreach ($formIds as $formId) {
            $elements = $reviewFormElementDao->getByReviewFormId($formId);
            $elementsList = collect();
            while ($element = $elements->next()) {
                $elementsList->push($element);
            }
            $elementsByFormId->put($formId, $elementsList);
        }

        return $elementsByFormId;
    }

    /**
     * Batch load review form responses for multiple assignments.
     */
    private function batchLoadReviewFormResponses(Enumerable $assignments): Collection
    {
        /** @var ReviewFormResponseDAO $reviewFormResponseDao */
        $reviewFormResponseDao = DAORegistry::getDAO('ReviewFormResponseDAO');

        $responsesByAssignmentId = collect();
        foreach ($assignments as $assignment) {
            if ($assignment->getReviewFormId()) {
                $responses = $reviewFormResponseDao->getReviewReviewFormResponseValues($assignment->getId());
                $responsesByAssignmentId->put($assignment->getId(), $responses);
            }
        }

        return $responsesByAssignmentId;
    }

    /**
     * Batch load reviewer comments for assignments without review forms.
     */
    private function batchLoadReviewerComments(array $assignmentIds): Collection
    {
        if (empty($assignmentIds)) {
            return collect();
        }

        /** @var SubmissionCommentDAO $submissionCommentDao */
        $submissionCommentDao = DAORegistry::getDAO('SubmissionCommentDAO');

        $commentsByAssignmentId = collect();

        foreach ($assignmentIds as $assignmentId) {
            $assignment = Repo::reviewAssignment()->get($assignmentId);
            if ($assignment) {
                $comments = $submissionCommentDao->getReviewerCommentsByReviewerId(
                    $assignment->getSubmissionId(),
                    $assignment->getReviewerId(),
                    $assignment->getId(),
                    true
                );

                $commentsList = collect();
                while ($comment = $comments->next()) {
                    $commentsList->push($comment->getComments());
                }
                $commentsByAssignmentId->put($assignmentId, $commentsList->all());
            }
        }

        return $commentsByAssignmentId;
    }

    /**
     * Batch load reviewer recommendations for multiple contexts.
     */
    private function batchLoadReviewerRecommendations(array $contextIds): Collection
    {
        $recommendationsByContextId = collect();

        foreach ($contextIds as $contextId) {
            $recommendations = ReviewerRecommendation::withContextId($contextId)
                ->get()
                ->keyBy('reviewerRecommendationId');
            $recommendationsByContextId->put($contextId, $recommendations);
        }

        return $recommendationsByContextId;
    }
}
