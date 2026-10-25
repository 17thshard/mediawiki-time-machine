<?php

namespace MediaWiki\Extension\TimeMachine;

use MediaWiki\MediaWikiServices;
use MediaWiki\Request\WebRequest;
use MediaWiki\Title\Title;
use Wikimedia\Timestamp\TimestampFormat;

class Utils {
	public const COOKIE = 'timemachine-date';

	public static function getTimeTravelTarget( WebRequest $request ) {
		$date = $request->getVal( self::COOKIE, $request->getCookie( self::COOKIE ) );
		if ( !$date ) {
			return false;
		}

		return wfTimestamp( TimestampFormat::UNIX, $date . ' 00:00:00' );
	}

	public static function isTimeMachinePermanentlyActive( WebRequest $request ) {
		$date = $request->getCookie( self::COOKIE );
		if ( !$date ) {
			return false;
		}

		return wfTimestamp( TimestampFormat::UNIX, $date . ' 00:00:00' ) !== false;
	}

	public static function isTimeTravelingTemporarily( WebRequest $request ) {
		return $request->getVal( self::COOKIE ) !== null && self::getTimeTravelTarget( $request ) !== false;
	}

	public static function timeTravelRevision( Title $title, string $timestamp ) {
		$cache = MediaWikiServices::getInstance()->getLocalServerObjectCache();
		$articleID = $title->getArticleID();

		return $cache->getWithSetCallback(
			$cache->makeGlobalKey(
				'timemachine',
				$articleID,
				$timestamp
			),
			$cache::TTL_DAY,
			function () use ( $title, $articleID, $timestamp ) {
			$dbProvider = MediaWikiServices::getInstance()->getConnectionProvider();
			$dbr = $dbProvider->getReplicaDatabase();
			$result = $dbr->newSelectQueryBuilder()->from( 'revision' )->field( 'rev_id' )->where(
				[
					'rev_page' => $title->getArticleID(),
					'rev_timestamp < ' . $dbr->timestamp( $timestamp )
				]
			)->orderBy( 'rev_timestamp', 'DESC' )->limit( 1 )->caller( __METHOD__ )->fetchField();

			if ( !$result ) {
				$result = null;
			}

			return $result;
		}
		);
	}

	public static function findMoveSourceAfter( Title $referenceTitle, string $timestamp ) {
		$cache = MediaWikiServices::getInstance()->getLocalServerObjectCache();

		return $cache->getWithSetCallback(
			$cache->makeGlobalKey(
				'timemachine',
				'movesource',
				$referenceTitle->getNamespace(),
				$referenceTitle->getDBkey(),
				$timestamp
			),
			$cache::TTL_DAY,
			function () use ( $referenceTitle, $timestamp ) {
			$dbProvider = MediaWikiServices::getInstance()->getConnectionProvider();
			$dbr = $dbProvider->getReplicaDatabase();
			$result = $dbr->newSelectQueryBuilder()
				->from( 'timemachine_title_history' )
				->field( 'tm_page_id' )
				->where(
					[
						'tm_old_namespace' => $referenceTitle->getNamespace(),
						'tm_old_title' => $referenceTitle->getDBkey(),
						'tm_timestamp > ' . $dbr->timestamp( $timestamp )
					]
				)
				->orderBy( 'tm_timestamp', 'ASC' )
				->limit( 1 )
				->caller( __METHOD__ )
				->fetchField();

			if ( !$result ) {
				return null;
			}

			// Make sure the page didn't hold another title in-between
			$intermediateResult = $dbr->newSelectQueryBuilder()->from( 'timemachine_title_history' )->field(
				'tm_old_namespace',
				'namespace'
			)->field( 'tm_old_title', 'title' )->where(
				[
					'tm_page_id' => $result,
					'tm_timestamp > ' . $dbr->timestamp( $timestamp )
				]
			)->orderBy( 'tm_timestamp', 'ASC' )->limit( 1 )->caller( __METHOD__ )->fetchRow();
			if (
				$intermediateResult &&
				( intval( $intermediateResult->namespace ) !== $referenceTitle->getNamespace() ||
					$intermediateResult->title !== $referenceTitle->getDBkey() )
			) {
				return null;
			}

			return $result;
		}
		);
	}

	public static function wasMovedHereAfter( Title $referenceTitle, string $timestamp ): bool {
		$cache = MediaWikiServices::getInstance()->getLocalServerObjectCache();

		return $cache->getWithSetCallback(
			$cache->makeGlobalKey(
				'timemachine',
				'wasmovedhere',
				$referenceTitle->getNamespace(),
				$referenceTitle->getDBkey(),
				$timestamp
			),
			$cache::TTL_DAY,
			function () use ( $referenceTitle, $timestamp ) {
			$dbProvider = MediaWikiServices::getInstance()->getConnectionProvider();
			$dbr = $dbProvider->getReplicaDatabase();
			$result = $dbr->newSelectQueryBuilder()
				->from( 'timemachine_title_history' )
				->field( 'tm_page_id' )
				->where(
					[
						'tm_new_namespace' => $referenceTitle->getNamespace(),
						'tm_new_title' => $referenceTitle->getDBkey(),
						'tm_timestamp > ' . $dbr->timestamp( $timestamp )
					]
				)
				->orderBy( 'tm_timestamp', 'ASC' )
				->limit( 1 )
				->caller( __METHOD__ )
				->fetchField();

			return $result !== false;
		}
		);
	}

	public static function findTitleAt( Title $referenceTitle, string $timestamp ) {
		$cache = MediaWikiServices::getInstance()->getLocalServerObjectCache();

		return $cache->getWithSetCallback(
			$cache->makeGlobalKey(
				'timemachine',
				'oldtitle',
				$referenceTitle->getNamespace(),
				$referenceTitle->getDBkey(),
				$timestamp
			),
			$cache::TTL_DAY,
			function () use ( $referenceTitle, $timestamp ) {
			$dbProvider = MediaWikiServices::getInstance()->getConnectionProvider();
			$dbr = $dbProvider->getReplicaDatabase();
			$result = $dbr->newSelectQueryBuilder()->from( 'timemachine_title_history' )->field(
				'tm_old_namespace',
				'namespace'
			)->field( 'tm_old_title', 'title' )->where(
				[
					'tm_page_id' => $referenceTitle->getArticleID(),
					'tm_timestamp > ' . $dbr->timestamp( $timestamp )
				]
			)->orderBy( 'tm_timestamp', 'ASC' )->limit( 1 )->caller( __METHOD__ )->fetchRow();

			if ( !$result ) {
				return null;
			}

			$title = Title::newFromDBkey( $result->title );
			$ns = intval( $result->namespace );

			return Title::makeTitle( $ns, $title->getDBkey(), $title->getFragment(), $title->getInterwiki() );
		}
		);
	}
}
