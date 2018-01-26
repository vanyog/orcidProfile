<?php

/**
 * @file plugins/generic/orcidProfile/OrcidProfilePlugin.inc.php
 *
 * Copyright (c) 2015-2016 University of Pittsburgh
 * Copyright (c) 2014-2017 Simon Fraser University
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class OrcidProfilePlugin
 * @ingroup plugins_generic_orcidProfile
 *
 * @brief ORCID Profile plugin class
 */

import('lib.pkp.classes.plugins.GenericPlugin');

define('ORCID_OAUTH_URL', 'https://orcid.org/oauth/');
define('ORCID_OAUTH_URL_SANDBOX', 'https://sandbox.orcid.org/oauth/');
define('ORCID_API_URL_PUBLIC', 'https://pub.orcid.org/');
define('ORCID_API_URL_PUBLIC_SANDBOX', 'https://pub.sandbox.orcid.org/');
define('ORCID_API_URL_MEMBER', 'https://api.orcid.org/');
define('ORCID_API_URL_MEMBER_SANDBOX', 'https://api.sandbox.orcid.org/');

define('OAUTH_TOKEN_URL', 'oauth/token');
define('ORCID_API_VERSION_URL', 'v2.1/');
define('ORCID_PROFILE_URL', 'person');
define('ORCID_EMAIL_URL', 'email');

class OrcidProfilePlugin extends GenericPlugin {
	/**
	 * Called as a plugin is registered to the registry
	 * @param $category String Name of category plugin was registered to
	 * @return boolean True iff plugin initialized successfully; if false,
	 * 	the plugin will not be registered.
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return true;
		if ($success && $this->getEnabled()) {
			// Register callback for Smarty filters; add CSS
			HookRegistry::register('TemplateManager::display', array($this, 'handleTemplateDisplay'));
			// Register callbacks for author metadata form handling
			HookRegistry::register('authorform::execute', array($this, 'handleAuthorFormExecute'));
			// Register callbacks for modified form displays
			HookRegistry::register('publicprofileform::display', array($this, 'handleFormDisplay'));
			HookRegistry::register('authorform::display', array($this, 'handleFormDisplay'));

			// Insert ORCID callback
			HookRegistry::register('LoadHandler', array($this, 'setupCallbackHandler'));

			// Handle ORCID on user registration
			HookRegistry::register('registrationform::execute', array($this, 'collectUserOrcidId'));

			// Send emails to authors without ORCID id upon submission
			HookRegistry::register('Author::Form::Submit::AuthorSubmitStep3Form::Execute', array($this, 'collectAuthorOrcidId'));

			// Add ORCiD fields to author DAO
			HookRegistry::register('authordao::getAdditionalFieldNames', array($this, 'authorGetAdditionalFieldNames'));
		}
		return $success;
	}

	/**
	 * Get page handler path for this plugin.
	 * @return string Path to plugin's page handler
	 */
	function getHandlerPath() {
		return $this->getPluginPath() . DIRECTORY_SEPARATOR . 'pages';
	}

	/**
	 * Hook callback: register pages for each sushi-lite method
	 * This URL is of the form: orcidapi/{$orcidrequest}
	 * @see PKPPageRouter::route()
	 */
	function setupCallbackHandler($hookName, $params) {
		$page = $params[0];
		if ($this->getEnabled() && $page == 'orcidapi') {
			$this->import('pages/OrcidHandler');
			define('HANDLER_CLASS', 'OrcidHandler');
			return true;
		}
		return false;
	}

    function getSetting($contextId, $name)
    {
		switch ($name) {
			case 'orcidProfileAPIPath':
				$config_value = Config::getVar('orcid','api_url');
				break;
			case 'orcidClientId':
				$config_value = Config::getVar('orcid','client_id');
				break;
			case 'orcidClientSecret':
				$config_value = Config::getVar('orcid','client_secret');
				break;
			default:
            	return parent::getSetting($contextId, $name);
		}
	    return $config_value ?: parent::getSetting($contextId, $name);
    }

    function isGloballyConfigured() {
	    $apiUrl = Config::getVar('orcid','api_url');
	    $clientId = Config::getVar('orcid','client_id');
	    $clientSecret = Config::getVar('orcid','client_secret');
	    return isset($apiUrl) && trim($apiUrl) && isset($clientId) && trim($clientId) &&
		    isset($clientSecret) && trim($clientSecret);
    }

