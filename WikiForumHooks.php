<?php
/**
 * Static class containing all the hooked functions used by WikiForum.
 *
 * @file
 */
class WikiForumHooks {

	/**
	 * Set up the two new parser hooks: <WikiForumList> and <WikiForumThread>
	 *
	 * @param $parser Object: instance of Parser
	 * @return Boolean: true
	 */
	public static function registerParserHooks( &$parser ) {
		$parser->setHook( 'WikiForumList', 'WikiForumHooks::renderWikiForumList' );
		$parser->setHook( 'WikiForumThread', 'WikiForumHooks::renderWikiForumThread' );
		return true;
	}

	/**
	 * Adds a link to Special:WikiForum to the toolbox, after permalink.
	 * Both this and the function below are required to render the link in the
	 * toolbox.
	 *
	 * @param $skinTemplate Object: SkinTemplate instance
	 * @param $nav_urls Array: existing navigation URLs
	 * @param $oldid Integer
	 * @param $revid Integer: revision ID number of the current revision
	 * @return Boolean: true
	 */
	public static function addNavigationLink( &$skinTemplate, &$nav_urls, &$oldid, &$revid ) {
		$nav_urls['wikiforum'] = array(
			'text' => wfMessage( 'wikiforum' )->text(),
			'href' => $skinTemplate->makeSpecialUrl( 'WikiForum' )
		);
		return true;
	}

	/**
	 * Adds a link to Special:WikiForum to the toolbox, after permalink.
	 * Both this and the function above are required to render the link in the
	 * toolbox.
	 *
	 * @param $skinTemplate Object: instance of SkinTemplate class
	 * @return Boolean: true
	 */
	public static function addNavigationLinkToToolbox( &$skinTemplate ) {
		if ( isset( $skinTemplate->data['nav_urls']['wikiforum'] ) ) {
			if ( $skinTemplate->data['nav_urls']['wikiforum']['href'] == '' ) {
				echo '<li id="t-iswikiforum">' . wfMessage( 'wikiforum' )->text() . '</li>';
			} else {
				$url = $skinTemplate->data['nav_urls']['wikiforum']['href'];
				echo '<li id="t-wikiforum"><a href="' . htmlspecialchars( $url ) . '">';
				echo wfMessage( 'wikiforum' )->text();
				echo '</a></li>';
			}
		}
		return true;
	}

	/**
	 * Callback for <WikiForumList> tag.
	 * Takes only the following argument: num (used as the LIMIT for the SQL query)
	 */
	public static function renderWikiForumList( $input, $args, $parser, $frame ) {
		global $wgLang;

		if ( !isset( $args['num'] ) ) {
			$args['num'] = 5;
		}

		$dbr = wfGetDB( DB_SLAVE );
		$sqlThreads = $dbr->select(
			array(
				'wikiforum_forums', 'wikiforum_category', 'wikiforum_threads',
				'user'
			),
			array(
				'*', 'wff_forum', 'wff_forum_name', 'wfc_category',
				'wfc_category_name', 'user_name'
			),
			array(
				'wff_deleted' => 0,
				'wfc_deleted' => 0,
				'wft_deleted' => 0,
				'wff_category = wfc_category',
				'wff_forum = wft_forum'
			),
			__METHOD__,
			array(
				'ORDER BY' => 'wft_last_post_timestamp DESC',
				'LIMIT' => intval( $args['num'] )
			),
			array( 'user' => array( 'LEFT JOIN', 'user_id = wft_user' ) )
		);

		$output = WikiForumGui::getMainPageHeader(
			wfMessage( 'wikiforum-updates' )->text(),
			wfMessage( 'wikiforum-replies' )->text(),
			wfMessage( 'wikiforum-views' )->text(),
			wfMessage( 'wikiforum-latest-reply' )->text()
		);

		foreach ( $sqlThreads as $thread ) {
			$icon = WikiForumClass::getThreadIcon(
				$thread->wft_posted_timestamp,
				$thread->wft_closed,
				$thread->wft_sticky
			);

			$lastpost = '';
			// If there are some replies, then we can obviously figure out who was
			// the last user who posted something on the topic...
			if ( $thread->wft_reply_count > 0 ) {
				$lastpost = wfMessage(
					'wikiforum-by',
					$wgLang->timeanddate( $thread->wft_last_post_timestamp ),
					WikiForumClass::getUserLinkById( $thread->wft_last_post_user ),
					User::newFromId( $thread->wft_last_post_user )->getName(),
					$wgLang->date( $thread->wft_last_post_timestamp ),
					$wgLang->time( $thread->wft_last_post_timestamp )
				)->text();
			}

			$specialPageObj = SpecialPage::getTitleFor( 'WikiForum' );
			// Build the links to the category and forum pages by using Linker
			$categoryLink = Linker::link(
				$specialPageObj,
				$thread->wfc_category_name,
				array(),
				array( 'category' => $thread->wfc_category )
			);
			$forumLink = Linker::link(
				$specialPageObj,
				$thread->wff_forum_name,
				array(),
				array( 'forum' => $thread->wff_forum )
			);
			$threadLink = Linker::link(
				SpecialPage::getTitleFor( 'WikiForum', $thread->wft_thread_name ),
				$thread->wft_thread_name
			);

			$output .= WikiForumGui::getMainBody(
				'<p class="mw-wikiforum-thread">' . $icon . $threadLink .
				'<p class="mw-wikiforum-descr" style="border-top: 0;">' .
				wfMessage(
					'wikiforum-posted',
					$wgLang->timeanddate( $thread->wft_posted_timestamp ),
					WikiForumClass::getUserLink( $thread->user_name ),
					$thread->user_name,
					$wgLang->date( $thread->wft_posted_timestamp ),
					$wgLang->time( $thread->wft_posted_timestamp )
				)->text() . '<br />' .
				wfMessage( 'wikiforum-forum', $categoryLink, $forumLink )->text() .
				'</p></p>',
				$thread->wft_reply_count,
				$thread->wft_view_count,
				$lastpost,
				false,
				false
			);
		}
		$output .= WikiForumGui::getMainPageFooter();

		return $output;
	}

