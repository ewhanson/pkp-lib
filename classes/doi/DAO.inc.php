<?php

/**
 * @file classes/doi/DAO.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DoiDAO
 * @ingroup doi
 *
 * @see Doi
 *
 * @brief Operations for retrieving and modifying Doi objects.
 */

namespace PKP\doi;

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use PKP\core\DataObject;
use PKP\services\PKPSchemaService;
use stdClass;

class DAO extends \PKP\core\EntityDAO
{
    /** @copydoc EntityDAO::$schema */
    public $schema = PKPSchemaService::SCHEMA_DOI;

    /** @copydoc EntityDAO::$table */
    public $table = 'dois';

    /** @copydoc EntityDAO::$settingsTable */
    public $settingsTable = 'doi_settings';

    /** @copydoc EntityDAO::$primaryKeyColumn */
    public $primaryKeyColumn = 'doi_id';

    /** @copydoc EntityDAO::$primaryTableColumns */
    public $primaryTableColumns = [
        'id' => 'doi_id',
        'contextId' => 'context_id',
        'doi' => 'doi',
        'status' => 'status'
    ];

    /**
     * Instantiate a new DataObject
     */
    public function newDataObject(): Doi
    {
        return App::make(Doi::class);
    }

    /**
     * @copydoc EntityDAO::get()
     */
    public function get(int $id): ?Doi
    {
        return parent::get($id);
    }

    /**
     * Get the number of DOIs matching the configured query
     */
    public function getCount(Collector $query): int
    {
        return $query
            ->getQueryBuilder()
            ->select('d' . $this->primaryKeyColumn)
            ->get()
            ->count();
    }

    /**
     * Get a list of ids matching the configured query
     */
    public function getIds(Collector $query): Collection
    {
        return $query
            ->getQueryBuilder()
            ->select('d' . $this->primaryKeyColumn)
            ->pluck('d' . $this->primaryKeyColumn);
    }

    /**
     * Get a collection of DOIs matching the configured query
     */
    public function getMany(Collector $query): LazyCollection
    {
        $rows = $query
            ->getQueryBuilder()
            ->select(['d.*'])
            ->get();

        return LazyCollection::make(function () use ($rows) {
            foreach ($rows as $row) {
                yield $this->fromRow($row);
            }
        });
    }

    /**
     * Get submission ids that have a matching setting
     *
     * @param $settingValue
     *
     */
    public function getIdsBySetting(string $settingName, $settingValue, int $contextId): Enumerable
    {
        return DB::table($this->table . 'as d')
            ->join($this->settingsTable . 'as ds', 'd.doi_id', '=', 'ds.doi_id')
            ->where('d.setting_name', '=', $settingName)
            ->when('d.setting_value', '=', $settingValue)
            ->where('d.context_id', '=', (int) $contextId)
            ->select('d.doi_id')
            ->pluck('d.doi_id');
    }

    /**
     * @copydoc EntityDAO::fromRow()
     */
    public function fromRow(stdClass $row): Doi
    {
        return parent::fromRow($row);
    }

    /**
     * @copydoc EntityDAO::insert()
     */
    public function insert(Doi $doi): int
    {
        return parent::_insert($doi);
    }

    /**
     * @copydoc EntityDAO::update()
     */
    public function update(Doi $doi)
    {
        parent::_update($doi);
    }

    /**
     * @copydoc EntityDAO::delete()
     */
    public function delete(Doi $doi)
    {
        parent::_delete($doi);
    }
}
