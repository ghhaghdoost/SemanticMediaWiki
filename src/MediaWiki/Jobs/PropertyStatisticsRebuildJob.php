<?php

namespace SMW\MediaWiki\Jobs;

use SMW\ApplicationFactory;
use SMW\RequestOptions;
use SMW\Site;
use Title;

/**
 * @license GNU GPL v2+
 * @since 2.5
 *
 * @author mwjames
 */
class PropertyStatisticsRebuildJob extends JobBase {

	/**
	 * @since 2.5
	 *
	 * @param Title $title
	 * @param array $params job parameters
	 */
	public function __construct( Title $title, $params = array() ) {
		parent::__construct( 'SMW\PropertyStatisticsRebuildJob', $title, $params );
		$this->removeDuplicates = true;
	}

	/**
	 * @see Job::run
	 *
	 * @since  2.5
	 */
	public function run() {

		if ( $this->waitOnCommandLineMode() ) {
			return true;
		}

		$applicationFactory = ApplicationFactory::getInstance();
		$maintenanceFactory = $applicationFactory->newMaintenanceFactory();

		// Use a fixed store to avoid issues like "Call to undefined method
		// SMW\SPARQLStore\SPARQLStore::getDataItemHandlerForDIType" because
		// the property statistics table and hereby its update is bound to
		// the SQLStore
		$propertyStatisticsRebuilder = $maintenanceFactory->newPropertyStatisticsRebuilder(
			$applicationFactory->getStore( '\SMW\SQLStore\SQLStore' )
		);

		$propertyStatisticsRebuilder->rebuild();

		return true;
	}

}
