<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2011 das Medienkombinat GmbH
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

require_once(t3lib_extMgm::extPath('rn_base') . 'class.tx_rnbase.php');
tx_rnbase::load('tx_mksearch_util_ServiceRegistry');
tx_rnbase::load('tx_rnbase_util_Spyc');
tx_rnbase::load('tx_mksearch_tests_Util');

/**
 * Base test class for tests hitting Solr
 * @author Hannes Bochmann
 */
class tx_mksearch_tests_SolrTestcase extends tx_phpunit_testcase {

	/**
	 * @var unknown_type
	 */
	private $solr;
	
	/**
	 * Can be a TYPO3 path like EXT:mksearch/tests.....
	 * @var string
	 */
	protected $instanceDir = '';
	
	/**
	 * Can be a TYPO3 path like EXT:mksearch/tests.....
	 * @var string
	 */
	protected $configFile = '';
	
	/**
	 * Can be a TYPO3 path like EXT:mksearch/tests.....
	 * @var string
	 */
	protected $schemaFile = '';
	
	/**
	 * @var string
	 */
	private $coreName = '';
	
	/**
	 * @var tx_mksearch_service_engine_Solr
	 */
	private $solrEngine;
	
	/**
	 * @var unknown_type
	 */
	private $defaultIndexModel;
	
	/**
	 * (non-PHPdoc)
	 * @see PHPUnit_Framework_TestCase::setUp()
	 */
	public function setUp() {
		$this->initAbsolutePathsForConfigs();
		$this->createCore();
	}
	
	/**
	 * @return void
	 */
	protected function initAbsolutePathsForConfigs() {
		$this->instanceDir = t3lib_div::getFileAbsFileName($this->instanceDir);
		$this->configFile = t3lib_div::getFileAbsFileName($this->configFile);
		$this->schemaFile = t3lib_div::getFileAbsFileName($this->schemaFile);
	}
	
	/**
	 * @return void
	 */
	protected function createCore() {
		if(!$this->isSolrOkay()) 
			$this->fail($this->getSolrNotRespondingMessage());
			
		$this->createInstanceDir($this->instanceDir);
		
		$solr = $this->getSolr();
		$httpTransport = $solr->getHttpTransport();
		$url = $this->getAdminCoresPath() . '?action=CREATE&name=' . $this->getCoreName() . 
			'&instanceDir=' . $this->instanceDir . '&config=' . $this->configFile . '&schema=' . 
			$this->schemaFile;
		$httpResponse = $httpTransport->performGetRequest($url);
		
		if($httpResponse->getStatusCode() != 200){
			$this->fail('Der Core (' . $this->getCoreName() . ') konnte nicht erstellt werden. URL: ' . $url . '. Bitte in die Solr Konsolte schauen bzgl. der Fehler!');
		}
		
		$this->setSolrCredentialsForNewCore();
	}
	
	private function setSolrCredentialsForNewCore() {
		$newCredentialsString = preg_replace(
			'/(.*),(.*),(\/.*\/).*/', 
			'$1,$2,$3'.$this->getCoreName(), 
			$this->getDefaultIndexModel()->record['name']
		);

		$newCredentials = $this->getSolrEngine()->getCredentialsFromString($newCredentialsString);
		$this->getSolrEngine()->setConnection(
			$newCredentials['host'], $newCredentials['port'], $newCredentials['path'], false
		);
		$this->solr = null;
	}
	
	/**
	 * Enter description here ...
	 */
	private function getDefaultIndexModel() {
		if(!$this->defaultIndexModel){
			$this->defaultIndexModel = 
				tx_mksearch_util_ServiceRegistry::getIntIndexService()->getRandomSolrIndex();
		}
		
		return $this->defaultIndexModel;
	}
	
	
	/**
	 * @return string
	 */
	private function getSolrNotRespondingMessage() {
		return 'Solr ist nicht erreichbar auf: ' . 
				'Host: ' . $this->getSolr()->getHost() . ', Port: ' . 
				$this->getSolr()->getPort() . ', Path: ' . $this->getSolr()->getPath();
	}
	
	/**
	 * 
	 * @return Apache_Solr_Service
	 */
	protected function getSolr() {
		if($this->solr) 
			return $this->solr;
			
		try {
			$this->solr = $this->getSolrEngine()->getSolr();
		} catch (Exception $e) {
			$this->markTestSkipped($this->getSolrNotRespondingMessage());
		}
		
		return $this->solr;
	}
	
