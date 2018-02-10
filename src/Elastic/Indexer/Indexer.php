<?php

namespace SMW\Elastic\Indexer;

use Psr\Log\LoggerAwareTrait;
use SMW\SQLStore\ChangeOp\ChangeOp;
use SMW\SQLStore\ChangeOp\ChangeDiff;
use SMW\Utils\CharArmor;
use SMW\Elastic\ElasticStore;
use SMW\Store;
use SMW\DataTypeRegistry;
use SMW\DIProperty;
use SMW\DIWikiPage;
use SMWDIBlob as DIBlob;
use SMW\Elastic\Connection\Client as ElasticClient;
use Onoi\MessageReporter\MessageReporterAwareTrait;
use Title;
use RuntimeException;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class Indexer {

	use MessageReporterAwareTrait;
	use LoggerAwareTrait;

	/**
	 * @var Store
	 */
	private $store;

	/**
	 * @var TextIndexer
	 */
	private $textIndexer;

	/**
	 * @var FileIndexer
	 */
	private $fileIndexer;

	/**
	 * @var string
	 */
	private $origin = '';

	/**
	 * @var []
	 */
	private $versions = [];

	/**
	 * @since 3.0
	 *
	 * @param Store $store
	 * @param TextIndexer|null $textIndexer
	 * @param FileIndexer|null $fileIndexer
	 */
	public function __construct( Store $store, TextIndexer $textIndexer = null, FileIndexer $fileIndexer = null ) {
		$this->store = $store;

		if ( $textIndexer === null ) {
			$textIndexer = new TextIndexer( $this );
		}

		if ( $fileIndexer === null ) {
			$fileIndexer = new FileIndexer( $this );
		}

		$this->textIndexer = $textIndexer;
		$this->fileIndexer = $fileIndexer;
	}

	/**
	 * @since 3.0
	 *
	 * @param [] $versions
	 */
	public function setVersions( array $versions ) {
		$this->versions = $versions;
	}

	/**
	 * @since 3.0
	 *
	 * @param string $origin
	 */
	public function setOrigin( $origin ) {
		$this->origin = $origin;
	}

	/**
	 * @since 3.0
	 *
	 * @return TextIndexer
	 */
	public function getTextIndexer() {

		$this->textIndexer->setLogger(
			$this->logger
		);

		return $this->textIndexer;
	}

	/**
	 * @since 3.0
	 *
	 * @return FileIndexer
	 */
	public function getFileIndexer() {

		$this->fileIndexer->setLogger(
			$this->logger
		);

		return $this->fileIndexer;
	}

	/**
	 * @since 3.0
	 *
	 * @param string $type
	 *
	 * @return string
	 */
	public function getId( DIWikiPage $dataItem ) {
		return $this->store->getObjectIds()->getId( $dataItem );
	}

	/**
	 * @since 3.0
	 *
	 * @return boolean
	 */
	public function isAccessible() {
		return $this->isSafe();
	}

	/**
	 * @since 3.0
	 *
	 * @return Client
	 */
	public function getConnection() {
		return $this->store->getConnection( 'elastic' );
	}

	/**
	 * @since 3.0
	 */
	public function drop() {
		$this->deleteIndexByType( ElasticClient::TYPE_DATA );
		$this->deleteIndexByType( ElasticClient::TYPE_LOOKUP );
	}

	/**
	 * @since 3.0
	 */
	public function setup() {
		$this->setupIndexByType( ElasticClient::TYPE_DATA );
		$this->setupIndexByType( ElasticClient::TYPE_LOOKUP );
	}

	/**
	 * @since 3.0
	 *
	 * @param string $type
	 *
	 * @return string
	 */
	public function getIndexName( $type ) {

		$index = $this->store->getConnection( 'elastic' )->getIndexNameByType(
			$type
		);

		// If the rebuilder has set a specific version, use it to avoid writing to
		// the alias of the index when running a rebuild.
		if ( isset( $this->versions[$type] ) ) {
			$index = "$index-" . $this->versions[$type];
		}

		return $index;
	}

	/**
	 * @since 3.0
	 *
	 * @param array $params
	 *
	 * @return Bulk
	 */
	public function newBulk( array $params ) {

		$bulk = new Bulk(
			$this->store->getConnection( 'elastic' )
		);

		$bulk->head( $params );

		return $bulk;
	}

	/**
	 * @since 3.0
	 *
	 * @param array $idList
	 */
	public function delete( array $idList, $isConcept = false ) {

		$title = Title::newFromText( $this->origin . ':' . md5( json_encode( $idList ) ) );

		$params = [
			'delete' => $idList
		];

		if ( $this->isSafe( $title, $params ) === false ) {
			return $this->planRecoveryJob( $title, $params );
		}

		$index = $this->getIndexName(
			ElasticClient::TYPE_DATA
		);

		$params = [
			'_index' => $index,
			'_type'  => ElasticClient::TYPE_DATA
		];

		$bulk = $this->newBulk( $params );
		$time = -microtime( true );

		foreach ( $idList as $id ) {

			$bulk->delete( [ '_id' => $id ] );

			if ( $isConcept ) {
				$bulk->delete(
					[
						'_index' => $this->getIndexName( ElasticClient::TYPE_LOOKUP ),
						'_type' => ElasticClient::TYPE_LOOKUP,
						'_id' => md5( $id )
					]
				);
			}
		}

		$bulk->execute();

		$context = [
			'method' => __METHOD__,
			'role' => 'developer',
			'origin' => $this->origin,
			'procTime' => $time + microtime( true )
		];

		$this->logger->info( 'Deleted: {origin}, procTime (in sec): {procTime}', $context );
	}

	/**
	 * @since 3.0
	 *
	 * @param DIWikiPage $dataItem
	 */
	public function create( DIWikiPage $dataItem ) {

		$title = $dataItem->getTitle();

		$params = [
			'create' => $dataItem->getHash()
		];

		if ( $this->isSafe() === false ) {
			return $this->planRecoveryJob( $title, $params );
		}

		$connection = $this->store->getConnection( 'elastic' );

		$params = [
			'index' => $this->getIndexName( ElasticClient::TYPE_DATA ),
			'type'  => ElasticClient::TYPE_DATA,
			'id'    => $dataItem->getId()
		];

		$value['subject'] = [
			'subject' => [
				'title' => str_replace( '_', ' ', $dataItem->getDBKey() ),
				'subobject' => $dataItem->getSubobjectName(),
				'namespace' => $dataItem->getNamespace(),
				'interwiki' => $dataItem->getInterwiki(),
				'sortkey'   => $dataItem->getSortKey()
			]
		];

		$connection->index( $params + [ 'body' => $value ] );
	}

	/**
	 * @since 3.0
	 *
	 * @param ChangeDiff $changeDiff
	 */
	public function safeReplicate( ChangeDiff $changeDiff ) {

		$subject = $changeDiff->getSubject();

		$params = [
			'index' => $subject->getHash()
		];

		if ( $this->isSafe() === false ) {
			return $this->planRecoveryJob( $subject->getTitle(), $params ) ;
		}

		$this->index( $changeDiff );
	}

	/**
	 * @since 3.0
	 *
	 * @param ChangeDiff $changeDiff
	 */
	public function index( ChangeDiff $changeDiff ) {

		$time = -microtime( true );

		$params = [
			'_index' => $this->getIndexName( ElasticClient::TYPE_DATA ),
			'_type'  => ElasticClient::TYPE_DATA
		];

		$bulk = $this->newBulk( $params );

		$this->doMap( $bulk, $changeDiff );
		$bulk->execute();

		$context = [
			'method' => __METHOD__,
			'role' => 'developer',
			'origin' => $this->origin,
			'subject' => $changeDiff->getSubject()->getHash(),
			'procTime' => $time + microtime( true )
		];

		$this->logger->info( 'Replicated: {subject}, procTime (in sec): {procTime}', $context );
	}

	private function isSafe() {

		$connection = $this->store->getConnection( 'elastic' );

		// Make sure a node is available and is not locked by the rebuilder
		if ( !$connection->hasLock( ElasticClient::TYPE_DATA ) && $connection->ping() ) {
			return true;
		}

		return false;
	}

	private function planRecoveryJob( $title, array $params ) {

		$indexerRecoveryJob = new IndexerRecoveryJob(
			$title,
			$params
		);

		$indexerRecoveryJob->insert();

		$context = [
			'method' => __METHOD__,
			'role' => 'user',
			'origin' => $this->origin,
			'subject' => $title->getPrefixedDBKey()
		];

		$this->logger->info( 'IndexerRecoveryJob: {subject}', $context );
	}

	private function doMap( $bulk, $changeDiff ) {

		$inserts = [];
		$inverted = [];

		// In the event that a _SOBJ (or hereafter any inherited object)
		// is deleted, remove the reference directly from the index since
		// the object is embedded and is therefore handled outside of the
		// normal wikiPage delete action
		foreach ( $changeDiff->getTableChangeOps() as $tableChangeOp ) {
			foreach ( $tableChangeOp->getFieldChangeOps( ChangeOp::OP_DELETE ) as $fieldChangeOp ) {

				if ( !$fieldChangeOp->has( 'o_id' ) ) {
					continue;
				}

				$bulk->delete( [ '_id' => $fieldChangeOp->get( 'o_id' ) ] );
			}
		}

		$propertyList = $changeDiff->getPropertyList( 'id' );

		foreach ( $changeDiff->getDataOps() as $tableChangeOp ) {
			foreach ( $tableChangeOp->getFieldChangeOps() as $fieldChangeOp ) {

				if ( !$fieldChangeOp->has( 's_id' ) ) {
					continue;
				}

				$this->mapRows( $fieldChangeOp, $propertyList, $inserts, $inverted );
			}
		}

		foreach ( $inverted as $id => $update ) {
			$bulk->upsert( [ '_id' => $id ], $update );
		}

		foreach ( $inserts as $id => $value ) {
			$bulk->index( [ '_id' => $id ], $value );
		}
	}

	private function mapRows( $fieldChangeOp, $propertyList, &$insertRows, &$invertedRows ) {

		// The structure to be expected in ES:
		//
		// "subject": {
		//    "title": "Foaf:knows",
		//    "subobject": "",
		//    "namespace": 102,
		//    "interwiki": "",
		//    "sortkey": "Foaf:knows"
		// },
		// "P:8": {
		//    "txtField": [
		//       "foaf knows http://xmlns.com/foaf/0.1/ Type:Page"
		//    ]
		// },
		// "P:29": {
		//    "datField": [
		//       2458150.6958333
		//    ]
		// },
		// "P:1": {
		//    "uriField": [
		//       "http://semantic-mediawiki.org/swivt/1.0#_wpg"
		//    ]
		// }

		// - datField (time value) is a numeric field (JD number) to allow using
		// ranges on dates with values being representable from January 1, 4713 BC
		// (proleptic Julian calendar)

		$sid = $fieldChangeOp->get( 's_id' );

		if ( !isset( $insertRows[$sid] ) ) {
			$insertRows[$sid] = [];
		}

		if ( !isset( $insertRows[$sid]['subject'] ) ) {
			$dataItem = $this->store->getObjectIds()->getDataItemById( $sid );
			$sort = $dataItem->getSortKey();

			// Use collated sort field if available
			if ( $dataItem->getOption( 'sort', '' ) !== '' ) {
				$sort = $dataItem->getOption( 'sort' );
			}

			// Avoid issue with the Ealstic serializer
			$sort = CharArmor::removeSpecialChars(
				CharArmor::removeControlChars( $sort )
			);

			$insertRows[$sid]['subject'] = [
				'title' => str_replace( '_', ' ', $dataItem->getDBKey() ),
				'subobject' => $dataItem->getSubobjectName(),
				'namespace' => $dataItem->getNamespace(),
				'interwiki' => $dataItem->getInterwiki(),
				'sortkey'   => $sort
			];
		}

		// Avoid issues where the p_id is unknown as in case of an empty
		// concept (red linked) as reference
		if ( !$fieldChangeOp->has( 'p_id' ) ) {
			return;
		}

		$ins = $fieldChangeOp->getChangeOp();
		$pid = $fieldChangeOp->get( 'p_id' );

		$prop = isset( $propertyList[$pid] ) ? $propertyList[$pid] : [];

		$pid = 'P:' . $pid;
		unset( $ins['s_id'] );

		$val = 'n/a';
		$type = 'wpgField';

		if ( $fieldChangeOp->has( 'o_blob' ) && $fieldChangeOp->has( 'o_hash' ) ) {
			$type = 'txtField';
			$val = $ins['o_blob'] === null ? $ins['o_hash'] : $ins['o_blob'];

			// #3020, 3035
			if ( isset( $prop['_type'] ) && $prop['_type'] === '_keyw' ) {
				$val = DIBlob::normalize( $ins['o_hash'] );
			}

			// Remove control chars and avoid Elasticsearch to throw a
			// "SmartSerializer.php: Failed to JSON encode: 5" since JSON requires
			// valid UTF-8
			$val = $this->removeLinks( mb_convert_encoding( $val, 'UTF-8', 'UTF-8' ) );
		} elseif ( $fieldChangeOp->has( 'o_serialized' ) && $fieldChangeOp->has( 'o_blob' ) ) {
			$type = 'uriField';
			$val = $ins['o_blob'] === null ? $ins['o_serialized'] : $ins['o_blob'];
		} elseif ( $fieldChangeOp->has( 'o_serialized' ) && $fieldChangeOp->has( 'o_sortkey' ) ) {
			$type = strpos( $ins['o_serialized'], '/' ) !== false ? 'datField' : 'numField';
			$val = (float)$ins['o_sortkey'];
		} elseif ( $fieldChangeOp->has( 'o_value' ) ) {
			$type = 'booField';
			// Avoid a "Current token (VALUE_NUMBER_INT) not of boolean type ..."
			$val = $ins['o_value'] ? true : false;
		} elseif ( $fieldChangeOp->has( 'o_lat' ) ) {
			// https://www.elastic.co/guide/en/elasticsearch/reference/6.1/geo-point.html
			// Geo-point expressed as an array with the format: [ lon, lat ]
			// Geo-point expressed as a string with the format: "lat,lon".
			$type = 'geoField';
			$val = $ins['o_serialized'];
		} elseif ( $fieldChangeOp->has( 'o_id' ) ) {
			$type = 'wpgField';
			$val = $this->store->getObjectIds()->getDataItemById( $ins['o_id'] )->getSortKey();
			$val = mb_convert_encoding( $val, 'UTF-8', 'UTF-8' );

			if ( !isset( $insertRows[$sid][$pid][$type] ) ) {
				$insertRows[$sid][$pid][$type] = [];
			}

			$insertRows[$sid][$pid][$type] = array_merge( $insertRows[$sid][$pid][$type], [ $val ] );
			$type = 'wpgID';
			$val = (int)$ins['o_id'];

			// Create a minimal body for an inverted relation
			//
			// When a query `[[-Has mother::Michael]]` inquiries that relationship
			// on the fact of `Michael` -> `[[Has mother::Carol]] with `Carol`
			// being redlinked (not exists as page) the query can match the object
			if ( !isset( $invertedRows[$val] ) ) {
				$invertedRows[$val] = [ 'noop' => [] ];
			}

			// A null, [] (an empty array), and [null] are all equivalent, they
			// simply don't exists in an inverted index
		}

		if ( !isset( $insertRows[$sid][$pid][$type] ) ) {
			$insertRows[$sid][$pid][$type] = [];
		}

		$insertRows[$sid][$pid][$type] = array_merge(
			$insertRows[$sid][$pid][$type],
			[ $val ]
		);
	}

	/**
	 * Remove anything that resembles [[:...|foo]] to avoid distracting the indexer
	 * with internal links annotation that are not relevant.
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	public function removeLinks( $text ) {

		// {{DEFAULTSORT: ... }}
		$text = preg_replace( "/\\{\\{([^|]+?)\\}\\}/", "", $text );
		$text = preg_replace( '/\\[\\[[\s\S]+?::/', '[[', $text );

		// [[:foo|bar]]
		$text = preg_replace( '/\\[\\[:[^|]+?\\|/', '[[', $text );
		$text = preg_replace( "/\\{\\{([^|]+\\|)(.*?)\\}\\}/", "\\2", $text );
		$text = preg_replace( "/\\[\\[([^|]+?)\\]\\]/", "\\1", $text );

		// [[Has foo::Bar]]
		//	$text = \SMW\Parser\LinksEncoder::removeAnnotation( $text );

		return $text;
	}

	private function setupIndexByType( $type ) {

		$connection = $this->store->getConnection( 'elastic' );
		$indices = $connection->indices();

		$index = $connection->getIndexNameByType( $type );

		// Shouldn't happen but just in case where the root index is
		// used as index but not an alias
		if ( $indices->exists( [ 'index' => "$index" ] ) && !$indices->existsAlias( [ 'name' => "$index" ] ) ) {
			$indices->delete(  [ 'index' => "$index" ] );
		}

		// Check v1/v2 and if both exists (which shouldn't happen but most likely
		// caused by an unfinshed rebuilder run) then use v1 as master
		if ( $indices->exists( [ 'index' => "$index-v1" ] ) ) {

			// Just in case
			if ( $indices->exists( [ 'index' => "$index-v2" ] ) ) {
				$indices->delete(  [ 'index' => "$index-v2" ] );
			}

			$actions[] = [ 'add' => [ 'index' => "$index-v1", 'alias' => $index ] ];
		} elseif ( $indices->exists( [ 'index' => "$index-v2" ] ) ) {
			$actions[] = [ 'add' => [ 'index' => "$index-v2", 'alias' => $index ] ];
		} else {
			$version = $connection->createIndex( $type );

			$actions = [
				[ 'add' => [ 'index' => "$index-$version", 'alias' => $index ] ]
			];
		}

		$params['body'] = [ 'actions' => $actions ];
		$indices->updateAliases( $params );
	}

	private function deleteIndexByType( $type ) {

		$connection = $this->store->getConnection( 'elastic' );
		$indices = $connection->indices();

		$index = $connection->getIndexNameByType( $type );

		if ( $indices->exists( [ 'index' => "$index-v1" ] ) ) {
			$indices->delete( [ 'index' => "$index-v1" ] );
		}

		if ( $indices->exists( [ 'index' => "$index-v2" ] ) ) {
			$indices->delete( [ 'index' => "$index-v2" ] );
		}

		if ( $indices->exists( [ 'index' => "$index" ] ) && !$indices->existsAlias( [ 'name' => "$index" ] ) ) {
			$indices->delete( [ 'index' => "$index" ] );
		}

		$connection->releaseLock( $type );
	}

}
