<?php

	require_once(TOOLKIT . '/class.datasource.php');
	require_once(EXTENSIONS . '/search_index/lib/class.search_index.php');
	require_once(EXTENSIONS . '/search_index/lib/class.entry_xml_datasource.php');
	require_once(EXTENSIONS . '/search_index/lib/class.reindex_datasource.php');
	
	class Extension_Search_Index extends Extension {
		
		/**
		* Extension meta data
		*/
		public function about() {
			return array(
				'name'			=> 'Search Index',
				'version'		=> '0.5',
				'release-date'	=> '2010-11-09',
				'author'		=> array(
					'name'			=> 'Nick Dunn'
				),
				'description' => 'Index text content of entries for efficient fulltext search.'
			);
		}

		/**
		* Set up configuration defaults and database tables
		*/		
		public function install(){
			
			// number of entries per page when rebuilding index
			Symphony::Configuration()->set('re-index-per-page', 20, 'search_index');
			// refresh frequency when rebuilding index
			Symphony::Configuration()->set('re-index-refresh-rate', 0.5, 'search_index');
			
			// append wildcard * to the end of search phrases (reduces performance, increases matches)
			Symphony::Configuration()->set('append-wildcard', 'no', 'search_index');
			
			// append + to the start of search phrases (makes all words required)
			Symphony::Configuration()->set('append-all-words-required', 'yes', 'search_index');
			
			// default sections if none specifed in URL
			Symphony::Configuration()->set('default-sections', '', 'search_index');
			
			// default sections if none specifed in URL
			Symphony::Configuration()->set('excerpt-length', 250, 'search_index');
			
			// names of GET parameters used for custom search DS
			Symphony::Configuration()->set('get-param-prefix', '', 'search_index');
			Symphony::Configuration()->set('get-param-keywords', 'keywords', 'search_index');
			Symphony::Configuration()->set('get-param-per-page', 'per-page', 'search_index');
			Symphony::Configuration()->set('get-param-sort', 'sort', 'search_index');
			Symphony::Configuration()->set('get-param-direction', 'direction', 'search_index');
			Symphony::Configuration()->set('get-param-sections', 'sections', 'search_index');
			Symphony::Configuration()->set('get-param-page', 'page', 'search_index');
			
			Administration::instance()->saveConfig();
			
			try {
				
				Symphony::Database()->query(
				  "CREATE TABLE IF NOT EXISTS `tbl_fields_search_index` (
					  `id` int(11) unsigned NOT NULL auto_increment,
					  `field_id` int(11) unsigned NOT NULL,
				  PRIMARY KEY  (`id`),
				  KEY `field_id` (`field_id`))");
				
				Symphony::Database()->query(
					"CREATE TABLE `tbl_search_index` (
					  `id` int(11) NOT NULL auto_increment,
					  `entry_id` int(11) NOT NULL,
					  `section_id` int(11) NOT NULL,
					  `data` text,
					  PRIMARY KEY (`id`),
					  KEY `entry_id` (`entry_id`),
					  FULLTEXT KEY `data` (`data`)
					) ENGINE=MyISAM DEFAULT CHARSET=utf8"
				);
				
				Symphony::Database()->query(
					"CREATE TABLE `tbl_search_index_logs` (
					  `id` int(11) NOT NULL auto_increment,
					  `date` datetime NOT NULL,
					  `keywords` varchar(255) default NULL,
					  `sections` varchar(255) default NULL,
					  `page` int(11) NOT NULL,
					  `results` int(11) default NULL,
					  `session_id` varchar(255) default NULL,
					  PRIMARY KEY  (`id`),
					  FULLTEXT KEY `keywords` (`keywords`)
					) ENGINE=MyISAM DEFAULT CHARSET=utf8;"
				);
				
			}
			catch (Exception $e){
				return false;
			}
			
			// Support for the multilanguage extension by Giel Berkers:
			// http://github.com/kanduvisla/multilanguage
			//
			// Run the update()-function which does the magic:
			$this->update();
			// End Support
			
			return true;
		}
		
		/**
		 * Update function
		 */
		public function update()
		{
			// Support for the multilanguage extension by Giel Berkers:
			// http://github.com/kanduvisla/multilanguage
			//
			// See if the multilingual extension is installed:
			require_once(TOOLKIT . '/class.extensionmanager.php');
			$extensionManager = new ExtensionManager($this);
			$status = $extensionManager->fetchStatus('multilanguage');
			if($status == EXTENSION_ENABLED)
			{
				// Append some extra rows to the search-index table:
				$languages = explode(',', file_get_contents(MANIFEST.'/multilanguage-languages'));
				// Check which fields exist:
				$columns = Symphony::Database()->fetch("SHOW COLUMNS FROM `tbl_search_index`");
				$fields  = array();
				foreach($columns as $column)
				{
					$fields[] = $column['Field'];
				}
				foreach($languages as $language)
				{
					$field = 'data_'.$language;
					if(!in_array($field, $fields))
					{
						Administration::instance()->Database->query(
							"ALTER TABLE `tbl_search_index` ADD `".$field."` TEXT, ADD FULLTEXT (`".$field."`)"
						);
					}
				}
			}
			// End Support
		}
		
		/**
		* Cleanup after yourself, remove configuration and database tables
		*/
		public function uninstall(){
			
			Symphony::Configuration()->remove('search_index');			
			Administration::instance()->saveConfig();
			
			try{
				Symphony::Database()->query("DROP TABLE `tbl_search_index`");
				Symphony::Database()->query("DROP TABLE `tbl_fields_search_index`");
				Symphony::Database()->query("DROP TABLE `tbl_search_index_logs`");
			}
			catch(Exception $e){
				return false;
			}
			return true;
		}
		
		/**
		* Callback functions for backend delegates
		*/		
		public function getSubscribedDelegates() {
			return array(
				array(
					'page'		=> '/publish/new/',
					'delegate'	=> 'EntryPostCreate',
					'callback'	=> 'indexEntry'
				),				
				array(
					'page'		=> '/publish/edit/',
					'delegate'	=> 'EntryPostEdit',
					'callback'	=> 'indexEntry'
				),
				array(
					'page'		=> '/publish/',
					'delegate'	=> 'Delete',
					'callback'	=> 'deleteEntryIndex'
				),
				array(
					'page' => '/frontend/',
					'delegate' => 'EventPostSaveFilter',
					'callback' => 'indexEntry'
				),
			);
		}
		
		/**
		* Append navigation to Blueprints menu
		*/
		public function fetchNavigation() {
			return array(
				array(
					'location'	=> 'Blueprints',
					'name'		=> 'Search Indexes',
					'link'		=> '/indexes/'
				),
			);
		}
		
		/**
		* Index this entry for search
		*
		* @param object $context
		*/
		public function indexEntry($context) {			
			SearchIndex::indexEntry($context['entry']->get('id'), $context['entry']->get('section_id'));
		}
		
		/**
		* Delete this entry's search index
		*
		* @param object $context
		*/
		public function deleteEntryIndex($context) {
			if (is_array($context['entry_id'])) {
				foreach($context['entry_id'] as $entry_id) {
					SearchIndex::deleteIndexByEntry($entry_id);
				}
			} else {
				SearchIndex::deleteIndexByEntry($context['entry_id']);
			}
		}
		
	}
	