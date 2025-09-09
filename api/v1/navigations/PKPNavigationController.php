<?php

/**
 * @file api/v1/navigations/PKPNavigationController.php
 *
 * Copyright (c) 2023-2025 Simon Fraser University
 * Copyright (c) 2023-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PKPNavigationController
 *
 * @ingroup api_v1_navigation
 *
 * @brief Handle API requests for navigation operations.
 *
 */

namespace PKP\API\v1\navigations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use PKP\core\PKPBaseController;
use PKP\db\DAORegistry;
use PKP\navigationMenu\NavigationMenuDAO;
use PKP\navigationMenu\NavigationMenuItem;
use PKP\navigationMenu\NavigationMenuItemAssignmentDAO;
use PKP\navigationMenu\NavigationMenuItemDAO;

class PKPNavigationController extends PKPBaseController
{
    /** @var array Routes that can be accessed without user authentication */
    public array $publicAccessRoutes = [
        'get', // Allow public access to navigation endpoint
    ];


    /**
     * @copydoc \PKP\core\PKPBaseController::getHandlerPath()
     */
    public function getHandlerPath(): string
    {
        return 'navigations';
    }

    /**
     * @copydoc \PKP\core\PKPBaseController::getRouteGroupMiddleware()
     */
    public function getRouteGroupMiddleware(): array
    {
        return [
            'has.context',
        ];
    }

    /**
     * @copydoc \PKP\core\PKPBaseController::getGroupRoutes()
     */
    public function getGroupRoutes(): void
    {
        Route::get('{navigationId}/public', $this->getPublic(...))
            ->name('navigation.get')
            ->whereNumber('navigationId');
    }

    /**
     * Get navigation menu by ID with formatted menu items and nesting
     */
    public function getPublic(Request $illuminateRequest): JsonResponse
    {
        $navigationId = (int) $illuminateRequest->route('navigationId');
        $request = $this->getRequest();
        $context = $request->getContext();
        $contextId = $context->getId();
        $locale = $context->getPrimaryLocale();

        /** @var NavigationMenuDAO */
        $navigationMenuDao = DAORegistry::getDAO('NavigationMenuDAO');
        $navigationMenu = $navigationMenuDao->getById($navigationId, $contextId);

        if (!$navigationMenu) {
            return response()->json([
                'error' => 'Navigation menu not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $formattedItems = $this->formatNavigationItems($navigationId, $locale);

        return response()->json([
            'id' => $navigationMenu->getId(),
            'title' => $navigationMenu->getTitle(),
            'area_name' => $navigationMenu->getAreaName(),
            'context_id' => $navigationMenu->getContextId(),
            'items' => $formattedItems,
        ], Response::HTTP_OK);
    }

    /**
     * Format navigation items with parent-child relationships and localized content
     */
    private function formatNavigationItems(int $navigationMenuId, string $locale): array
    {
        /** @var NavigationMenuItemAssignmentDAO */
        $assignmentDao = DAORegistry::getDAO('NavigationMenuItemAssignmentDAO');
        /** @var NavigationMenuItemDAO */
        $itemDao = DAORegistry::getDAO('NavigationMenuItemDAO');

        $assignments = $assignmentDao->getByMenuId($navigationMenuId);
        $items = [];
        $parentMap = [];

        while ($assignment = $assignments->next()) {
            $itemId = $assignment->getMenuItemId();
            $parentId = $assignment->getParentId();

            $navigationItem = $itemDao->getById($itemId);
            if (!$navigationItem) {
                continue;
            }

            $formattedItem = [
                'id' => $itemId,
                'title' => $this->getLocalizedSetting($navigationItem, 'title', $locale),
                'path' => $navigationItem->getPath() ?: $this->getLocalizedSetting($navigationItem, 'remoteUrl', $locale),
                'type' => $navigationItem->getType(),
                'sequence' => $assignment->getSequence(),
                'children' => []
            ];

            if ($parentId) {
                if (!isset($parentMap[$parentId])) {
                    $parentMap[$parentId] = [];
                }
                $parentMap[$parentId][] = $formattedItem;
            } else {
                $items[] = $formattedItem;
            }
        }

        foreach ($items as &$item) {
            if (isset($parentMap[$item['id']])) {
                $item['children'] = $parentMap[$item['id']];
                // Sort children by sequence
                usort($item['children'], function($a, $b) {
                    return $a['sequence'] <=> $b['sequence'];
                });
            }
        }

        usort($items, function($a, $b) {
            return $a['sequence'] <=> $b['sequence'];
        });

        return $items;
    }

    /**
     * Get localized setting value for a navigation item
     */
    private function getLocalizedSetting(NavigationMenuItem $item, string $settingName, string $locale): string
    {
        $value = $item->getLocalizedData($settingName, $locale);

        // Fallback to title locale key if no localized value found
        if (empty($value) && $settingName === 'title') {
            $titleLocaleKey = $item->getData('titleLocaleKey');
            if ($titleLocaleKey) {
                // Try to get from locale files or return the key itself as fallback
                $value = $titleLocaleKey;
            }
        }

        return $value ?: '';
    }
}
