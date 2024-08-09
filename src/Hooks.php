<?php

namespace MediaWiki\Extension\TimeMachine;

use MediaWiki\Api\Hook\ApiOpenSearchSuggestHook;
use MediaWiki\Hook\AncientPagesQueryHook;
use MediaWiki\Hook\BeforeInitializeHook;
use MediaWiki\Hook\BeforeParserFetchTemplateAndtitleHook;
use MediaWiki\Hook\InitializeArticleMaybeRedirectHook;
use MediaWiki\Hook\LonelyPagesQueryHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\ParserOptionsRegisterHook;
use MediaWiki\Hook\RandomPageQueryHook;
use MediaWiki\Hook\SpecialSearchResultsHook;
use MediaWiki\Hook\SpecialSearchResultsPrependHook;
use MediaWiki\Page\Hook\ArticleFromTitleHook;
use MediaWiki\Page\Hook\CategoryPageViewHook;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\Search\Hook\ShowSearchHitHook;
use MediaWiki\Search\Hook\ShowSearchHitTitleHook;
use RequestContext;
use Title;
use Xml;

class Hooks implements ParserOptionsRegisterHook, ArticleFromTitleHook, BeforeParserFetchTemplateAndtitleHook,
                       BeforeInitializeHook, InitializeArticleMaybeRedirectHook, PageMoveCompleteHook,
                       SpecialSearchResultsPrependHook, SpecialSearchResultsHook, GetUserPermissionsErrorsHook,
                       RandomPageQueryHook, AncientPagesQueryHook, LonelyPagesQueryHook, CategoryPageViewHook,
                       ShowSearchHitHook, ShowSearchHitTitleHook, ApiOpenSearchSuggestHook {
    public function onBeforeInitialize( $title, $unused, $output, $user, $request, $mediaWiki ) {
        global $wgTimeMachineServedByMove;
        $timeTravelTarget = Utils::getTimeTravelTarget( $request );
        if ( !$timeTravelTarget ) {
            return;
        }

        if ( $request->getBool( 'oldid' ) ) {
            return;
        }

        // If a moved page existed under this name at our time travel destination, use that as the "canonical" page instead
        $moveSource = Utils::findMoveSourceAfter( $title, $timeTravelTarget );
        if ( !$moveSource ) {
            return;
        }

        $newTitle = Title::newFromID( $moveSource );
        if ( !$newTitle ) {
            return;
        }
        $newTitle->prefixedText = $title->getPrefixedText();
        RequestContext::getMain()->setTitle( $newTitle );
        $wgTimeMachineServedByMove = true;
    }

    public function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
        $timeTravelTarget = Utils::getTimeTravelTarget( RequestContext::getMain()->getRequest() );
        if ( !$timeTravelTarget ) {
            return true;
        }

        if ( $action !== 'edit' ) {
            return true;
        }

        $result = 'timemachine-no-editing';

        return false;
    }

    public function onInitializeArticleMaybeRedirect( $title, $request, &$ignoreRedirect, &$target, &$article ) {
        // If we couldn't time-travel the redirect to a previous revision, it didn't exist yet, so ignore it
        if ( $article instanceof TimeMachineArticle && !$article->getOldID() ) {
            $ignoreRedirect = true;
        }
    }

    public function onArticleFromTitle( $title, &$article, $context ) {
        global $wgTimeMachineServedByMove;
        if ( $article ) {
            return;
        }

        switch ( $title->getNamespace() ) {
            case NS_FILE:
            case NS_CATEGORY:
                return;
        }

        $timeTravelTarget = Utils::getTimeTravelTarget( $context->getRequest() );
        if ( !$timeTravelTarget ) {
            return;
        }

        // Replace the article with the closest revision to the timestamp we're traveling to
        $timeTraveledRev = Utils::timeTravelRevision( $title, $timeTravelTarget );

        $oldId = $context->getRequest()->getIntOrNull( 'oldid' );
        if ( $oldId !== null ) {
            $timeTraveledRev = $oldId;
        }

        // If the page was moved to its current name only after our destination timestamp, report the article as missing
        $wasMovedHere = !$wgTimeMachineServedByMove && Utils::wasMovedHereAfter( $title, $timeTravelTarget );
        if ( $wasMovedHere ) {
            $timeTraveledRev = null;
        }

        $article = new TimeMachineArticle( $title, $timeTraveledRev );
    }

    public function onParserOptionsRegister( &$defaults, &$inCacheKey, &$lazyLoad ) {
        $defaults[Utils::ARTICLE_PARSER_OPTION] = false;
        $defaults[Utils::TIMESTAMP_PARSER_OPTION] = null;
    }

    public function onBeforeParserFetchTemplateAndtitle( $parser, $title, &$skip, &$id ) {
        // If currently time-traveling, resolve templates to the same timestamp as we're traveling to
        $timeMachine = $parser->getOptions()->getOption( Utils::ARTICLE_PARSER_OPTION );
        if ( !$timeMachine ) {
            return;
        }

        $timeMachineTimestamp = $parser->getOptions()->getOption( Utils::TIMESTAMP_PARSER_OPTION );
        $pastRevId = Utils::timeTravelRevision( $title, $timeMachineTimestamp );

        if ( !$pastRevId ) {
            return;
        }

        $id = $pastRevId;
    }

    public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
        $db = wfGetDB( DB_MASTER );
        $db->insert(
            'timemachine_title_history',
            [
                'tm_page_id' => $pageid,
                'tm_old_title' => $old->getDBkey(),
                'tm_new_title' => $new->getDBkey(),
                'tm_timestamp' => $revision->getTimestamp()
            ],
            __METHOD__
        );
    }

    public function onSpecialSearchResults( $term, &$titleMatches, &$textMatches ) {
        $timeTravelTarget = Utils::getTimeTravelTarget( RequestContext::getMain()->getRequest() );
        if ( !$timeTravelTarget ) {
            return;
        }

        // Filter out search results that definitely didn't exist yet at the time travel destination

        if ( $titleMatches ) {
            $titleMatches = new TimeMachineSearchResultSet( $titleMatches, $timeTravelTarget );
        }

        if ( $textMatches ) {
            $textMatches = new TimeMachineSearchResultSet( $textMatches, $timeTravelTarget );
        }
    }

    public function onApiOpenSearchSuggest( &$results ) {
        $timeTravelTarget = Utils::getTimeTravelTarget( RequestContext::getMain()->getRequest() );
        if ( !$timeTravelTarget ) {
            return;
        }

        // Filter out search results that definitely didn't exist yet at the time travel destination
        $results = array_filter(
            $results,
            function ( $result ) use ( $timeTravelTarget ) {
                $title = $result['title'];
                $existed = Utils::timeTravelRevision( $title, $timeTravelTarget ) !== null;

                return $existed && !Utils::wasMovedHereAfter( $title, $timeTravelTarget );
            }
        );

        wfDebug( '--- EDITING SUGGESTION RESULTS ---' );
        foreach ( $results as $suggestion ) {
            wfDebug( $suggestion['title']->getPrefixedText() );
        }
        wfDebug( '--- END EDITING SUGGESTION RESULTS ---' );
    }

    public function onSpecialSearchResultsPrepend( $searchPage, $output, $term ) {
        $timeTravelTarget = Utils::getTimeTravelTarget( $searchPage->getRequest() );
        if ( !$timeTravelTarget ) {
            return;
        }

        $dir = $searchPage->getContext()->getLanguage()->getDir();
        $lang = $searchPage->getContext()->getLanguage()->getHtmlCode();
        $text = wfMessage( 'timemachine-search-info' )->parse();
        $content = Xml::openElement(
                'div',
                [
                    'class' => "noarticletext mw-content-$dir warningbox",
                    'dir' => $dir,
                    'lang' => $lang,
                ]
            ) . "\n$text\n</div>";
        $output->addSubtitle( $content );
    }

    public function onShowSearchHitTitle(
        &$title,
        &$titleSnippet,
        $result,
        $terms,
        $searchPage,
        &$query,
        &$attributes
    ) {
        $timeTravelTarget = Utils::getTimeTravelTarget( $searchPage->getRequest() );
        if ( !$timeTravelTarget ) {
            return;
        }

        // Replace title if page had a different name at the time travel destination
        $oldTitle = Utils::findTitleAt( $title, $timeTravelTarget );
        if ( $oldTitle ) {
            $title = $oldTitle;
        }
    }

    public function onShowSearchHit(
        $searchPage,
        $result,
        $terms,
        &$link,
        &$redirect,
        &$section,
        &$extract,
        &$score,
        &$size,
        &$date,
        &$related,
        &$html
    ) {
        $timeTravelTarget = Utils::getTimeTravelTarget( $searchPage->getRequest() );
        if ( !$timeTravelTarget ) {
            return;
        }

        // Hide preview and redirect info since they might contain spoilers
        $extract = '';
        $redirect = '';
    }

    public function onCategoryPageView( $catpage ) {
        $timeTravelTarget = Utils::getTimeTravelTarget( $catpage->getContext()->getRequest() );
        if ( !$timeTravelTarget ) {
            return;
        }

        // We can't easily filter what pages get displayed on a category page, but at least we can show a warning

        $dir = $catpage->getContext()->getLanguage()->getDir();
        $lang = $catpage->getContext()->getLanguage()->getHtmlCode();
        $text = wfMessage( 'timemachine-category-info' )->parse();
        $content = Xml::openElement(
                'div',
                [
                    'class' => "noarticletext mw-content-$dir warningbox",
                    'dir' => $dir,
                    'lang' => $lang,
                ]
            ) . "\n$text\n</div>";
        $catpage->getContext()->getOutput()->addSubtitle( $content );
    }

    public function onRandomPageQuery( &$tables, &$conds, &$joinConds ) {
        $this->adjustPagesQuery( $tables, $conds, $joinConds );
    }

    public function onAncientPagesQuery( &$tables, &$conds, &$joinConds ) {
        $this->adjustPagesQuery( $tables, $conds, $joinConds );
    }

    public function onLonelyPagesQuery( &$tables, &$conds, &$joinConds ) {
        $this->adjustPagesQuery( $tables, $conds, $joinConds );
    }

    private function adjustPagesQuery( &$tables, &$conds, &$joinConds ) {
        $timeTravelTarget = Utils::getTimeTravelTarget( RequestContext::getMain()->getRequest() );
        if ( !$timeTravelTarget ) {
            return;
        }

        $dbr = wfGetDB( DB_REPLICA );

        $conds[] = 'rev_timestamp < ' . $dbr->timestamp( $timeTravelTarget );

        if ( in_array( 'revision', $tables ) ) {
            return;
        }

        $tables[] = 'revision';
        $joinConds['revision'] = [
            'INNER JOIN',
            [
                'page_id = rev_page',
                'rev_parent_id' => 0
            ]
        ];
    }
}