    /**
	 * Hook callback to handle form display.
	 * Registers output filter for public user profile and author form.
	 *
	 * @see Form::display()
	 *
	 * @param $hookName string
	 * @param $args Form[]
	 *
	 * @return bool
	 */
	function handleFormDisplay($hookName, $args) {		
		$request = PKPApplication::getRequest();
		$templateMgr = TemplateManager::getManager($request);

		switch ($hookName) {
			case 'publicprofileform::display':
				$templateMgr->register_outputfilter(array($this, 'profileFilter'));
				break;
			case 'authorform::display':
				$authorForm =& $args[0];
				$author = $authorForm->getAuthor();
				$templateMgr->assign( array(
					'orcidAccessToken' => $author->getData('orcidAccessToken'),
					'orcidAccessExpiresOn' => $author->getData('orcidAccessExpiresOn')
				));
				$templateMgr->register_outputfilter(array($this, 'authorFormFilter'));
				break;
		}
		return false;
	}

	/**
	 * Hook callback: register output filter for user registration and article display.
	 *
	 * @see TemplateManager::display()
	 * @param $hookName string
	 * @param $args array
	 * @return bool
	 */
	function handleTemplateDisplay($hookName, $args) {
		$templateMgr =& $args[0];
		$template =& $args[1];
		$request = PKPApplication::getRequest();

		// Assign our private stylesheet, for front and back ends.
		$templateMgr->addStyleSheet(
			'orcidProfile',
			$request->getBaseUrl() . '/' . $this->getStyleSheet(),
			array(
				'contexts' => array('frontend', 'backend')
			)
		);

		switch ($template) {
			case 'frontend/pages/userRegister.tpl':
				$templateMgr->register_outputfilter(array($this, 'registrationFilter'));
				break;
			case 'frontend/pages/article.tpl':
				$templateMgr->assign('orcidIcon', $this->getIcon());
				break;
		}
		return false;
	}

	/**
	 * Return the OAUTH path (prod or sandbox) based on the current API configuration
	 * @return string
	 */
	function getOauthPath() {
		$context = Request::getContext();
		$contextId = ($context == null) ? 0 : $context->getId();

		$apiPath =	$this->getSetting($contextId, 'orcidProfileAPIPath');
		if ($apiPath == ORCID_API_URL_PUBLIC || $apiPath == ORCID_API_URL_MEMBER) {
			return ORCID_OAUTH_URL;
		} else {
			return ORCID_OAUTH_URL_SANDBOX;
		}
	}

	/**
	 * Output filter adds ORCiD interaction to registration form.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return string
	 */
	function registrationFilter($output, &$templateMgr) {
		if (preg_match('/<form[^>]+id="register"[^>]+>/', $output, $matches, PREG_OFFSET_CAPTURE)) {			
			$match = $matches[0][0];
			$offset = $matches[0][1];
			$context = Request::getContext();
			$contextId = ($context == null) ? 0 : $context->getId();

			$templateMgr->assign(array(
				'targetOp' => 'register',
				'orcidProfileOauthPath' => $this->getOauthPath(),
				'orcidClientId' => $this->getSetting($contextId, 'orcidClientId'),
				'orcidIcon' => $this->getIcon(),
			));

			$newOutput = substr($output, 0, $offset+strlen($match));
			$newOutput .= $templateMgr->fetch($this->getTemplatePath() . 'orcidProfile.tpl');
			$newOutput .= substr($output, $offset+strlen($match));
			$output = $newOutput;
		}
		$templateMgr->unregister_outputfilter(array($this, 'registrationFilter'));
		return $output;
	}