	/**
	 * @return string
	 */
	protected function getCoreName() {
		if(!$this->coreName)
			//muss mit einem buchstaben beginnen da der name 
			//in setSolrCredentialsForNewCore in preg_replace
			//nicht korrekt ersetzt wird
			$this->coreName = 'a'. md5(microtime());
			
		return $this->coreName;
	}
	
	/**
	 * per default den ersten konfiguriereten index.
	 * sollte so passen.
	 * @return tx_mksearch_service_engine_Solr
	 */
	protected function getSolrEngine() {
		if(!$this->solrEngine){
			$defaultIndexModel = $this->getDefaultIndexModel();
			$this->solrEngine = tx_mksearch_util_ServiceRegistry::getSearchEngine(
				$defaultIndexModel
			);
		}
		return $this->solrEngine;
	}
	
	/**
	 * @param string $path
	 * @return void
	 */
	protected function createInstanceDir($path) {
		t3lib_div::mkdir($path);
		t3lib_div::mkdir($path . '/conf');
	}
	
	/**
	 * @return string 
	 */
	protected function getAdminCoresPath() {
		return $this->getBaseUrl() . '/' . 'admin/cores';
	}
	
	/**
	 * @return string
	 */
	private function getBaseUrl() {
		$solr = $this->getSolr();
		$baseSolrPath = explode('/', $solr->getPath());
		return 'http://' . $solr->getHost() . ':' . $solr->getPort() . '/' . $baseSolrPath[1];
	}
	
	/**
	 * (non-PHPdoc)
	 * @see PHPUnit_Framework_TestCase::tearDown()
	 */
	public function tearDown(){
		$this->unloadCore();
		t3lib_div::rmdir($this->instanceDir,true);
	}
	
	/**
	 * @return void
	 */
	protected function unloadCore() {
		if(!$this->isSolrOkay()) 
			$this->fail($this->getSolrNotRespondingMessage());
			
		$url = $this->getAdminCoresPath() . '?action=UNLOAD&core=' . $this->getCoreName() . '&deleteIndex=true';
		$httpResponse =  $this->getSolr()->getHttpTransport()->performGetRequest($url);
		
		if($httpResponse->getStatusCode() != 200){
			$this->fail('Der Core (' . $this->getCoreName() . ') konnte nicht gelöscht werden. URL: ' . $url . '. Bitte in die Solr Konsolte schauen bzgl. der Fehler!');
		}
	}
	
	/**
	 * 
	 * @param string $yamlPath
	 */
	protected function indexDocsFromYaml($yamlPath) {
		if(!$this->isSolrOkay()) 
			$this->fail($this->getSolrNotRespondingMessage());

		// Erstmal komplett leer räumen
		$this->getSolr()->deleteByQuery('*:*');

		tx_rnbase::load('tx_rnbase_util_Spyc');
		$data = tx_rnbase_util_Spyc::YAMLLoad($yamlPath);
		
		foreach($data['docs'] As $docArr) {
			$extKey = $docArr['extKey']; unset($docArr['extKey']);
			$cType = $docArr['contentType']; unset($docArr['contentType']);
			$uid = $docArr['uid']; unset($docArr['uid']);
			$indexDoc = $this->createDoc($extKey, $cType);
			$indexDoc->setUid($uid);

			foreach($docArr As $field => $value) {
				$indexDoc->addField($field, $value);
			}
			$this->getSolrEngine()->indexUpdate($indexDoc);
		}
		
		$this->getSolrEngine()->commitIndex();
	}
	
	/**
	 * @param unknown_type $extKey
	 * @param unknown_type $cntType
	 * @return tx_mksearch_interface_IndexerDocument
	 */
	private function createDoc($extKey, $cntType) {
		$indexDoc = $this->getSolrEngine()->makeIndexDocInstance($extKey, $cntType);
		return $indexDoc;
	}
	
	/**
	 * @return boolean
	 */
	protected function isSolrOkay() {
		try {
			$ret = $this->getSolr()->ping() !== false;
		}
		catch(Exception $e) {
			$ret = false;
		}
		return $ret;
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/tests/class.tx_mksearch_tests_SolrTestcase.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/tests/class.tx_mksearch_tests_SolrTestcase.php']);
}