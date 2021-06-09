<?php

/**
 * @file api/v1/dois/PKPDoiHandler.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DoiHandler
 * @ingroup api_v1_dois
 *
 * @brief Handle API requests for DOI operations.
 *
 */

use APP\plugins\PubObjectsExportPlugin;
use PKP\handler\APIHandler;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\PublicationWritePolicy;
use PKP\security\authorization\SubmissionAccessPolicy;

use PKP\security\Role;

class PKPDoiHandler extends APIHandler
{
    /** @var DOIPubIdPlugin */
    private $_doiPubIdPlugin;

    /** @var array Handlers that must be authorized to access a submission */
    public $requiresSubmissionAccess = [];

    /** @var array Handlers that must be authorized to write to a publication */
    public $requiresPublicationWriteAccess = [];

    /** @var array Valid DOI export actions */
    private $_validActions = [
        PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT,
        PubObjectsExportPlugin::EXPORT_ACTION_EXPORT,
        PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->_handlerPath = 'dois';
        $this->_endpoints = [
            'GET' => [],
            'POST' => [],
            'PUT' => [
                [
                    'pattern' => $this->getEndpointPattern() . '/submissions/{action:\w+}',
                    'handler' => [$this, 'executeSubmissionAction'],
                    'roles' => [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
                ],
            ],
            'DELETE' => [],
        ];
        parent::__construct();
    }

    /**
     * @param \PKP\handler\Request $request
     * @param array $args
     * @param array $roleAssignments
     *
     * @return bool
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $routeName = $this->getSlimRequest()->getAttribute('route')->getName();

        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));

        if (in_array($routeName, $this->requiresSubmissionAccess)) {
            $this->addPolicy(new SubmissionAccessPolicy($request, $args, $roleAssignments));
        }

        if (in_array($routeName, $this->requiresPublicationWriteAccess)) {
            $this->addPolicy(new PublicationWritePolicy($request, $args, $roleAssignments));
        }

        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     *
     *
     * @param $slimRequest Slim\Http\Request Slim request object
     * @param $response PKP\Core\APIResponse object
     * @param $args array arguments
     */
    public function executeSubmissionAction($slimRequest, $response, $args)
    {
        // TODO: Use of this as a plugin will change when incorporated into core
        $plugin = $this->_getDoiPubIdPlugin();

        $action = $args['action'];
        $body = $slimRequest->getParsedBody();

        if (!in_array($action, $this->_validActions)) {
            return $response->withStatus(406)->withJsonError('api.dois.406.noActionIncluded');
        }

        if (empty($body) || !is_array($body)) {
            return $response->withStatus(406)->withJsonError('api.dois.406.noSubmissionsIncluded');
        }

        // TODO: Ensure initiateExportAction exists on plugin, via interface? Will change once not plugin
        $plugin->initiateExportAction($action, $body);

        return $response->withStatus(200);
    }

    /**
     * Helper method to assign reference to DOIPubIdPlugin
     * TODO: Will change when DOI functionality incorporated into core
     *
     * @return DOIPubIdPlugin|\PKP\plugins\Plugin|null
     */
    private function _getDoiPubIdPlugin()
    {
        if (empty($this->_doiPubIdPlugin)) {
            $this->_doiPubIdPlugin = PluginRegistry::getPlugin('pubIds', 'doipubidplugin');
        }
        return $this->_doiPubIdPlugin;
    }
}
