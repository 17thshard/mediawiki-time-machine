<?php

namespace MediaWiki\Extension\TimeMachine;

use MediaWiki\Page\WikiPage;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

class TimeMachineMissingPage extends WikiPage {
	public function __construct( Title $title ) {
		parent::__construct( $title );
	}

	public function exists(): bool {
		return false;
	}

	public function shouldCheckParserCache( ParserOptions $parserOptions, $oldId ) {
		return false;
	}
}
