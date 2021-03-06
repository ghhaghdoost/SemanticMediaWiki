<?php

namespace SMW\Tests\Utils;

use DatabaseBase;
use SMW\Connection\ConnectionProvider;

/**
 *
 * @license GNU GPL v2+
 * @since 2.0
 *
 * @author mwjames
 */
class MediaWikiTestConnectionProvider implements ConnectionProvider {

	/**
	 * @var DatabaseBase
	 */
	protected $connection;

	protected $id;

	/**
	 * @since 2.0
	 *
	 * @param int $id
	 */
	public function __construct( $id = DB_MASTER ) {
		$this->id = $id;
	}

	/**
	 * @since  2.0
	 *
	 * @return DatabaseBase
	 */
	public function getConnection() {

		if ( $this->connection === null ) {
			$this->connection = wfGetDB( $this->id );
		}

		return $this->connection;
	}

	public function releaseConnection() {
	}

}
