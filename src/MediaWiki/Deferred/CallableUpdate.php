<?php

namespace SMW\MediaWiki\Deferred;

use Closure;
use DeferrableUpdate;
use DeferredUpdates;
use RuntimeException;
use Psr\Log\LoggerAwareTrait;
use SMW\MediaWiki\Database;

/**
 * @see MWCallableUpdate
 *
 * @license GNU GPL v2+
 * @since 2.4
 */
class CallableUpdate implements DeferrableUpdate {

	use LoggerAwareTrait;

	/**
	 * Updates that should run before flushing output buffer
	 */
	const STAGE_PRESEND = 'pre';

	/**
	 * Updates that should run after flushing output buffer
	 */
	const STAGE_POSTSEND = 'post';

	/**
	 * @var Closure|callable
	 */
	private $callback;

	/**
	 * @var boolean
	 */
	protected $isDeferrableUpdate = true;

	/**
	 * @var boolean
	 */
	protected $isCommandLineMode = false;

	/**
	 * @var boolean
	 */
	private $isPending = false;

	/**
	 * @var string
	 */
	private $origin = '';

	/**
	 * @var array
	 */
	private static $pendingUpdates = array();

	/**
	 * @var string|null
	 */
	private $fingerprint = null;

	/**
	 * @var array
	 */
	private static $queueList = array();

	/**
	 * @var string
	 */
	private $stage;

	/**
	 * @since 2.4
	 *
	 * @param Closure $callback|null
	 * @param Database|null $connection
	 */
	public function __construct( Closure $callback = null ) {

		if ( $callback === null ) {
			$callback = [ $this, 'emptyCallback' ];
		}

		$this->callback = $callback;
		$this->stage = self::STAGE_POSTSEND;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:$wgCommandLineMode
	 * Indicates whether MW is running in command-line mode.
	 *
	 * @since 2.5
	 *
	 * @param boolean $isCommandLineMode
	 */
	public function isCommandLineMode( $isCommandLineMode ) {
		$this->isCommandLineMode = $isCommandLineMode;
	}

	/**
	 * @since 3.0
	 */
	public function asPresend() {
		$this->stage = self::STAGE_PRESEND;
	}

	/**
	 * @since 3.0
	 *
	 * @return string
	 */
	public function getStage() {
		return $this->stage;
	}

	/**
	 * @since 3.0
	 *
	 * @param Closure $callback
	 */
	public function setCallback( Closure $callback ) {
		$this->callback = $callback;
	}

	/**
	 * @deprecated since 3.0, use DeferredCallableUpdate::isDeferrableUpdate
	 * @since 2.4
	 */
	public function enabledDeferredUpdate( $enabledDeferredUpdate = true ) {
		$this->isDeferrableUpdate( $enabledDeferredUpdate );
	}

	/**
	 * @note Unit/Integration tests in MW 1.26- showed ambiguous behaviour when
	 * run in deferred mode because not all MW operations were supporting late
	 * execution.
	 *
	 * @since 3.0
	 */
	public function isDeferrableUpdate( $isDeferrableUpdate ) {
		$this->isDeferrableUpdate = (bool)$isDeferrableUpdate;
	}

	/**
	 * @note If wgCommandLineMode = true (e.g. MW is in CLI mode) then
	 * DeferredUpdates::addUpdate pushes updates directly into execution mode
	 * which may not be desirable for all update processes therefore hold on to it
	 * by using an internal waitableUpdate list and release them at convenience.
	 *
	 * @since 2.4
	 *
	 * @param booloan $isPending
	 */
	public function markAsPending( $isPending = false ) {
		$this->isPending = (bool)$isPending;
	}

	/**
	 * @note Set a fingerprint allowing it to track and detect duplicate update
	 * requests while being unprocessed.
	 *
	 * @since 2.5
	 *
	 * @param string|null $queue
	 */
	public function setFingerprint( $fingerprint = null ) {
		$this->fingerprint = md5( $fingerprint );
	}

	/**
	 * @since 3.0
	 *
	 * @param string|null $queue
	 */
	public function getFingerprint() {
		return $this->fingerprint;
	}

	/**
	 * @since 2.5
	 *
	 * @param string $origin
	 */
	public function setOrigin( $origin ) {
		$this->origin = $origin;
	}

	/**
	 * @see DeferrableCallback::getOrigin
	 *
	 * @since 2.5
	 *
	 * @return string
	 */
	public function getOrigin() {

		if ( is_string( $this->origin ) ) {
			$this->origin = [ $this->origin ];
		}

		return json_encode( $this->origin );
	}

	/**
	 * @since 2.4
	 */
	public static function releasePendingUpdates() {
		foreach ( self::$pendingUpdates as $update ) {
			DeferredUpdates::addUpdate( $update );
		}

		self::$pendingUpdates = array();
	}

	/**
	 * @see DeferrableUpdate::doUpdate
	 *
	 * @since 2.4
	 */
	public function doUpdate() {
		call_user_func( $this->callback );
		unset( self::$queueList[$this->fingerprint] );

		$context = [
			'method' => __METHOD__,
			'role' => 'developer',
			'origin' => $this->getOrigin(),
			'fingerprint' => $this->fingerprint
		];

		$this->logger->info( '[CallableUpdate] Update completed: {origin} (fingerprint:{fingerprint})', $context );
	}

	/**
	 * @since 2.5
	 */
	public function pushUpdate() {

		$context = [
			'method' => __METHOD__,
			'role' => 'developer',
			'origin' => $this->getOrigin(),
			'fingerprint' => $this->fingerprint
		];

		if ( $this->fingerprint !== null && isset( self::$queueList[$this->fingerprint] ) ) {
			$this->logger->info( '[CallableUpdate] Push: {origin} (fingerprint: {fingerprint} is already listed, skip)', $context );
			return;
		}

		self::$queueList[$this->fingerprint] = true;

		if ( $this->isPending && $this->isDeferrableUpdate ) {
			$this->logger->info( '[CallableUpdate] Push: {origin} (as pending DeferredCallableUpdate)', $context );
			return self::$pendingUpdates[] = $this;
		}

		if ( !$this->isCommandLineMode && $this->isDeferrableUpdate ) {
			return $this->addUpdate( $this );
		}

		$this->doUpdate();
	}

	protected function addUpdate( $update ) {

		$context = [
			'method' => __METHOD__,
			'role' => 'developer',
			'ctx' => json_encode(
				$this->getLoggableContext(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		];

		$this->logger->info( '[CallableUpdate] Added: {ctx}', $context );
		$stage = null;

		if ( $update->getStage() === self::STAGE_POSTSEND && defined( 'DeferredUpdates::POSTSEND' ) ) {
			$stage = DeferredUpdates::POSTSEND;
		}

		if ( $update->getStage() === self::STAGE_PRESEND && defined( 'DeferredUpdates::PRESEND' ) ) {
			$stage = DeferredUpdates::PRESEND;
		}

		DeferredUpdates::addUpdate( $update, $stage );
	}

	protected function getLoggableContext() {
		return array(
			'origin' => $this->origin,
			'fingerprint' => $this->fingerprint,
			'stage' => $this->stage
		);
	}

	private function emptyCallback() {
		$this->logger->info( '[CallableUpdate] Empty callback!', [ 'role' => 'developer', 'method' => __METHOD__ ] );
	}

}
