<?php

/**
 * @defgroup announcement Announcement
 * Implements announcements that can be presented to website visitors.
 */

/**
 * @file classes/doi/Doi.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Doi
 * @ingroup doi
 *
 * @see DoiDAO
 *
 * @brief Basic class describing a DOI.
 */

namespace PKP\doi;

use PKP\core\DataObject;

class Doi extends DataObject
{
    //
    // Get/set methods
    //

    /**
     * Get ID of context.
     *
     * @return int
     */
    public function getContextId()
    {
        return $this->getData('contextId');
    }

    /**
     * Set ID of context.
     *
     * @param $contextId int
     */
    public function setContextId($contextId)
    {
        $this->setData('contextId', $contextId);
    }

    /**
     * Get DOI for this DOI
     *
     * @return string
     */
    public function getDoi()
    {
        return $this->getData('doi');
    }

    /**
     * Set DOI for this DOI
     *
     * @param $doi string
     */
    public function setDoi($doi)
    {
        $this->setData('doi', $doi);
    }

    /**
     * Get status for this DOI
     *
     * @return int
     */
    public function getStatus()
    {
        return $this->getData('status');
    }

    /**
     * Set status for this DOI
     *
     * @param $status int
     */
    public function setStatus($status)
    {
        $this->setData('status', $status);
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\PKP\doi\Doi', '\Doi');
}
