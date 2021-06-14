<?php
/**
 * @file classes/doi/Collector.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class doi
 *
 * @brief A helper class to configure a Query Builder to get a collection of DOI
 */

namespace PKP\doi;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PKP\core\interfaces\CollectorInterface;
use PKP\plugins\HookRegistry;

class Collector implements CollectorInterface
{
    /** @var DAO */
    public $dao;

    /** @var array|null */
    public $contextIds = null;

    /** @var int */
    public $count = 30;

    /** @var int */
    public $offset = 0;

    /** @var array|null */
    public $statuses = null;

    public function __construct(DAO $dao)
    {
        $this->dao = $dao;
    }

    /**
     * Filter DOI by one or more contexts
     */
    public function filterByContextIds(array $contextIds): self
    {
        $this->contextIds = $contextIds;
        return $this;
    }

    public function filterByStatus(array $statuses): self
    {
        $this->statuses = $statuses;
        return $this;
    }


    /**
     * Limit the number of objects retrieved
     */
    public function limit(int $count): self
    {
        $this->count = $count;
        return $this;
    }

    /**
     * Offset the number of objects retrieved, for example to
     * retrieve the second page of contents
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * @copydoc collectorInterface::getQueryBuilder()
     */
    public function getQueryBuilder(): Builder
    {
        $qb = DB::table($this->dao->table . ' as d');

        if (is_array($this->contextIds)) {
            $qb->whereIn('d.context_id', $this->contextIds);
        }

        if (is_array($this->statuses)) {
            $qb->whereIn('d.status', $this->statuses);
        }

        if (!empty($this->count)) {
            $qb->limit($this->count);
        }

        if (!empty($this->offset)) {
            $qb->offset($this->count);
        }

        HookRegistry::call('Doi::Collector', [&$qb, $this]);

        return $qb;
    }
}