	/**
	 * Output filter adds ORCiD interaction to user profile form.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return string
	 */
	function profileFilter($output, &$templateMgr) {
		if (preg_match('/<label[^>]+for="orcid[^"]*"[^>]*>[^<]+<\/label>/', $output, $matches, PREG_OFFSET_CAPTURE) &&
			!(preg_match('/\$\(\'input\[name=orcid\]\'\)/', $output))) {
			$match = $matches[0][0];
			$offset = $matches[0][1];
			$context = Request::getContext();
			$contextId = ($context == null) ? 0 : $context->getId();

			// Entering the registration without ORCiD; present the button.
			$templateMgr->assign(array(
				'targetOp' => 'profile',
				'orcidProfileOauthPath' => $this->getOauthPath(),
				'orcidClientId' => $this->getSetting($contextId, 'orcidClientId'),
				'orcidIcon' => $this->getIcon(),
			));

			$newOutput = substr($output, 0, $offset+strlen($match));
			$newOutput .= $templateMgr->fetch($this->getTemplatePath() . 'orcidProfile.tpl');
			$newOutput .= '<script type="text/javascript">
					$(document).ready(function() {
					$(\'input[name=orcid]\').attr(\'readonly\', "true");
				});
			</script>';
			$newOutput .= substr($output, $offset+strlen($match));
			$output = $newOutput;
		}
		$templateMgr->unregister_outputfilter(array($this, 'profileFilter'));
		return $output;
	}

	/**
	 * Output filter adds ORCiD interaction to contributors metadata add/edit form.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return string
	 */
	function authorFormFilter($output, &$templateMgr) {
		if (preg_match('/<input[^>]+name="submissionId"[^>]*>/', $output, $matches, PREG_OFFSET_CAPTURE)) {
			$match = $matches[0][0];
			$offset = $matches[0][1];

			$newOutput = substr($output, 0, $offset+strlen($match));
			$newOutput .= $templateMgr->fetch($this->getTemplatePath() . 'authorFormOrcid.tpl');
			$newOutput .= substr($output, $offset+strlen($match));
			$output = $newOutput;
		}
		$templateMgr->unregister_outputfilter('authorFormFilter');
		return $output;
	}

	/**
	 * @param $hookname string
	 * @param $args AuthorForm[]
	 */
	function handleAuthorFormExecute($hookname, $args) {
		$form =& $args[0];
		$form->readUserVars(array('requestOrcidAuthorization'));
		$requestAuthorization = $form->getData('requestOrcidAuthorization');
		$author = $form->getAuthor();
		if ($author) {
			$this->sendAuthorMail($author);
		}
		// TODO Send mail to author if no ORCID was stored and or no ORCID access token has been stored.
	}

	/**
	 * Collect the ORCID when registering a user.
	 * @param $hookName string
	 * @param $params array
	 * @return bool
	 */
	function collectUserOrcidId($hookName, $params) {
		$form = $params[0];
		$user =& $params[1];

		$form->readUserVars(array('orcid'));
		$user->setOrcid($form->getData('orcid'));
		return false;
	}

	/**
	 * Output filter adds ORCiD interaction to the 3rd step submission form.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return bool
	 */
	function collectAuthorOrcidId($hookName, $params) {
		$author =& $params[0];
		$formAuthor =& $params[1];

		// if author has no orcid id
		if (!$author->getData('orcid')){
			$this->sendAuthorMail($author);
		}
		return false;
	}

	/**
	 * Add additional ORCID specific fields to the author record
	 * @param $hookName string
	 * @param $params array
	 *
	 * @return bool
	 */
	function authorGetAdditionalFieldNames($hookName, $params) {
		$fields =& $params[1];
		$fields[] = 'orcidToken';
		$fields[] = 'orcidAccessToken';
		$fields[] = 'orcidRefreshToken';
		$fields[] = 'orcidAccessExpiresOn';
		return false;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.orcidProfile.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.orcidProfile.description');
	}

	/**
	 * @copydoc PKPPlugin::getTemplatePath
	 */
	function getTemplatePath($inCore = false) {
		return parent::getTemplatePath($inCore) . 'templates/';
	}

	/**
	 * @see PKPPlugin::getInstallEmailTemplatesFile()
	 */
	function getInstallEmailTemplatesFile() {
		return ($this->getPluginPath() . '/emailTemplates.xml');
	}

	/**
	 * @see PKPPlugin::getInstallEmailTemplateDataFile()
	 */
	function getInstallEmailTemplateDataFile() {
		return ($this->getPluginPath() . '/locale/{$installedLocale}/emailTemplates.xml');
	}

	/**
	 * Extend the {url ...} smarty to support this plugin.
	 */
	function smartyPluginUrl($params, &$smarty) {
		$path = array($this->getCategory(), $this->getName());
		if (is_array($params['path'])) {
			$params['path'] = array_merge($path, $params['path']);
		} elseif (!empty($params['path'])) {
			$params['path'] = array_merge($path, array($params['path']));
		} else {
			$params['path'] = $path;
		}

		if (!empty($params['id'])) {
			$params['path'] = array_merge($params['path'], array($params['id']));
			unset($params['id']);
		}
		return $smarty->smartyUrl($params, $smarty);
	}

	/**
	 * @see Plugin::getActions()
	 */
	function getActions($request, $actionArgs) {
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		return array_merge(
			$this->getEnabled()?array(
				new LinkAction(
					'settings',
					new AjaxModal(
						$router->url(
							$request,
							null,
							null,
							'manage',
							null,
							array(
								'verb' => 'settings',
								'plugin' => $this->getName(),
								'category' => 'generic'
							)
						),
						$this->getDisplayName()
					),
					__('manager.plugins.settings'),
					null
				),
			):array(),
			parent::getActions($request, $actionArgs)
		);
	}

	/**
	 * @see Plugin::manage()
	 */
	function manage($args, $request) {
		switch ($request->getUserVar('verb')) {
			case 'settings':
				$context = $request->getContext();
				$contextId = ($context == null) ? 0 : $context->getId();

				$templateMgr = TemplateManager::getManager();
				$templateMgr->register_function('plugin_url', array($this, 'smartyPluginUrl'));
				$apiOptions = array(
					ORCID_API_URL_PUBLIC => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.public',
					ORCID_API_URL_PUBLIC_SANDBOX => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.publicSandbox',
					ORCID_API_URL_MEMBER => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.member',
					ORCID_API_URL_MEMBER_SANDBOX => 'plugins.generic.orcidProfile.manager.settings.orcidProfileAPIPath.memberSandbox'
				);

				$templateMgr->assign('orcidApiUrls', $apiOptions);

				$this->import('OrcidProfileSettingsForm');
				$form = new OrcidProfileSettingsForm($this, $contextId);
				if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						return new JSONMessage(true);
					}
				} else {
					$form->initData();
				}
				return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}

