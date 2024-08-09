<?php

namespace MediaWiki\Extension\TimeMachine;

use ParserOptions;
use Title;
use WikiPage;

class TimeMachineMissingPage extends WikiPage {
    public function __construct( title $title ) {
        parent::__construct( $title );
    }

    public function exists(): bool {
        return false;
    }

    public function shouldCheckParserCache( ParserOptions $parserOptions, $oldId ) {
        return false;
    }
}
