<?php

namespace MediaWiki\Extension\TimeMachine;

use BaseSearchResultSet;
use ISearchResultSet;
use SearchResult;
use SearchResultSetTrait;
use Title;

class TimeMachineSearchResultSet extends BaseSearchResultSet {
    use SearchResultSetTrait;

    private ISearchResultSet $delegate;
    private string $timestamp;

    /**
     * @var Title[]
     */
    private $titles;

    /**
     * @var SearchResult[]
     */
    private $results;

    private bool $hasMoreResults;

    public function __construct( ISearchResultSet $delegate, string $timestamp ) {
        $this->delegate = $delegate;
        $this->timestamp = $timestamp;
        $this->hasMoreResults = $this->delegate->hasMoreResults();
    }

    public function extractResults() {
        if ( $this->results === null ) {
            $this->results = array_filter(
                $this->delegate->extractResults(),
                function ( SearchResult $result ) {
                    return Utils::timeTravelRevision( $result->getTitle(), $this->timestamp ) !== null;
                }
            );
        }

        return $this->results;
    }

    public function extractTitles() {
        if ( $this->titles === null ) {
            if ( $this->numRows() == 0 ) {
                // Don't bother if we've got empty result
                $this->titles = [];
            } else {
                $this->titles = array_map(
                    function ( SearchResult $result ) {
                        return $result->getTitle();
                    },
                    $this->extractResults()
                );
            }
        }

        return $this->titles;
    }

    public function hasMoreResults() {
        return $this->hasMoreResults;
    }

    public function shrink( $limit ) {
        if ( $this->count() > $limit ) {
            $this->hasMoreResults = true;
            $this->results = array_slice( $this->results, 0, $limit );
            $this->titles = null;
        }
    }

    public function count() {
        return count( $this->extractResults() );
    }

    public function numRows() {
        return $this->count();
    }

    public function getTotalHits() {
        return null;
    }

    public function hasRewrittenQuery() {
        return $this->delegate->hasRewrittenQuery();
    }

    public function getQueryAfterRewrite() {
        return $this->delegate->getQueryAfterRewrite();
    }

    public function getQueryAfterRewriteSnippet() {
        return $this->delegate->getQueryAfterRewriteSnippet();
    }

    public function hasSuggestion() {
        return $this->delegate->hasSuggestion();
    }

    public function getSuggestionQuery() {
        return $this->delegate->getSuggestionQuery();
    }

    public function getSuggestionSnippet() {
        return $this->delegate->getSuggestionSnippet();
    }

    public function getInterwikiResults( $type = self::SECONDARY_RESULTS ) {
        return $this->delegate->getInterwikiResults( $type );
    }

    public function hasInterwikiResults( $type = self::SECONDARY_RESULTS ) {
        return $this->delegate->hasInterwikiResults( $type );
    }

    public function searchContainedSyntax() {
        return $this->delegate->searchContainedSyntax();
    }
}
