<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2015 Leo Feyer
 *
 */

/**
 * @package hoja_newsletter_extended
 *
 * @copyright  Holger Janßen 2015
 * @author     Holger Janßen <phpdevel@holgerjanssen.de>
 */

 
 
/**** elemente fuer den Newsletter-Header ***/
$GLOBALS['TL_DCA']['tl_content']['palettes']['hoja_nl_header'] 
	= '{type_legend},type;{hoja_nl_header_legend},headline,hoja_nl_header_subheadline;'
		.'{hoja_nl_header_image_legend},singleSRC,alt,title,imageUrl;'
	. '{expert_legend:hide},invisible;';

$GLOBALS['TL_DCA']['tl_content']['fields']['hoja_nl_header_subheadline'] = array (
	'label'		=> &$GLOBALS['TL_LANG']['tl_content']['hoja_nl_header_subheadline'],
	'exclude'	=> true,
	'inputType' => 'text',
	'eval'       => array('mandatory'=>true, 'tl_class' => 'w50'),
	'sql'		=> "varchar(255) NOT NULL default ''",
);


	
	
 
 

/**
 * Dynamically add the permission check and parent table
 */
if ($this->Input->get('do') == 'newsletter' || (\Input::get('table') == 'tl_content' && \Input::get('field') == 'type')) {
	$GLOBALS['TL_DCA']['tl_content']['config']['ptable'] = 'tl_newsletter';
	$GLOBALS['TL_DCA']['tl_content']['config']['onload_callback'][] = array('tl_content_newsletter_extended', 'checkPermission');
	$GLOBALS['TL_DCA']['tl_content']['list']['sorting']['headerFields'] = array('subject', 'alias', 'useSMTP');


	// remove some palette fields
	foreach ($arrPalettes as $k => $strPalette) {
		$GLOBALS['TL_DCA']['tl_content']['palettes'][$k] = str_replace(
			array(
				',guests,',
				',fullsize,',
				'{protected_legend:hide},protected;'
			),
			array(
				',',
				',',
				''
			),
			$strPalette
		);
	}
	
	$GLOBALS['TL_DCA']['tl_content']['fields']['customTpl']['options_callback'] = array('tl_content_newsletter_extended', 'getNewsletterElementTemplates');


	
	$GLOBALS['TL_HOOKS']['parseTemplate'][] = array('tl_content_newsletter_extended', 'addTemplatePrefix');
	
	
	

}






class tl_content_newsletter_extended extends Backend {

	/**
	 * Import the back end user object
	 */
	public function __construct() {
		parent::__construct();
		$this->import('BackendUser', 'User');

		//$GLOBALS['TL_CSS'][] = 'system/modules/newsletter_content/assets/css/multicolumnwizard.css';
	}


	/**
	 * Check permissions to edit table tl_content
	 */
	public function checkPermission() {
		if ($this->User->isAdmin) {
			return;
		}

		// Set the root IDs
		if (!is_array($this->User->newsletters) || empty($this->User->newsletters)) {
			$root = array(0);
		}
		else {
			$root = $this->User->newsletters;
		}

		//$id = strlen($this->Input->get('id')) ? $this->Input->get('id') : CURRENT_ID;

		// Check the current action
		switch ($this->Input->get('act'))
		{
			case 'paste':
				// Allow
				break;

			case '': // empty
			case 'create':
			case 'select':
				// Check access to the news item
				if (!$this->checkAccessToElement(CURRENT_ID, $root, true))
				{
					$this->redirect('contao/main.php?act=error');
				}
				break;

			case 'editAll':
			case 'deleteAll':
			case 'overrideAll':
			case 'cutAll':
			case 'copyAll':
				// Check access to the parent element if a content element is moved
				if (($this->Input->get('act') == 'cutAll' || $this->Input->get('act') == 'copyAll') && !$this->checkAccessToElement($this->Input->get('pid'), $root, ($this->Input->get('mode') == 2)))
				{
					$this->redirect('contao/main.php?act=error');
				}

				$objCes = $this->Database->prepare("SELECT id FROM tl_content WHERE ptable='tl_newsletter' AND pid=?")
										 ->execute(CURRENT_ID);

				$session = $this->Session->getData();
				$session['CURRENT']['IDS'] = array_intersect($session['CURRENT']['IDS'], $objCes->fetchEach('id'));
				$this->Session->setData($session);
				break;

			case 'cut':
			case 'copy':
				// Check access to the parent element if a content element is moved
				if (!$this->checkAccessToElement($this->Input->get('pid'), $root, ($this->Input->get('mode') == 2)))
				{
					$this->redirect('contao/main.php?act=error');
				}
				// NO BREAK STATEMENT HERE

			default:
				// Check access to the content element
				if (!$this->checkAccessToElement($this->Input->get('id'), $root))
				{
					$this->redirect('contao/main.php?act=error');
				}
				break;
		}
	}


