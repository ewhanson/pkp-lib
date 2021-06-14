<?php
/**
 * @file classes/doi/Repository.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2000-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class doi
 *
 * @brief A repository to find and manage DOIs.
 */

namespace PKP\doi;

use APP\core\Request;
use APP\core\Services;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\LazyCollection;
use PKP\plugins\HookRegistry;
use PKP\services\PKPSchemaService;
use PKP\validation\ValidatorFactory;

class Repository
{
    /** @var DAO $dao */
    public $dao;

    /** @var string $schemaMap The name of the class to map this entity to its schema */
    public $schemaMap = maps\Schema::class;

    /** @var Request $request */
    protected $request;

    /** @var PKPSchemaService $schemaService */
    protected $schemaService;

    public function __construct(DAO $dao, Request $request, PKPSchemaService $schemaService)
    {
        $this->dao = $dao;
        $this->request = $request;
        $this->schemaService = $schemaService;
    }

    /** @copydoc DAO::newDataObject() */
    public function newDataObject(array $params = []): Doi
    {
        $doi = $this->dao->newDataObject();
        if (!empty($params)) {
            $doi->setAllData($params);
        }
        return $doi;
    }

    /** @copydoc::get() */
    public function get(int $id): ?Doi
    {
        return $this->dao->get($id);
    }

    /** @copydoc DAO::getCount() */
    public function getCount(Collector $query): int
    {
        return $this->dao->getCount($query);
    }

    /** @copydoc DAO::getIds() */
    public function getIds(Collector $query): Collection
    {
        return $this->dao->getIds($query);
    }

    /** @copydoc DAO::getMany() */
    public function getMany(Collector $query): LazyCollection
    {
        return $this->dao->getMany($query);
    }

    /** @copydoc DAO::getCollector */
    public function getCollector(): Collector
    {
        return App::make(Collector::class);
    }

    /**
     * Get an instance of the map class for mapping
     * DOIs to their schema
     */
    public function getSchemaMap(): maps\Schema
    {
        return app('maps')->withExtensions($this->schemaMap);
    }

    /** @copydoc DAO::getBySetting() */
    public function getIdsBySetting(string $settingName, $settingValue, int $contextId): Enumerable
    {
        return $this->dao->getIdsBySetting($settingName, $settingValue, $contextId);
    }

    /**
     * Validate properties for a Doi
     *
     * Perform validation checks on data used to add or edit a Doi
     *
     * @param array $props A key/value array with the new data to validate
     * @param array $allowedLocales The context's supported locales
     * @param string $primaryLocale The context's primary locale
     *
     * @throws Exception
     *
     * @return array A key/value array with validation errors. Empty if no errors
     */
    public function validate(?Doi $doi, array $props, array $allowedLocales, string $primaryLocale): array
    {
        $errors = [];

        $validator = ValidatorFactory::make(
            $props,
            $this->schemaService->getValidationRules(PKPSchemaService::SCHEMA_DOI, $allowedLocales),
        );

        // Check required fields
        ValidatorFactory::required(
            $validator,
            $doi,
            $this->schemaService->getRequiredProps(PKPSchemaService::SCHEMA_DOI),
            $this->schemaService->getMultilingualProps(PKPSchemaService::SCHEMA_DOI),
            $primaryLocale,
            $allowedLocales
        );

        // Check for input from disallowed locales
        ValidatorFactory::allowedLocales($validator, $this->schemaService->getMultilingualProps(PKPSchemaService::SCHEMA_DOI), $allowedLocales);

        // The contextId must match an existing context
        $validator->after(function ($validator) use ($props) {
            if (isset($props['contextId']) && !$validator->errors()->get('contextId')) {
                $doiContext = Services::get('context')->get($props['contextId']);
                if (!$doiContext) {
                    $validator->errors()->add('contextId', __('doi.submit.noContext'));
                }
            }
        });

        if ($validator->fails()) {
            $errors = $this->schemaService->formatValidationErrors($validator->errors(), $this->schemaService->get(PKPSchemaService::SCHEMA_DOI), $allowedLocales);
        }

        HookRegistry::call('Doi::validate', [&$errors, $doi, $props, $allowedLocales, $primaryLocale]);

        return $errors;
    }

    /** @copydoc DAO::insert() */
    public function add(Doi $doi): int
    {
        $id = $this->dao->insert($doi);
        HookRegistry::call('Doi::add', [$doi]);

        return $id;
    }

    /** @copydoc DAO:update() */
    public function edit(Doi $doi, array $params)
    {
        $newDoi = clone $doi;
        $newDoi->setAllData(array_merge($newDoi->_data, $params));

        HookRegistry::call('Doi::edit', [$newDoi, $doi, $params]);

        $this->dao->update($newDoi);
    }

    /** @copydoc DAO::delete() */
    public function delete(Doi $doi)
    {
        HookRegistry::call('Doi::delete::before', [$doi]);
        $this->dao->delete($doi);
        HookRegistry::call('Doi::delete', [$doi]);
    }

    /**
     * Delete a collection of DOIs
     */
    public function deleteMany(Collector $collector)
    {
        $dois = $this->getMany($collector);
        foreach ($dois as $doi) {
            $this->delete($doi);
        }
    }
}