	/**
	 * Return the location of the plugin's CSS file
	 * @return string
	 */
	function getStyleSheet() {
		return $this->getPluginPath() . '/css/orcidProfile.css';
	}

	/**
	 * Return a string of the ORCiD SVG icon
	 * @return string
	 */
	function getIcon() {
		$path = Core::getBaseDir() . '/' . $this->getPluginPath() . '/templates/images/orcid.svg';
		return file_exists($path) ? file_get_contents($path) : '';
	}

	/**
	 * Instantiate a MailTemplate
	 *
	 * @param $emailKey string
	 * @param $context Context
	 *
	 * @return MailTemplate
	 */
	function &getMailTemplate($emailKey, $context = null) {
		if (!isset($this->_mailTemplates[$emailKey])) {
			import('lib.pkp.classes.mail.MailTemplate');
			$mailTemplate = new MailTemplate($emailKey, null, null, $context, true, true);
			$this->_mailTemplates[$emailKey] = $mailTemplate;
		}
		return $this->_mailTemplates[$emailKey];
	}

	/**
	 * @param $author Author
	 */
	public function sendAuthorMail($author)
	{
		$mail = $this->getMailTemplate('ORCID_COLLECT_AUTHOR_ID');

		$orcidToken = md5(time());
		$author->setData('orcidToken', $orcidToken);

		$request = PKPApplication::getRequest();
		$context = $request->getContext();

		// This should only ever happen within a context, never site-wide.
		assert($context != null);
		$contextId = $context->getId();

		$articleDao = DAORegistry::getDAO('ArticleDAO');
		$article = $articleDao->getById($author->getSubmissionId());
		$dispatcher = $request->getDispatcher();
		// We need to construct a page url, but the request is using the component router.
		// Use the Dispatcher to construct the url and set the router.
		$redirectUrl = $dispatcher->url($request, ROUTE_PAGE, null, 'orcidapi',
			'orcidVerify', null, array('orcidToken' => $orcidToken, 'articleId' => $author->getSubmissionId()));

		$mail->assignParams(array(
			'authorOrcidUrl' => $this->getOauthPath() . 'authorize?' . http_build_query(array(
				'client_id' => $this->getSetting($contextId, 'orcidClientId'),
				'response_type' => 'code',
				'scope' => '/activities/update /read-limited',
				'redirect_uri' => $redirectUrl)),
			'authorName' => $author->getFullName(),
			'journalName' => $context->getLocalizedName(),
			'editorialContactSignature' => $context->getSetting('contactName'),
			'articleTitle' => $article->getLocalizedTitle(),
		));

		// Send to author
		$mail->addRecipient($author->getEmail(), $author->getFullName());

		// Send the mail.
		$mail->send($request);
	}

}
?>
