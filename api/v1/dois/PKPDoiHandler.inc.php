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

use APP\facades\Repo;
use APP\plugins\PubObjectsExportPlugin;

use PKP\core\APIResponse;
use PKP\handler\APIHandler;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\PublicationWritePolicy;
use PKP\security\authorization\SubmissionAccessPolicy;
use PKP\security\Role;

use Slim\Http\Request as SlimRequest;
use Slim\Http\Response;

class PKPDoiHandler extends APIHandler
{
    /** @var int The default number of DOIs to return in one request */
    public const DEFAULT_COUNT = 30;

    /** @var int The maximum number of DOIs to return in one request */
    public const MAX_COUNT = 100;

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
            'GET' => [
                [
                    'pattern' => $this->getEndpointPattern(),
                    'handler' => [$this, 'getMany'],
                    'roles' => [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT, Role::ROLE_ID_REVIEWER, Role::ROLE_ID_AUTHOR],
                ],
                [
                    'pattern' => $this->getEndpointPattern() . '/{doiId:\d+}',
                    'handler' => [$this, 'get'],
                    'roles' => [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT, Role::ROLE_ID_REVIEWER, Role::ROLE_ID_AUTHOR],
                ],
            ],
            'POST' => [
                [
                    'pattern' => $this->getEndpointPattern(),
                    'handler' => [$this, 'add'],
                    'roles' => [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
                ],
            ],
            'PUT' => [
                [
                    'pattern' => $this->getEndpointPattern() . '/{doiId:\d+}',
                    'handler' => [$this, 'edit'],
                    'roles' => [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT, Role::ROLE_ID_REVIEWER, Role::ROLE_ID_AUTHOR],
                ],
                [
                    'pattern' => $this->getEndpointPattern() . '/submissions/{action:\w+}',
                    'handler' => [$this, 'executeSubmissionAction'],
                    'roles' => [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
                ],
            ],
            'DELETE' => [
                [
                    'pattern' => $this->getEndpointPattern() . '/{doiId:\d+}',
                    'handler' => [$this, 'delete'],
                    'roles' => [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT, Role::ROLE_ID_REVIEWER, Role::ROLE_ID_AUTHOR],
                ],
            ],
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
     * Get a single DOI
     *
     * @param SlimRequest $slimRequest Slim request object
     * @param APIResponse $response object
     * @param array $args arguments
     *
     */
    public function get(SlimRequest $slimRequest, APIResponse $response, array $args): Response
    {
        $doi = Repo::doi()->get((int) $args['doiId']);

        if (!$doi) {
            return $response->withStatus(404)->withJsonError('api.404.resourceNotFound"');
        }

        // The contextId should always point to the requested contextId
        if ($doi->getData('contextId') !== $this->getRequest()->getContext()->getId()) {
            return $response->withStatus(404)->withJsonError('api.doi.400.contextsNotMatched');
        }

        return $response->withJson(Repo::doi()->getSchemaMap()->map($doi), 200);
    }

    /**
     * Get a collection of DOIs
     *
     *
     */
    public function getMany(SlimRequest $slimRequest, APIResponse $response, array $args): Response
    {
        $collector = Repo::doi()->getCollector()
            ->limit(self::DEFAULT_COUNT)
            ->offset(0);

        foreach ($slimRequest->getQueryParams() as $param => $val) {
            switch ($param) {
                case 'count':
                    $collector->limit(min((int) $val, self::MAX_COUNT));
                    break;
                case 'offset':
                    $collector->offset((int) $val);
                    break;
                case 'status':
                    $collector->filterByStatus(array_map('intval', $this->paramToArray($val)));
                    break;
            }
        }

        $collector->filterByContextIds([$this->getRequest()->getContext()->getId()]);

        HookRegistry::call('API::dois::params', [$collector, $slimRequest]);

        $dois = Repo::doi()->getMany($collector);

        return $response->withJson(
            [
                'itemsMax' => $dois->count(),
                'items' => Repo::doi()->getSchemaMap()->summarizeMany($dois),
            ],
            200
        );
    }

    /**
     *
     * @throws Exception
     */
    public function add(SlimRequest $slimRequest, APIResponse $response, array $args): Response
    {
        $request = $this->getRequest();
        $context = $request->getContext();

        if (!$context) {
            throw new Exception('You can not add an announcement without sending a request to the API endpoint of a particular context.');
        }

        $params = $this->convertStringsToSchema(\PKP\services\PKPSchemaService::SCHEMA_DOI, $slimRequest->getParsedBody());
        $params['contextId'] = $context->getId();

        $primaryLocale = $context->getPrimaryLocale();
        $allowedLocales = $context->getSupportedFormLocales();
        $errors = Repo::doi()->validate(null, $params, $allowedLocales, $primaryLocale);

        if (!empty($errors)) {
            return $response->withStatus(400)->withJson($errors);
        }

        $doi = Repo::doi()->newDataObject($params);
        $id = Repo::doi()->add($doi);
        $doi = Repo::doi()->get($id);

        return $response->withJson(Repo::doi()->getSchemaMap()->map($doi), 200);
    }

    /**
     * Edit a DOI
     *
     *
     * @throws Exception
     */
    public function edit(SlimRequest $slimRequest, APIResponse $response, array $args): Response
    {
        $request = $this->getRequest();

        $doi = Repo::doi()->get((int) $args['doiId']);

        if (!$doi) {
            return $response->withStatus(404)->withJsonError('api.doi.404.doiNotFound');
        }

        // The contextId should always point to the requested contextId
        if ($doi->getData('contextId') !== $this->getRequest()->getContext()->getId()) {
            return $response->withStatus(403)->withJsonError('api.doi.400.contextsNotMatched');
        }

        $params = $this->convertStringsToSchema(\PKP\services\PKPSchemaService::SCHEMA_DOI, $slimRequest->getParsedBody());
        $params['id'] = $doi->getId();

        $context = $request->getContext();
        $primaryLocale = $context->getPrimaryLocale();
        $allowedLocales = $context->getSupportedFormLocales();

        $errors = Repo::doi()->validate($doi, $params, $allowedLocales, $primaryLocale);
        if (!empty($errors)) {
            return $response->withStatus(400)->withJson($errors);
        }

        Repo::doi()->edit($doi, $params);

        $doi = Repo::doi()->get($doi->getId());

        return $response->withJson(Repo::doi()->getSchemaMap()->map($doi), 200);
    }

    /**
     * Delete a DOI
     *
     *
     */
    public function delete(SlimRequest $slimRequest, APIResponse $response, array $args): Response
    {
        $request = $this->getRequest();

        $doi = Repo::doi()->get((int) $args['doiId']);

        if (!$doi) {
            return $response->withStatus(404)->withJsonError('api.doi.404.doiNotFound');
        }

        // The contextId should always point to the requested contextId
        if ($doi->getData('contextId') !== $this->getRequest()->getContext()->getId()) {
            return $response->withStatus(403)->withJsonError('api.doi.400.contextsNotMatched');
        }

        $doiProps = Repo::doi()->getSchemaMap()->map($doi);

        Repo::doi()->delete($doi);

        return $response->withJson($doiProps, 200);
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