	/**
	 * Callback for the <WikiForumThread> hook.
	 * Takes the following arguments: id (ID number of the thread, used in SQL
	 * query), replies (whether to display replies)
	 */
	public static function renderWikiForumThread( $input, $args, $parser, $frame ) {
		global $wgOut, $wgLang;

		if ( isset( $args['id'] ) && $args['id'] > 0 ) {
			$dbr = wfGetDB( DB_SLAVE );
			$sqlThreads = $dbr->select(
				array( 'wikiforum_forums', 'wikiforum_category', 'wikiforum_threads', 'user' ),
				array(
					'wft_thread', 'wft_thread_name', 'wft_text', 'wff_forum',
					'wff_forum_name', 'wfc_category', 'wfc_category_name',
					'user_name', 'user_id', 'wft_edit_timestamp', 'wft_edit_user',
					'wft_edit_user_text', 'wft_posted_timestamp', 'wft_user',
					'wft_closed', 'wft_closed_user'
				),
				array(
					'wff_deleted' => 0,
					'wfc_deleted' => 0,
					'wft_deleted' => 0,
					'wff_category = wfc_category',
					'wff_forum = wft_forum',
					'wft_thread' => intval( $args['id'] )
				),
				__METHOD__,
				array(),
				array( 'user' => array( 'LEFT JOIN', 'user_id = wft_user' ) )
			);
			$overview = $dbr->fetchObject( $sqlThreads );

			if ( $overview ) {
				$posted = wfMessage(
					'wikiforum-posted',
					$wgLang->timeanddate( $overview->wft_posted_timestamp ),
					WikiForumClass::getUserLink( $overview->user_name ),
					$overview->user_name,
					$wgLang->date( $overview->wft_posted_timestamp ),
					$wgLang->time( $overview->wft_posted_timestamp )
				)->text();
				if ( $overview->wft_edit_timestamp > 0 ) {
					$posted .= '<br /><i>' .
						wfMessage(
							'wikiforum-edited',
							$wgLang->timeanddate( $overview->wft_edit_timestamp ),
							WikiForumClass::getUserLinkById( $overview->wft_edit_user ),
							$overview->wft_edit_user_text,
							$wgLang->date( $overview->wft_edit_timestamp ),
							$wgLang->time( $overview->wft_edit_timestamp )
						)->text() . '</i>';
				}

				$output = WikiForumGui::getHeaderRow(
					$overview->wfc_category,
					$overview->wfc_category_name,
					$overview->wff_forum,
					$overview->wff_forum_name,
					false
				);

				$output .= WikiForumGui::getThreadHeader(
					Linker::link(
						SpecialPage::getTitleFor( 'WikiForum', $overview->wft_thread_name ),
						$overview->wft_thread_name
					),
					$parser->recursiveTagParse( $overview->wft_text, $frame ),
					$posted,
					'',
					$overview->wft_thread,
					$overview->user_id
				);

				if ( isset( $args['replies'] ) && $args['replies'] ) {
					$replies = $dbr->select(
						array( 'wikiforum_replies', 'user' ),
						array( '*', 'user_name' ),
						array( 'wfr_deleted' => 0, 'wfr_thread' => $overview->pkThread ),
						__METHOD__,
						array( 'ORDER BY' => 'wfr_posted_timestamp ASC' ),
						array( 'user' => array( 'LEFT JOIN', 'user_id = wfr_user' ) )
					);

					foreach ( $replies as $reply ) {
						$posted = wfMessage(
							'wikiforum-posted',
							$wgLang->timeanddate( $reply->wfr_posted_timestamp ),
							WikiForumClass::getUserLink( $reply->user_name ),
							$reply->user_name,
							$wgLang->date( $reply->wfr_posted_timestamp ),
							$wgLang->time( $reply->wfr_posted_timestamp )
						)->text();
						if ( $reply->wfr_edit > 0 ) {
							$posted .= '<br /><i>' .
								wfMessage(
									'wikiforum-edited',
									$wgLang->timeanddate( $reply->wfr_edit ),
									WikiForumClass::getUserLinkById( $reply->wfr_edit_user ),
									$reply->wfr_edit_user_text,
									$wgLang->date( $reply->wfr_edit ),
									$wgLang->time( $reply->wfr_edit )
								)->text() . '</i>';
						}
						$output .= WikiForumGui::getReply(
							$wgOut->parse( WikiForum::deleteTags( $reply->wfr_reply_text ) ),
							$posted,
							'',
							$reply->wfr_reply_id
						);
					}
				}

				$output .= WikiForumGui::getThreadFooter();
				return $output;
			}
		} else {
			return '';
		}
	}

