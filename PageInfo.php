<?php
/*
    MediaWiki extension PageInfo: display editing informations.
    Copyright (C) 2011  Philipp Glatza

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see http://www.gnu.org/licenses/.
*/

if ( !defined( "MEDIAWIKI" ) )
	die( "PageInfo extension cannot run without MediaWiki!\n" );

/* ---- CREDITS ---- */
$wgExtensionCredits['other'][] = array(
	'path'           => __FILE__,
	'name'           => 'PageInfo',
	'author'         => 'Philipp Glatza, le-tex publishing services GmbH',
	'version'        => '1.0',
	'description'    => 'A info-box near the table of contents of a wiki page. Informations about creation, edits and views.',
	'url'         	 => 'http://www.mediawiki.org/w/index.php?title=Extension:PageInfo',
);

$PI = new PageInfo();

/* ---- HOOKS and i18n ---- */
$wgHooks['BeforePageDisplay'][] = array( $PI, "pi_Settings" );
$wgHooks['OutputPageBeforeHTML'][] = array( $PI, "pi_Show" );
$wgExtensionMessagesFiles['PageInfo'] = dirname( __FILE__ ) . '/PageInfo.i18n.php';
 
 
/* ---- CLASS PI ---- */
class PageInfo {
	var $pi_MagicWord = "__NO_PI__";
	var $pi_MagicWordMatchedInArticle = FALSE;

	// constructor - empty 
	function PageInfo() {}

	function pi_MagicWordRemove( &$text ) {
		if( strpos( $text, $this->pi_MagicWord ) ) {
			$text = str_replace( $this->pi_MagicWord, '', $text );
			$this->pi_MagicWordMatchedInArticle = TRUE;
		}
		return true;
	}

	function pi_Settings( &$out ) {
		global $wgArticle, $wgPI_COLORPARAM, $wgPI_COLORVALUE, $wgJsMimeType;

		// check for and remove the magic word
		$this->pi_MagicWordRemove( $out->mBodytext );

		// output CSS and JavaScript only on wiki pages
		if( $this->pi_DontDisplay() )
			return true;

		if( !isset( $wgPI_COLORPARAM ) )
			$wgPI_COLORPARAM = "#000";

		if( !isset( $wgPI_COLORVALUE ) )
			$wgPI_COLORVALUE = "#5318FE";

		// CSS
		$out->addScript( '<style type="text/css"><!-- ' 
		. '#pi_break { clear:left; }' 
		. '#toc + #pi { float:left; margin-left:0.5em; }' 
		. 'p.pi_head { font-weight: bold; margin-bottom:1em;}' 
		. 'p.pi_entry { margin:0; }' 
		. 'p.pi_entry_space { margin-bottom:1em; }' 
		. 'span.pi_toggle { font-weight:normal; font-size:94%; }' 
		. "span.pi_param { color:" . $wgPI_COLORPARAM . "; padding-right:0.2em;}" 
		. "span.pi_value { color:" . $wgPI_COLORVALUE . "; }" 
		. 'span.pi_red { color:red; }' 
		. 'span.pi_orange { color:orange; }' 
		. 'span.pi_green { color:green; }' 
		. '--></style>' );

		// JS (copied and modified from wikibits.js / Linker.php)
		$out->addScript( '<script type="' . $wgJsMimeType . '">' 
		. 'function togglePI() {' 
		. "	var pi = document.getElementById('pi-div');" 
		. "	var toggleLink = document.getElementById('pi_togglelink');" 
		. '	if (pi && toggleLink && pi.style.display == "none") {' 
		. '		changeText(toggleLink, piHideText);' 
		. '		pi.style.display = "block";' 
		. '		document.cookie = "showpi=1";' 
		. '	} else {' 
		. '		changeText(toggleLink, piShowText);' 
		. '		pi.style.display = "none";' 
		. '		document.cookie = "showpi=0";' 
		. '	}'
		. '}'
		. 'function PI_CheckCookie() {'
		. 'var cookiePos = document.cookie.indexOf("showpi=");'
		. '	if (cookiePos > -1 && document.cookie.charAt(cookiePos + 8) == 0) {'
		. ' 	togglePI();'
		. ' }'
		. '} '
		. 'function PI_MoveToTOC() {'
		. ' var NodeTOC = document.getElementById("toc");'
		. '	if( NodeTOC != null ) {'
		. '		var NodePIOld = document.getElementById("pi");'
		. '		var NodePINew = NodePIOld.cloneNode(true);'
		. '   var NodePIBreak = document.getElementById("pi_break").cloneNode(true);'
		. '		document.getElementById("bodyContent").removeChild(NodePIOld);'
		. '		document.getElementById("bodyContent").insertBefore( NodePINew, NodeTOC.nextSibling);'
		. '		document.getElementById("bodyContent").insertBefore( NodePIBreak, NodePINew.nextSibling);'
		. '   NodeTOC.style.float = "left"; /*JS*/'
		. '   NodeTOC.style.cssFloat = "left"; /*standards compliant*/'
		. '   NodeTOC.style.styleFloat = "left"; /*IE 6*/'
		. '	}'
		. '}'
		. 'var piShowText = "' . Xml::escapeJsString( wfMsg('showtoc') ) . '";'
		. 'var piHideText = "' . Xml::escapeJsString( wfMsg('hidetoc') ) . '";'
		. 'addOnloadHook( PI_MoveToTOC );'
		. 'addOnloadHook( PI_CheckCookie );'
		. '</script>' );
		return true;
	}

