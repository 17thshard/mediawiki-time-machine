<?php

namespace MediaWiki\Extension\TimeMachine;

use MediaWiki\MediaWikiServices;
use Title;
use WebRequest;

class Utils {
    public const COOKIE = 'timemachine-date';

    public const ARTICLE_PARSER_OPTION = 'timeMachineArticle';
    public const TIMESTAMP_PARSER_OPTION = 'timeMachineTimestamp';

    public static function getTimeTravelTarget( WebRequest $request ) {
        $date = $request->getCookie( Utils::COOKIE );
        if ( !$date ) {
            return false;
        }

        return wfTimestamp(
            TS_UNIX,
            $date . ' 00:00:00'
        );
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
                $dbr = wfGetDB( DB_REPLICA );
                $result = $dbr->selectField(
                    'revision',
                    'rev_id',
                    [
                        'rev_page' => $title->getArticleID(),
                        'rev_timestamp < ' . $dbr->timestamp( $timestamp )
                    ],
                    __METHOD__,
                    [
                        'ORDER BY' => 'rev_timestamp DESC',
                        'LIMIT' => 1
                    ]
                );

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
                $dbr = wfGetDB( DB_REPLICA );
                $result = $dbr->selectField(
                    'timemachine_title_history',
                    'tm_page_id',
                    [
                        'tm_old_namespace' => $referenceTitle->getNamespace(),
                        'tm_old_title' => $referenceTitle->getDBkey(),
                        'tm_timestamp > ' . $dbr->timestamp( $timestamp )
                    ],
                    __METHOD__,
                    [
                        'ORDER BY' => 'tm_timestamp ASC',
                        'LIMIT' => 1
                    ]
                );

                if ( !$result ) {
                    return null;
                }

                // Make sure the page didn't hold another title in-between
                $intermediateResult = $dbr->selectRow(
                    'timemachine_title_history',
                    [
                        'namespace' => 'tm_old_namespace',
                        'title' => 'tm_old_title'
                    ],
                    [
                        'tm_page_id' => $result,
                        'tm_timestamp > ' . $dbr->timestamp( $timestamp )
                    ],
                    __METHOD__,
                    [
                        'ORDER BY' => 'tm_timestamp ASC',
                        'LIMIT' => 1
                    ]
                );
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
                $dbr = wfGetDB( DB_REPLICA );
                $result = $dbr->selectField(
                    'timemachine_title_history',
                    'tm_page_id',
                    [
                        'tm_new_namespace' => $referenceTitle->getNamespace(),
                        'tm_new_title' => $referenceTitle->getDBkey(),
                        'tm_timestamp > ' . $dbr->timestamp( $timestamp )
                    ],
                    __METHOD__,
                    [
                        'ORDER BY' => 'tm_timestamp ASC',
                        'LIMIT' => 1
                    ]
                );

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
                $dbr = wfGetDB( DB_REPLICA );
                $result = $dbr->selectRow(
                    'timemachine_title_history',
                    [
                        'namespace' => 'tm_old_namespace',
                        'title' => 'tm_old_title'
                    ],
                    [
                        'tm_page_id' => $referenceTitle->getArticleID(),
                        'tm_timestamp > ' . $dbr->timestamp( $timestamp )
                    ],
                    __METHOD__,
                    [
                        'ORDER BY' => 'tm_timestamp ASC',
                        'LIMIT' => 1
                    ]
                );

                if ( !$result ) {
                    return null;
                }

                $title = Title::newFromDBkey( $result->title );
                $title->mNamespace = intval( $result->namespace );

                return $title;
            }
        );
    }
}
