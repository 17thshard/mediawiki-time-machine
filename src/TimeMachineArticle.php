<?php

namespace MediaWiki\Extension\TimeMachine;

use Article;
use ParserOptions;
use Title;
use Xml;

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

        $dir = $this->getContext()->getLanguage()->getDir();
        $lang = $this->getContext()->getLanguage()->getHtmlCode();
        $messageKey = 'timemachine-article-info';
        if (Utils::isTimeTravelingTemporarily($this->getContext()->getRequest())) {
            $messageKey = 'timemachine-article-info-temporary';
        }

        $linkTitle = $this->getTitle();
        if ($wgTimeMachineServedByMove) {
            $linkTitle = $wgTimeMachineOriginalTitle;
        }
        $link = $linkTitle->getFullURL( 'timemachine-date=' . $this->timeTravelDate );

        $text = wfMessage( $messageKey, $this->timeTravelDate, $link )->parse();
        $content = Xml::openElement(
                'div',
                [
                    'class' => "noarticletext mw-content-$dir warningbox",
                    'dir' => $dir,
                    'lang' => $lang,
                ]
            ) . "\n$text\n</div>";
        $this->getContext()->getOutput()->addSubtitle( $content );
    }

    public function getParserOptions(): ParserOptions {
        $base = clone parent::getParserOptions();

        $revision = $this->fetchRevisionRecord();
        if ( !$revision ) {
            return $base;
        }

        $base->setOption( Utils::ARTICLE_PARSER_OPTION, true );
        $base->setOption( Utils::TIMESTAMP_PARSER_OPTION, $revision->getTimestamp() );
        $base->setOption( 'disableTitleConversion', true );

        return $base;
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