	function pi_Show( &$out, &$text ) {
		global $wgArticle, $wgTitle;
		wfLoadExtensionMessages ('PageInfo');

		// check for and remove the magic word
		$this->pi_MagicWordRemove( $text );
		
		// show infos only on wiki pages
		if( $this->pi_DontDisplay() )
			return true;
		
		// variable declaration
		$dbr = wfGetDB( DB_SLAVE );
		$db = new Database;
		$pi_Container = "";
		$pi_CreationTimestamp = $wgTitle->getEarliestRevTime();
		$pi_LastEditTimestamp = strtotime( $wgArticle->mTouched );
		$pi_CreatedBy = $wgArticle->getUserText();		
		
		// Collect informations
		$pi_sql = "
		SELECT rev_user_text
		FROM " . $db->tableName( 'revision' ) . " 
		WHERE rev_page = " . $wgTitle->getArticleId() . "
		ORDER BY rev_id ASC
		LIMIT 1";
		$pi_CreatedBy_SQL = $dbr->fetchRow( $dbr->query( $pi_sql ) );
		$pi_CreatedBy = $pi_CreatedBy_SQL[ 'rev_user_text' ];

		// Creator
		$pi_Container .= $this->pi_CreateEntry( wfMsgHTML( 'paramCreator' ), $pi_CreatedBy );
		
		// Creation date
		$pi_CreatedOn = $this->pi_CreateEntry( 
			wfMsgHTML( 'paramCreationDate' ), 
			date( 'd.m.Y', strtotime( $pi_CreationTimestamp ) ) );
		$pi_Container .= $pi_CreatedOn;

		// Get other authors
		$pi_sql = "
		SELECT DISTINCT rev_user_text
		FROM " . $db->tableName( 'revision' ) . "
		WHERE rev_user_text NOT REGEXP '^$pi_CreatedBy$' 
		AND rev_page = " . $wgTitle->getArticleId();
		$pi_Contributors_SQL = $dbr->fetchRow( $dbr->query( $pi_sql ) );
		if( is_array( $pi_Contributors_SQL ) ) {
			$pi_Contributors_SQL = array_unique( $pi_Contributors_SQL );
			if( count( $pi_Contributors_SQL ) > 0 ) {
				foreach( $pi_Contributors_SQL as $contributor ) {
					$pi_Contributors .= $contributor . ", ";
				}
				$pi_Container .= $this->pi_CreateEntry( 
					wfMsgHTML( 'paramOtherAuthors' ), 
					substr( $pi_Contributors, 0, -2 ) );
			}
		}

		// Num of edits
		$pi_sql = "
		SELECT COUNT( * )
		FROM " .  $db->tableName( 'revision' ) . "
		WHERE rev_page = " . $wgTitle->getArticleId();
		$pi_CountEdits = $dbr->fetchRow( $dbr->query( $pi_sql ) );
		$pi_Container .= $this->pi_CreateEntry( wfMsgHTML( 'paramEdits' ), $pi_CountEdits[0] );

		// Date of last edit
		$pi_LastChanged = $this->pi_CreateEntry( 
			wfMsgHTML( 'paramLastEdit' ), 
			date( 'd.m.Y', $pi_LastEditTimestamp ) );
		$pi_Container .= $pi_LastChanged;
		
		// output space
		$pi_Container .= "<p class='pi_entry_space'></p>";

		// Who can edit the page?
		$pi_Container .= $this->pi_CreateEntry( 
			wfMsgHTML( 'paramEditRights' ), 
			$this->pi_GetEditRights() );
		
		// Mark, who long since last edit?
		$pi_SiteRating = $this->pi_CreateEntry( 
			wfMsgHTML( 'paramEditStatus' ), 
			$this->pi_GetEditStatus( $pi_LastEditTimestamp ) );
		$pi_Container .= $pi_SiteRating;
		
		// Total pageviews
		$pi_Container .= $this->pi_CreateEntry( wfMsgHTML( 'paramViews' ), $wgArticle->getCount() );

		// Total watchers
		$pi_sql = "
		SELECT COUNT( * )
		FROM " . $db->tableName( 'watchlist' ) . "
		WHERE wl_title = '" . $wgTitle->getDBkey() . "'
		AND wl_namespace = " . $wgTitle->getNamespace();
		$pi_Watcher_SQL = $dbr->fetchRow( $dbr->query( $pi_sql ) );
		$pi_Watcher = $this->pi_CreateEntry( wfMsgHTML( 'paramWatcher' ), $pi_Watcher_SQL[0] );
		$pi_Container .= $pi_Watcher;

		$pi_Container .= "<p class='pi_entry_space-last'></p>";

		// output the info-container
		$text = $this->pi_Content( $pi_Container ) . $text;
		return true;
	}