	/**
	 * Add the CSS file to the output, but only once.
	 *
	 * @param $out Object: OutputPage instance
	 * @param $sk Object: Skin (or descendant class) instance
	 */
	public static function addStyles( &$out, &$sk ) {
		static $cssDone = false;
		if ( !$cssDone ) {
			$out->addModuleStyles( 'ext.wikiForum' );
			$cssDone = true;
		}
		return true;
	}

	/**
	 * Adds the four new tables to the database when the user runs
	 * maintenance/update.php.
	 *
	 * @param $updater DatabaseUpdater
	 * @return Boolean: true
	 */
	public static function addTables( $updater = null ) {
		$dir = dirname( __FILE__ );
		$file = "$dir/wikiforum.sql";

		$updater->addExtensionUpdate( array( 'addTable', 'wikiforum_category', $file, true ) );
		$updater->addExtensionUpdate( array( 'addTable', 'wikiforum_forums', $file, true ) );
		$updater->addExtensionUpdate( array( 'addTable', 'wikiforum_threads', $file, true ) );
		$updater->addExtensionUpdate( array( 'addTable', 'wikiforum_replies', $file, true ) );

		// Add new *_user_text and *_user_ip columns introduced in 1.3.0-SW
		// Yes, I realize that the code inside the if() loop is pretty insane,
		// so if you have better suggestions on how to apply these patches, go
		// ahead and edit the code
		if ( !$updater->getDB()->fieldExists( 'wikiforum_category', 'wfc_added_user_text' ) ) {
			// wikiforum_category
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_category', 'wfc_added_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_category', 'wfc_added_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_category', 'wfc_edited_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_category', 'wfc_edited_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_category', 'wfc_deleted_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_category', 'wfc_deleted_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			// wikiforum_forums
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_forums', 'wff_last_post_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_forums', 'wff_last_post_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_forums', 'wff_added_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_forums', 'wff_added_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_forums', 'wff_edited_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_forums', 'wff_edited_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_forums', 'wff_deleted_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_forums', 'wff_deleted_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			// wikiforum_threads
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_threads', 'wft_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_threads', 'wft_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_threads', 'wft_deleted_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_threads', 'wft_deleted_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_threads', 'wft_edit_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_threads', 'wft_edit_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_threads', 'wft_closed_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_threads', 'wft_closed_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_threads', 'wft_last_post_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_threads', 'wft_last_post_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			// wikiforum_replies
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_replies', 'wfr_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_replies', 'wfr_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_replies', 'wfr_deleted_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_replies', 'wfr_deleted_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_replies', 'wfr_edit_user_text',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
			$updater->addExtensionUpdate( array(
				'addField', 'wikiforum_replies', 'wfr_edit_user_ip',
				dirname( __FILE__ ) . '/sql/1.3.0-SW-new-fields.sql', true
			) );
		}

		return true;
	}
}
