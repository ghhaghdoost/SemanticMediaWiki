<?php

namespace SMW\MediaWiki\Search;

use SMW;
use SpecialSearch;
use Html;
use Xml;
use MWNamespace;
use SMW\Message;

/**
 * @ingroup SMW
 *
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class SearchProfile {

	const PROFILE_NAME = 'smw';

	/**
	 * @var SpecialSearch
	 */
	private $specialSearch;

	/**
	 * @var []
	 */
	private $searchableNamespaces = [];

	/**
	 * @since 3.0
	 *
	 * @param SpecialSearch $specialSearch
	 */
	public function __construct( SpecialSearch $specialSearch ) {
		$this->specialSearch = $specialSearch;
	}

	/**
	 * @since 3.0
	 *
	 * @param array &$profiles
	 */
	public static function addProfile( array &$profiles ) {

		if ( $GLOBALS['wgSearchType'] !== 'SMWSearch' ) {
			return;
		}

		$profiles[self::PROFILE_NAME] = array(
			'message' => 'smw-search-profile',
			'tooltip' => 'smw-search-profile-tooltip',
			'namespaces' => \SearchEngine::defaultNamespaces()
		);
	}

	/**
	 * @since 3.0
	 */
	public function setSearchableNamespaces( array $searchableNamespaces ) {
		$this->searchableNamespaces = $searchableNamespaces;
	}

	/**
	 * @since 3.0
	 */
	public function getForm( &$form, $opts ) {

		$hidden = '';
		$html = '';

		foreach ( $opts as $key => $value ) {
			$hidden .= Html::hidden( $key, $value );
		}

		$request = $this->specialSearch->getContext()->getRequest();
		$sort = $request->getVal( 'sort' );

		$this->specialSearch->setExtraParam( 'sort', $sort );

		$list = [
			'best'   => 'Best Match',
			'recent' => 'Most Recent',
			'title'  => 'Title'
		];

		foreach ( $list as $key => $value ) {
			$opt = '';

			if ( $sort === $key ) {
				$opt = 'selected';
			}

			$html .= "<option value='$key' $opt>$value</option>";
		}

		$html = '<select name="sort">' . $html . '</select>';

		$params = array( 'id' => 'smw-searchoptions' );

		$form = Xml::fieldset( false, false, $params ) . $hidden . $html . Html::closeElement( 'fieldset' );

		$activeNamespaces = $this->specialSearch->getNamespaces();

		foreach ( $this->searchableNamespaces as $ns => $name ) {
			if ( $request->getCheck( 'ns' . $ns ) ) {
				$activeNamespaces[] = $ns;
				$this->specialSearch->setExtraParam( 'ns' . $ns, true );
			}
		}

		$searchEngine = $this->specialSearch->getSearchEngine();

		if ( $searchEngine !== null ) {
			$searchEngine->setNamespaces( $activeNamespaces );
		}

		$divider = "<div class='divider'></div>";

		$form .= $this->createMWNamespaceForm( $activeNamespaces, $divider, '' );
	}

	/**
	 * @notes Copied from SearchFormWidget::powerSearchBox
	 */
	private function createMWNamespaceForm( $activeNamespaces, $divider, $hidden ) {
		global $wgContLang;

		$rows = [];

		foreach ( $this->searchableNamespaces as $namespace => $name ) {
			$subject = MWNamespace::getSubject( $namespace );

			if ( MWNamespace::isTalk( $namespace ) ) {
			//	continue;
			}

			if ( !isset( $rows[$subject] ) ) {
				$rows[$subject] = "";
			}

			$name = $wgContLang->getConverter()->convertNamespace( $namespace );

			if ( $name === '' ) {
				$name = Message::get( 'blanknamespace', Message::TEXT, Message::USER_LANGUAGE );
			}

			$rows[$subject] .=
				'<td>' .
					Xml::checkLabel(
						$name,
						"ns{$namespace}",
						"mw-search-ns{$namespace}",
						in_array( $namespace, $activeNamespaces )
					) .
				'</td>';
		}

		// Lays out namespaces in multiple floating two-column tables so they'll
		// be arranged nicely while still accomodating diferent screen widths
		$tableRows = [];
		foreach ( $rows as $row ) {
			$tableRows[] = "<tr>{$row}</tr>";
		}
		$namespaceTables = [];
		foreach ( array_chunk( $tableRows, 4 ) as $chunk ) {
			$namespaceTables[] = implode( '', $chunk );
		}

		$showSections = [
			'namespaceTables' => "<table>" . implode( '</table><table>', $namespaceTables ) . '</table>',
		];

		// Stuff to feed SpecialSearch::saveNamespaces()
		$user = $this->specialSearch->getUser();
		$remember = '';
		if ( $user->isLoggedIn() ) {
			$remember = $divider . Xml::checkLabel(
				Message::get( 'powersearch-remember', Message::TEXT, Message::USER_LANGUAGE ),
				'nsRemember',
				'mw-search-powersearch-remember',
				false,
				// The token goes here rather than in a hidden field so it
				// is only sent when necessary (not every form submission)
				[ 'value' => $user->getEditToken(
					'searchnamespace',
					$this->specialSearch->getRequest()
				) ]
			);
		}

		return
			"<fieldset id='mw-searchoptions'>" .
				"<legend>" . Message::get( 'powersearch-legend', Message::ESCAPED, Message::USER_LANGUAGE ) . '</legend>' .
				"<h4>" . Message::get( 'powersearch-ns', Message::PARSE, Message::USER_LANGUAGE ) . '</h4>' .
				// populated by js if available
				"<div id='mw-search-togglebox'></div>" .
				$divider .
				implode(
					$divider,
					$showSections
				) .
				$hidden .
				$remember .
			"</fieldset>";
	}

}
