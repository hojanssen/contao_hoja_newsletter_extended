<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2015Leo Feyer
 *
 * @package Newsletter
 * @link    https://contao.org
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */


/**
 * adaption for the newsletter display to show the content of a newsletter correctly
 */
namespace HoJa\NLExtended;

class ModuleNewsletterReader extends \Contao\ModuleNewsletterReader
{
	
	protected $strTemplate = 'mod_hoja_newsletter_reader';

	public function generate()
	{
		return parent::generate();
	}
	
	
	
	protected function compile () {
		parent::compile ();
	
		$objNewsletter = \NewsletterModel::findSentByParentAndIdOrAlias(\Input::get('items'), $this->nl_channels);
		error_log ( "check" );
		error_log ( $this->id );
	
		$html = '';
		$objContentElements = \ContentModel::findPublishedByPidAndTable($objNewsletter->id, 'tl_newsletter');

		error_log ( $objContentElements );
		
		if ($objContentElements !== null) {
			while ($objContentElements->next()) {
				$html.= $this->getContentElement($objContentElements->id);
			}
		}

		// Replace insert tags
		
		$html = $this->replaceInsertTags($html);
	
		$this->Template->htmlContent = $html;
	}
}
