<?php


/**
 * adaption of the subscription module to ask (optionally) for Name and Form of Address
 */

namespace HoJa\NLExtended;

/**
 * Front end module "newsletter subscribe".
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class ModuleSubscribeWithName extends \Module
{

	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'mod_hoja_subscribe_with_name';


    protected static $formName = 'hoja_nl_subscribe_with_name';


	/**
	 * Display a wildcard in the back end
	 *
	 * @return string
	 */
	public function generate()
	{
		if (TL_MODE == 'BE')
		{
			/** @var \BackendTemplate|object $objTemplate */
			$objTemplate = new \BackendTemplate('be_wildcard');

			$objTemplate->wildcard = '### ' . utf8_strtoupper($GLOBALS['TL_LANG']['FMD']['hoja_nl_subscribe_with_name'][0]) . ' ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}

		$this->nl_channels = deserialize($this->nl_channels);

		// Return if there are no channels
		if (!is_array($this->nl_channels) || empty($this->nl_channels))
		{
			return '';
		}

		return parent::generate();
	}


	/**
	 * Generate the module
	 */
	protected function compile()
	{
		// Activate e-mail address
		if (\Input::get('token'))
		{
			$this->activateRecipient();

			return;
		}

		// Subscribe
		if (\Input::post('FORM_SUBMIT') == self::$formName)
		{
			$this->addRecipient();
		}

		$blnHasError = false;

		// Error message
		if (strlen($_SESSION['SUBSCRIBE_ERROR']))
		{
			$blnHasError  = true;
			$this->Template->mclass = 'error form_error';
			$this->Template->message = $_SESSION['SUBSCRIBE_ERROR'];
			$_SESSION['SUBSCRIBE_ERROR'] = '';
		}

		// Confirmation message
		if (strlen($_SESSION['SUBSCRIBE_CONFIRM']))
		{
			$this->Template->mclass = 'confirm form_confirm';
            $this->Template->confirmed = true;
			$this->Template->message = $_SESSION['SUBSCRIBE_CONFIRM'];
			$_SESSION['SUBSCRIBE_CONFIRM'] = '';
		}

		$arrChannels = array();
		$objChannel = \NewsletterChannelModel::findByIds($this->nl_channels);

		// Get the titles
		if ($objChannel !== null)
		{
			while ($objChannel->next())
			{
				$arrChannels[$objChannel->id] = $objChannel->title;
			}
		}

        // load field names for labels
        $this->loadLanguageFile ( 'tl_newsletter_recipients');

		// Default template variables
		$this->Template->email = '';
		$this->Template->channels = $arrChannels;
		$this->Template->showChannels = !$this->nl_hideChannels;
		$this->Template->submit = specialchars($GLOBALS['TL_LANG']['MSC']['subscribe']);
		$this->Template->channelsLabel = $GLOBALS['TL_LANG']['MSC']['nl_channels'];
		$this->Template->emailLabel = $GLOBALS['TL_LANG']['MSC']['emailAddress'];
		$this->Template->action = \Environment::get('indexFreeRequest');
		$this->Template->formId = self::$formName;
		$this->Template->id = $this->id;
		$this->Template->hasError = $blnHasError;
	}


	/**
	 * Activate a recipient
	 */
	protected function activateRecipient()
	{
		/** @var \FrontendTemplate|object $objTemplate */
		$objTemplate = new \FrontendTemplate('mod_newsletter');

		$this->Template = $objTemplate;

		// Check the token
		$objRecipient = \NewsletterRecipientsModel::findByToken(\Input::get('token'));

		if ($objRecipient === null)
		{
			$this->Template->mclass = 'error form_error';
			$this->Template->message = $GLOBALS['TL_LANG']['ERR']['invalidToken'];

			return;
		}

		$time = time();
		$arrAdd = array();
		$arrChannels = array();
		$arrCids = array();

		// Update the subscriptions
		while ($objRecipient->next())
		{
			/** @var \NewsletterChannelModel $objChannel */
			$objChannel = $objRecipient->getRelated('pid');

			$arrAdd[] = $objRecipient->id;
			$arrChannels[] = $objChannel->title;
			$arrCids[] = $objChannel->id;

			$objRecipient->active = 1;
			$objRecipient->token = '';
			$objRecipient->pid = $objChannel->id;
			$objRecipient->confirmed = $time;
			$objRecipient->save();
		}

		// HOOK: post activation callback
		if (isset($GLOBALS['TL_HOOKS']['activateRecipient']) && is_array($GLOBALS['TL_HOOKS']['activateRecipient']))
		{
			foreach ($GLOBALS['TL_HOOKS']['activateRecipient'] as $callback)
			{
				$this->import($callback[0]);
				$this->{$callback[0]}->{$callback[1]}($objRecipient->email, $arrAdd, $arrCids);
			}
		}

		// Confirm activation
		$this->Template->mclass = 'confirm form_confirm';
		$this->Template->message = $GLOBALS['TL_LANG']['MSC']['nl_activate'];
	}


	/**
	 * Add a new recipient
	 */
	protected function addRecipient()
	{
		$arrChannels = \Input::post('channels');

		if (!is_array($arrChannels))
		{
			$_SESSION['SUBSCRIBE_ERROR'] = $GLOBALS['TL_LANG']['ERR']['noChannels'];
			$this->reload();
		}

		$arrChannels = array_intersect($arrChannels, $this->nl_channels); // see #3240

		// Check the selection
		if (!is_array($arrChannels) || empty($arrChannels))
		{
			$_SESSION['SUBSCRIBE_ERROR'] = $GLOBALS['TL_LANG']['ERR']['noChannels'];
			$this->reload();
		}

		$varInput = \Idna::encodeEmail(\Input::post('email', true));

		// Validate the e-mail address
		if (!\Validator::isEmail($varInput))
		{
			$_SESSION['SUBSCRIBE_ERROR'] = $GLOBALS['TL_LANG']['ERR']['email'];
			$this->reload();
		}

		$arrSubscriptions = array();

		// Get the existing active subscriptions
		if (($objSubscription = \NewsletterRecipientsModel::findBy(array("email=? AND active=1"), $varInput)) !== null)
		{
			$arrSubscriptions = $objSubscription->fetchEach('pid');
		}

		$arrNew = array_diff($arrChannels, $arrSubscriptions);

		// Return if there are no new subscriptions
		if (!is_array($arrNew) || empty($arrNew))
		{
			$_SESSION['SUBSCRIBE_ERROR'] = $GLOBALS['TL_LANG']['ERR']['subscribed'];
			$this->reload();
		}

		// Remove old subscriptions that have not been activated yet
		if (($objOld = \NewsletterRecipientsModel::findBy(array("email=? AND active=''"), $varInput)) !== null)
		{
			while ($objOld->next())
			{
				$objOld->delete();
			}
		}

		$time = time();
		$strToken = md5(uniqid(mt_rand(), true));

		// Add the new subscriptions
		foreach ($arrNew as $id)
		{
			$objRecipient = new \NewsletterRecipientsModel();

			$objRecipient->pid = $id;
			$objRecipient->tstamp = $time;
			$objRecipient->email = $varInput;
			$objRecipient->active = '';
			$objRecipient->addedOn = $time;
			$objRecipient->ip = $this->anonymizeIp(\Environment::get('ip'));
			$objRecipient->token = $strToken;
			$objRecipient->confirmed = '';

            $objRecipient->hoja_nl_firstname = \Input::post('firstname');
            $objRecipient->hoja_nl_lastname = \Input::post('lastname');
            $objRecipient->hoja_nl_gender = \Input::post('gender');
            $objRecipient->hoja_nl_title = \Input::post('title');
            $objRecipient->hoja_nl_form_of_address = \Input::post('form_of_address');

			$objRecipient->save();
		}

		// Get the channels
		$objChannel = \NewsletterChannelModel::findByIds($arrChannels);

		// Prepare the simple token data
		$arrData = array();
		$arrData['token'] = $strToken;
		$arrData['domain'] = \Idna::decode(\Environment::get('host'));
		$arrData['link'] = \Idna::decode(\Environment::get('base')) . \Environment::get('request') . ((\Config::get('disableAlias') || strpos(\Environment::get('request'), '?') !== false) ? '&' : '?') . 'token=' . $strToken;
		$arrData['channel'] = $arrData['channels'] = implode("\n", $objChannel->fetchEach('title'));

		// Activation e-mail
		$objEmail = new \Email();
		$objEmail->from = $GLOBALS['TL_ADMIN_EMAIL'];
		$objEmail->fromName = $GLOBALS['TL_ADMIN_NAME'];
		$objEmail->subject = sprintf($GLOBALS['TL_LANG']['MSC']['nl_subject'], \Idna::decode(\Environment::get('host')));
		$objEmail->text = \StringUtil::parseSimpleTokens($this->nl_subscribe, $arrData);
		$objEmail->sendTo($varInput);

		// Redirect to the jumpTo page
		if ($this->jumpTo && ($objTarget = $this->objModel->getRelated('jumpTo')) !== null)
		{
			/** @var \PageModel $objTarget */
			$this->redirect($objTarget->getFrontendUrl());
		}

		$_SESSION['SUBSCRIBE_CONFIRM'] = $GLOBALS['TL_LANG']['MSC']['nl_confirm'];
		$this->reload();
	}
}
