<?php

namespace MediaWiki\Extension\TimeMachine;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Article;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\Xml\Xml;
use Wikimedia\Parsoid\Core\LinkTarget;

use MediaWiki\Logger\LoggerFactory;

class TimeMachineArticle extends Article {
	private string $timeTravelDate;

	public function __construct( Title $title, $timeTravelDate, $oldId = null ) {
		parent::__construct( $title, $oldId );
		$this->timeTravelDate = $timeTravelDate;
	}

	public function getOldIDFromRequest(): ?int {
		return null;
	}

	protected function tryFileCache(): bool {
		return false;
	}

	public function view() {
		$oldPage = $this->mPage;
		if ( !$this->getOldID() ) {
			$this->mPage = new TimeMachineMissingPage( $this->mPage->getTitle() );
		}

		try {
			parent::view();
		} finally {
			$this->mPage = $oldPage;
		}
	}

	public function setOldSubtitle( $oldid = 0 ) {
		global $wgTimeMachineServedByMove, $wgTimeMachineOriginalTitle;

		$messageKey = 'timemachine-article-info';
		if ( Utils::isTimeTravelingTemporarily( $this->getContext()->getRequest() ) ) {
			$messageKey = 'timemachine-article-info-temporary';
		}

		$linkTitle = $this->getTitle();
		if ( $wgTimeMachineServedByMove ) {
			$linkTitle = $wgTimeMachineOriginalTitle;
		}
		$link = $linkTitle->getFullURL( 'timemachine-date=' . $this->timeTravelDate );

		$text = wfMessage( $messageKey, $this->timeTravelDate, $link )->parse();
		$this->getContext()->getOutput()->addSubtitle( Html::warningBox( $text ) );
	}

	public function getParserOptions( $oldid = null, $user = null ): ParserOptions {
		$base = clone parent::getParserOptions();

		$revision = $this->fetchRevisionRecord();
		if ( !$revision ) {
			return $base;
		}

		$base->setOption( 'disableTitleConversion', true );
		$base->setTemplateCallback( $this->buildTemplateCallback( $revision->getTimestamp() ) );

		return $base;
	}

	private function buildTemplateCallback( string $timestamp ) {
		return function ( LinkTarget $link, $parser = null ) use ( $timestamp ) {
			$title = Title::castFromLinkTarget( $link ); // for compatibility with return type
			$text = false;
			$finalTitle = $title;
			$deps = [];
			$revRecord = null;

			# Loop to fetch the article, with up to 2 redirects

			# Note that $title (including redirect targets) could be
			# external; we do allow hooks a chance to redirect the
			# external title to a local one (which might be useful), but
			# are careful not to add external titles to the dependency
			# list. (T362221)

			$services = MediaWikiServices::getInstance();
			$revLookup = $services->getRevisionLookup();
			for ( $i = 0; $i < 3 && is_object( $title ); $i++ ) {
				# Give extensions a chance to select the revision instead
				$revRecord = null; # Assume no hook
				$origTitle = $title;
				$titleChanged = false;

				$pastRevId = Utils::timeTravelRevision( $title, $timestamp );
				$revRecord = $revLookup->getRevisionById( $pastRevId );
				if ( $revRecord ) {
					# Update title, as $revRecord may have been changed by hook
					$title = Title::newFromPageIdentity( $revRecord->getPage() );
					// Assuming title is not external if we've got a $revRecord
					$deps[] = [
						'title' => $title,
						'page_id' => $revRecord->getPageId(),
						'rev_id' => $revRecord->getId(),
					];
				} elseif ( !$title->isExternal() ) {
					$deps[] = [
						'title' => $title,
						'page_id' => $title->getArticleID(),
						'rev_id' => null,
					];
				}
				if ( !$title->equals( $origTitle ) ) {
					# If we fetched a rev from a different title, register
					# the original title too...
					if ( !$origTitle->isExternal() ) {
						$deps[] = [
							'title' => $origTitle,
							'page_id' => $origTitle->getArticleID(),
							'rev_id' => null,
						];
					}
					$titleChanged = true;
				}
				# If there is no current revision, there is no page
				if ( $revRecord === null || $revRecord->getId() === null ) {
					$linkCache = $services->getLinkCache();
					$linkCache->addBadLinkObj( $title );
				}
				if ( $revRecord ) {
					if ( $titleChanged && !$revRecord->hasSlot( SlotRecord::MAIN ) ) {
						// We've added this (missing) title to the dependencies;
						// give the hook another chance to redirect it to an
						// actual page.
						$text = false;
						$finalTitle = $title;
						continue;
					}
					if ( $revRecord->hasSlot( SlotRecord::MAIN ) ) { // T276476
						$content = $revRecord->getContent( SlotRecord::MAIN );
						$text = $content ? $content->getWikitextForTransclusion() : null;
					} else {
						$text = false;
					}

					if ( $text === false || $text === null ) {
						$text = false;
						break;
					}
				} elseif ( $title->getNamespace() === NS_MEDIAWIKI ) {
					$message = wfMessage(
						$services->getContentLanguage()->lcfirst( $title->getText() )
					)->inContentLanguage();
					if ( !$message->exists() ) {
						$text = false;
						break;
					}
					$text = $message->plain();
					break;
				} else {
					break;
				}
				// @phan-suppress-next-line PhanPossiblyUndeclaredVariable Only reached when content is set
				if ( !$content ) {
					break;
				}
				# Redirect?
				$finalTitle = $title;
				$title = $content->getRedirectTarget();
			}

			$retValues = [
				// previously, when this also returned a Revision object, we set
				// 'revision-record' to false instead of null if it was unavailable,
				// so that callers to use isset and then rely on the revision-record
				// key instead of the revision key, even if there was no corresponding
				// object - we continue to set to false here for backwards compatability
				'revision-record' => $revRecord ?: false,
				'text' => $text,
				'finalTitle' => $finalTitle,
				'deps' => $deps
			];

			return $retValues;
		};
	}

	public function showMissingArticle() {
		$outputPage = $this->getContext()->getOutput();
		$this->getContext()->getRequest()->response()->statusHeader( 404 );

		$dir = $this->getContext()->getLanguage()->getDir();
		$lang = $this->getContext()->getLanguage()->getHtmlCode();
		$text = wfMessage( 'timemachine-missing' )->plain();
		$outputPage->addWikiTextAsInterface(
			Xml::openElement(
				'div',
				[
					'class' => "noarticletext mw-content-$dir",
					'dir' => $dir,
					'lang' => $lang,
				]
			) . "\n$text\n</div>"
		);
	}
}