	/**
	 * Check access to a particular content element
	 * @param integer
	 * @param array
	 * @param boolean
	 * @return boolean
	 */
	protected function checkAccessToElement($id, $root, $blnIsPid=false) {
		if ($blnIsPid) {
			$objArchive = $this->Database->prepare("SELECT a.id, n.id AS nid FROM tl_newsletter n, tl_newsletter_channel a WHERE n.id=? AND n.pid=a.id")
										 ->limit(1)
										 ->execute($id);
		}
		else {
			$objArchive = $this->Database->prepare("SELECT a.id, n.id AS nid FROM tl_content c, tl_newsletter n, tl_newsletter_channel a WHERE c.id=? AND c.pid=n.id AND n.pid=a.id")
										 ->limit(1)
										 ->execute($id);
		}

		// Invalid ID
		if ($objArchive->numRows < 1) {
			$this->log('Invalid newsletter content element ID ' . $id, __METHOD__, TL_ERROR);
			return false;
		}

		// The news archive is not mounted
		if (!in_array($objArchive->id, $root)) {
			$this->log('Not enough permissions to modify article ID ' . $objArchive->nid . ' in newsletter channel ID ' . $objArchive->id, __METHOD__, TL_ERROR);
			return false;
		}

		return true;
	}


	/**
	 * Return all newsletter content element templates as array
	 * @return array
	 */
	public function getNewsletterElementTemplates() {
		$strPrefix = 'nl_';
		$arrTemplates = array();

		// Get the default templates
		foreach (\TemplateLoader::getPrefixedFiles($strPrefix) as $strTemplate)
		{
			$arrTemplates[$strTemplate][] = 'root';
		}
		$arrCustomized = glob(TL_ROOT . '/templates/' . $strPrefix . '*');

		// Add the customized templates
		if (is_array($arrCustomized))
		{
			foreach ($arrCustomized as $strFile)
			{
				$strTemplate = basename($strFile, strrchr($strFile, '.'));
				$arrTemplates[$strTemplate][] = $GLOBALS['TL_LANG']['MSC']['global'];
			}
		}

		// Show the template sources (see #6875)
		foreach ($arrTemplates as $k=>$v)
		{
			$v = array_filter($v, function($a) {
				return $a != 'root';
			});
			if (empty($v))
			{
				$arrTemplates[$k] = $k;
			}
			else
			{
				$arrTemplates[$k] = $k . ' (' . implode(', ', $v) . ')';
			}
		}

		// Sort the template names
		ksort($arrTemplates);
		return $arrTemplates;
	}

	
	public function addTemplatePrefix($objTemplate)
	{
		// get the current prefix
		if ( $objTemplate->ptable == "tl_newsletter" ) {
			$pid = $objTemplate->pid;
			$dbNl = $this->Database->prepare('SELECT hoja_template_prefix FROM tl_newsletter WHERE id=?')->execute($pid);

			$templPrefix = $dbNl->hoja_template_prefix;
			
			try {
				$found = $objTemplate->getTemplate ( $templPrefix . $objTemplate->getName() );
				$objTemplate->setName ($templPrefix . $objTemplate->getName()) ;
			} catch ( Exception $e ) {
				// ok, template with prefix does not exist! 
			}
		}
	}

	
}
