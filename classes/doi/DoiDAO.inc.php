<?php

/**
 * @file classes/doi/DoiDAO.inc.php
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

use PKP\db\SchemaDAO;
use PKP\services\PKPSchemaService;

class DoiDAO extends SchemaDAO
{
    /** @var string One of the SCHEMA_... constants */
    public $schemaName = PKPSchemaService::SCHEMA_DOI;

    /** @var string The name of the primary table for this object */
    public $tableName = 'dois';

    /** @var string The name of the settings table for this object */
    public $settingsTableName = 'doi_settings';

    /** @var string The column name for the object id in primary and settings tables */
    public $primaryKeyColumn = 'doi_id';

    /** @var array Maps schema properties for the primary table to their column names */
    public $primaryTableColumns = [
        'id' => 'doi_id',
        'contextId' => 'context_id',
        'doi' => 'doi',
        'status' => 'status'
    ];

    /**
     * @inheritDoc
     */
    public function newDataObject()
    {
        return new Doi();
    }

    public function getInsertId()
    {
        return $this->_getInsertId('dois', 'doi_id');
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\PKP\doi\DoiDAO', '\DoiDAO');
}
