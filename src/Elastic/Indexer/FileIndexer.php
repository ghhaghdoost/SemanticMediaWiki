<?php

namespace SMW\Elastic\Indexer;

use Psr\Log\LoggerAwareTrait;
use SMW\DIWikiPage;
use SMW\Elastic\Connection\Client as ElasticClient;
use Onoi\MessageReporter\MessageReporterAwareTrait;
use File;
use Title;
use RuntimeException;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class FileIndexer {

	use MessageReporterAwareTrait;
	use LoggerAwareTrait;

	/**
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * @var string
	 */
	private $origin = '';

	/**
	 * @var boolean
	 */
	private $noCheck = false;

	/**
	 * @since 3.0
	 *
	 * @return Indexer $indexer
	 */
	public function __construct( Indexer $indexer ) {
		$this->indexer = $indexer;
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
	 */
	public function noCheck() {
		$this->noCheck = true;
	}

	/**
	 * @since 3.0
	 *
	 * @param File|null $file
	 */
	public function planIngestJob( Title $title ) {

		$fileIngestJob = new FileIngestJob(
			$title
		);

		$fileIngestJob->insert();
	}

	/**
	 * @since 3.0
	 *
	 * @param DIWikiPage $dataItem
	 * @param File|null $file
	 */
	public function index( DIWikiPage $dataItem, File $file = null ) {

		if ( $dataItem->getId() == 0 ) {
			$dataItem->setId( $this->indexer->getId( $dataItem ) );
		}

		if ( $dataItem->getId() == 0 || $dataItem->getNamespace() !== NS_FILE ) {
			return;
		}

		$time = -microtime( true );

		$params = [
			'id' => 'attachment',
			'body' => [
				'description' => 'Extract attachment information',
				'processors' => [
					[
						'attachment' => [
							'field' => 'file_content',
							'indexed_chars' => -1
						]
					],
					[
						'remove' => [
							"field" => "file_content"
						]
					]
				]
			],
		];

		$connection = $this->indexer->getConnection();
		$connection->ingest()->putPipeline( $params );

		if ( $file === null ) {
			$file = wfFindFile( $dataItem->getTitle() );
		}

		if ( $file === false || $file === null ) {
			return;
		}

		$url = $file->getFullURL();
		$id = $dataItem->getId();

		$sha1 = $file->getSha1();
		$ingest = true;

		$index = $this->indexer->getIndexName( ElasticClient::TYPE_DATA );
		$doc = [ '_source' => [] ];

		$params = [
			'index' => $index,
			'type'  => ElasticClient::TYPE_DATA,
			'id'    => $id,
		];

		// Do we have any existing data? The ingest pipeline will override the
		// entire document, so rescue any data before starting the ingest.
		if ( $connection->exists( $params ) ) {
			$doc = $connection->get( $params + [ '_source_include' => [ 'file_sha1', 'subject', 'text_raw', 'text_copy', 'P*' ] ] );
		}

		// Is the sha1 the same? Don't do anything since the content is expected
		// to be the same!
		if ( !$this->noCheck && isset( $doc['_source']['file_sha1'] ) && $doc['_source']['file_sha1'] === $sha1 ) {
			$ingest = false;
		}

		$context = [
			'method' => __METHOD__,
			'role' => 'production',
			'origin' => $this->origin,
			'subject' => $dataItem->getHash()
		];

		if ( $ingest === false ) {
			return $this->logger->info( 'File: {subject}, no ingest process.', $context );
		}

		$context['response'] = $connection->index(
			$params + [
			'pipeline' => 'attachment',
			'body' => [
				'file_content' => base64_encode( file_get_contents( $url ) ),
				'file_path' => $url,
				'file_sha1' => $sha1,
			] + $doc['_source'] ]
		);

		$context['procTime'] = microtime( true ) + $time;

		$this->logger->info( 'File: {subject}, procTime (in sec): {procTime}, response: {response}', $context );
	}

}