	function pi_CreateEntry( $param, $value ) {
		return "<p class='pi_entry'><span class='pi_param'>$param</span> "
					 . "<span class='pi_value'>$value</span></p>\n";
	}

	function pi_Content( $pi_content ) {
		wfLoadExtensionMessages ('PageInfo');
		return
			 '<table onload="PI_CookieCheck()" id="pi" class="toc" summary="' 
		 . wfMsgHTML( 'title' ) .'"><tr><td>'
		 . '<div id="pititle"><h2 class="pi_head">' . wfMsgHTML( 'title' )
		 . " <span class='pi_toggle'>["
		 . "<a id='pi_togglelink' class='internal' href='javascript:togglePI()'>"
		 . wfMsg('hidetoc') . "</a>]"
		 . '</span>'
		 . '</h2></div>'
		 . '<div id="pi-div">'
		 . $pi_content
		 . '</div>'
		 . "</td></tr></table>"
		 . "<div id='pi_break'></div>";
	}

	function pi_GetEditRights() {
		wfLoadExtensionMessages ('PageInfo');
		global $wgGroupPermissions;
		$pi_UserRights = "";
		if( $wgGroupPermissions['*']['edit'] === TRUE ) {
			$pi_UserRights = wfMsgHTML( 'valueEditRights_All' );
		} else {
			$pi_UserRights = wfMsgHTML( 'valueEditRights_Admin' );
		}
		return $pi_UserRights;
	}

	function pi_GetEditStatus( $lastedited ) {
		global $wgPI_EDITSTATUS_MIN, $wgPI_EDITSTATUS_MID, $wgPI_EDITSTATUS_MAX;
		wfLoadExtensionMessages ('PageInfo');
		
		if( !isset( $PI_EDITSTATUS_MIN ) )
			$wgPI_EDITSTATUS_MIN = 30;
		if( !isset( $wgPI_EDITSTATUS_MID ) )
			$wgPI_EDITSTATUS_MID = 60;
		if( !isset( $wgPI_EDITSTATUS_MAX ) )
			$wgPI_EDITSTATUS_MAX = 90;

		// calculate difference
		$pi_DateDiff = floor( ( time() - $lastedited  ) / (60*60*24) );
		$pi_editstatus = "$pi_DateDiff";
		
		// choose edit state
		if( $pi_DateDiff <= $wgPI_EDITSTATUS_MAX || $pi_DateDiff > $wgPI_EDITSTATUS_MAX)
			$pi_editstatus = wfMsgHTML( 'valueEditStatus_PleaseUpdate' ) . " <span class='pi_red'>&#9679;</span>";
		if( $pi_DateDiff <= $wgPI_EDITSTATUS_MID )
			$pi_editstatus = wfMsgHTML( 'valueEditStatus_OutOfDate' ) . " <span class='pi_orange'>&#9679;</span>";
		if( $pi_DateDiff <= $wgPI_EDITSTATUS_MIN )
			$pi_editstatus = wfMsgHTML( 'valueEditStatus_Current' ) . " <span class='pi_green'>&#9679;</span>";

		return $pi_editstatus;
	}

	function pi_DontDisplay() {
		global $wgArticle, $wgPI_Namespaces, $wgRequest, $wgTitle;
		if( !isset( $wgPI_Namespaces ) )
			$wgPI_Namespaces = array(0);
		# special pages: hide PI
		$wgPI_Namespaces[] = -1;
		$dbr = wfGetDB( DB_SLAVE );
		$ns = $dbr->selectField( 
			'page', 
			'page_namespace', 
			array( 'page_id' => $wgTitle->getArticleId() ), 
			__METHOD__ );
		$action = $wgRequest->getVal( 'action' );

		// "do not display" conditions
		if( $wgArticle === null || // page is not really an article
				$this->pi_MagicWordMatchedInArticle || // magic word found
				!$wgArticle->exists() || // article doesnt exist yet
				!in_array( $ns, $wgPI_Namespaces ) || // page not in right namespace
				$action == 'edit' ) // page in edit mode
			return true;
		return false;
	}
}
?>