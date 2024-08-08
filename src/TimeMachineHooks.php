<?php

namespace MediaWiki\Extension\TimeMachine;

use MediaWiki\Hook\BeforeInitializeHook;
use MediaWiki\Hook\BeforeParserFetchTemplateAndtitleHook;
use MediaWiki\Hook\InitializeArticleMaybeRedirectHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\ParserOptionsRegisterHook;
use MediaWiki\Hook\SpecialSearchResultsHook;
use MediaWiki\Hook\SpecialSearchResultsPrependHook;
use MediaWiki\Page\Hook\ArticleFromTitleHook;
use RequestContext;
use Title;
use Xml;

class TimeMachineHooks
	implements ParserOptionsRegisterHook, ArticleFromTitleHook, BeforeParserFetchTemplateAndtitleHook,
	BeforeInitializeHook, InitializeArticleMaybeRedirectHook, PageMoveCompleteHook, SpecialSearchResultsPrependHook,
	SpecialSearchResultsHook {
	public function onParserOptionsRegister( &$defaults, &$inCacheKey, &$lazyLoad ) {
		$defaults[TimeMachineUtils::ARTICLE_PARSER_OPTION] = false;
		$defaults[TimeMachineUtils::TIMESTAMP_PARSER_OPTION] = null;
	}

	public function onBeforeInitialize( $title, $unused, $output, $user, $request, $mediaWiki ) {
		global $wgTimeMachineServedByMove;
		$timeTravelTarget = TimeMachineUtils::getTimeTravelTarget( $request );
		if ( !$timeTravelTarget ) {
			return;
		}

		// If a moved page existed under this name at our time travel destination, use that as the "canonical" page instead
		$moveSource = TimeMachineUtils::findMoveSourceAfter( $title, $timeTravelTarget );
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

		$timeTravelTarget = TimeMachineUtils::getTimeTravelTarget( $context->getRequest() );
		if ( !$timeTravelTarget ) {
			return;
		}

		// Replace the article with the closest revision to the timestamp we're traveling to
		// If the page was moved to its current name only after our destination timestamp, report the article as missing
		$timeTraveledRev = TimeMachineUtils::timeTravelRevision( $title, $timeTravelTarget );
		$wasMovedHere = !$wgTimeMachineServedByMove && TimeMachineUtils::wasMovedHereAfter( $title, $timeTravelTarget );
		if ( $wasMovedHere ) {
			$timeTraveledRev = null;
		}

		$article = new TimeMachineArticle( $title, $timeTraveledRev );
		$article->mTimestamp = $timeTravelTarget;
	}

	public function onBeforeParserFetchTemplateAndtitle( $parser, $title, &$skip, &$id ) {
		// If currently time-traveling, resolve templates to the same timestamp as we're traveling to
		$timeMachine = $parser->getOptions()->getOption( TimeMachineUtils::ARTICLE_PARSER_OPTION );
		if ( !$timeMachine ) {
			return;
		}

		$timeMachineTimestamp = $parser->getOptions()->getOption( TimeMachineUtils::TIMESTAMP_PARSER_OPTION );
		$pastRevId = TimeMachineUtils::timeTravelRevision( $title, $timeMachineTimestamp );

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

	public function onSpecialSearchResultsPrepend( $specialSearch, $output, $term ) {
		$dir = $specialSearch->getContext()->getLanguage()->getDir();
		$lang = $specialSearch->getContext()->getLanguage()->getHtmlCode();
		$text = wfMessage( 'timemachine-search-info' )->parse();
		$content = Xml::openElement( 'div', [
				'class' => "noarticletext mw-content-$dir warningbox",
				'dir' => $dir,
				'lang' => $lang,
			] ) . "\n$text\n</div>";
		$output->addSubtitle( $content );
	}

	public function onSpecialSearchResults( $term, &$titleMatches, &$textMatches ) {
		// TODO: Implement basic search result filtering here to exclude any pages that definitely did not exist yet
	}
}
