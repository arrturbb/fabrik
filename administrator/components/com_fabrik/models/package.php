<?php
/*
 * Package Model
 *
 * @package Joomla.Administrator
 * @subpackage Fabrik
 * @since		1.6
 * @copyright Copyright (C) 2005 Rob Clayburn. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 */

// No direct access.
defined('_JEXEC') or die;

require_once('fabmodeladmin.php');

class FabrikModelPackage extends FabModelAdmin
{
	/**
	 * @var		string	The prefix to use with controller messages.
	 * @since	1.6
	 */

	protected $text_prefix = 'COM_FABRIK_PACKAGE';

	protected $tables = array('#__fabrik_connections',
		'#__{package}_cron',
		'#__{package}_elements',
		'#__{package}_formgroup',
		'#__{package}_forms',
		'#__{package}_form_sessions',
		'#__{package}_groups',
		'#__{package}_joins',
		'#__{package}_jsactions',
		'#__{package}_lists',
		'#__{package}_log',
		'#__{package}_packages',
		'#__{package}_validations',
		'#__{package}_visualizations');

	/**
	 * Returns a reference to the a Table object, always creating it.
	 *
	 * @param	type	The table type to instantiate
	 * @param	string	A prefix for the table class name. Optional.
	 * @param	array	Configuration array for model. Optional.
	 * @return	JTable	A database object
	 * @since	1.6
	 */

	public function getTable($type = 'Package', $prefix = 'FabrikTable', $config = array())
	{
		$config['dbo'] = FabriKWorker::getDbo(true);
		return FabTable::getInstance($type, $prefix, $config);
	}

	/**
	 * Method to get the record form.
	 *
	 * @param	array	$data		Data for the form.
	 * @param	boolean	$loadData	True if the form is to load its own data (default case), false if not.
	 * @return	mixed	A JForm object on success, false on failure
	 * @since	1.6
	 */

	public function getForm($data = array(), $loadData = true)
	{
		// Get the form.
		$form = $this->loadForm('com_fabrik.package', 'package', array('control' => 'jform', 'load_data' => $loadData));
		if (empty($form)) {
			return false;
		}
		return $form;
	}

	/**
	 * Method to get the data that should be injected in the form.
	 *
	 * @return	mixed	The data for the form.
	 * @since	1.6
	 */

	protected function loadFormData()
	{
		// Check the session for previously entered form data.
		$data = JFactory::getApplication()->getUserState('com_fabrik.edit.package.data', array());

		if (empty($data)) {
			$data = $this->getItem();
		}

		return $data;
	}

	/**
	 * save the pacakge
	 * @param array data
	 * @return bol
	 */

	public function save($data)
	{
		$canvas = $data['params']['canvas'];
		$canvas = json_decode($canvas);
		$o = new stdClass();
		if (is_null($canvas)) {
			JError::raiseError(E_ERROR, 'malformed json package object');
		}
		$o->canvas = $canvas;
		$data['params'] = json_encode($o);
		$return = parent::save($data);
		$data['id'] = $this->getState($this->getName().'.id');
		$packageId = $this->getState($this->getName().'.id');
		$blocks = is_object($o->canvas) ? $o->canvas->blocks : array();
		foreach ($blocks as $fullkey => $ids) {
			$key = FabrikString::rtrimword($fullkey, 's');
			$tbl = JString::ucfirst($key);
			foreach ($ids as $id) {
				$item = $this->getTable($tbl);
				$item->load($id);
				if ($key == 'list') {
					//also assign the form to the package
					$form = $this->getTable('Form');
					$form->load($item->form_id);

					if (!in_array($form->id, $blocks->form)) {
						$o->canvas->blocks->form[] = $item->id;
					}
				}
			}
		}
		// resave the data to update blocks
		$data['params'] = json_encode($o);
		$return = parent::save($data);
		return $return;
	}

	public function export($ids = array())
	{
		//JModel::addIncludePath(COM_FABRIK_FRONTEND.DS.'models');
		jimport('joomla.filesystem.archive');
		foreach ($ids as $id) {
			$row = $this->getTable();

			$row->load($id);
			$this->outputPath = JPATH_ROOT.DS.'tmp'.DS.$this->getComponentName($row);
			$json = $row->params;
			$row->params = json_decode($row->params);
			$row->blocks = $row->params->canvas->blocks;
			$componentZipPath = $this->outputPath.DS.'packages'.DS.'com_'.$this->getComponentName($row). '.zip';
			$pkgName = 'pkg_'.$this->getComponentName($row). '.zip';
			$packageZipPath = $this->outputPath.DS.$pkgName;
			if (JFile::exists($componentZipPath)) {
				JFile::delete($componentZipPath);
			}
			$filenames = array();
			$row2 = clone($row);
			$row2->params = $json;
			$filenames[] = $this->makeXML($row);
			$filenames[] = $this->makeInstallSQL($row);
			$filenames[] = $this->makeUnistallSQL($row);
			$filenames[] = $this->makeMetaSQL($row2);

			$this->copySkeleton($row, $filenames);
			$archive = JArchive::getAdapter('zip');

			$files = array();
			$this->addFiles($filenames, $files, $this->outputPath.DS);
			$ok = $archive->create($componentZipPath, $files);
			if (!$ok){
				JError::raiseError(500, 'Unable to create zip in ' . $componentZipPath);
			}
			//copy that to root

			$ok = JFile::copy($componentZipPath, $this->outputPath.DS.'com_'.$this->getComponentName($row). '.zip');

			// now lets create the Joomla install package
			$plugins = $this->findPlugins($row);
			$this->zipPlugins($row, $plugins);
			$filenames = FArrayHelper::extract($plugins, 'fullfile');

			$filenames[] = $componentZipPath;
			$filenames[] = $this->makePackageXML($row, $plugins);


			$files = array();
			$this->addFiles($filenames, $files, $this->outputPath.DS);

			$ok = $archive->create($packageZipPath, $files);
			if (!$ok){
				JError::raiseError(500, 'Unable to create zip in ' . $componentZipPath);
			}
			//$this->triggerDownload($pkgName, $packageZipPath);
			//$this->cleanUp($pkgName);
		}
	}

	protected function triggerDownload($filename, $filepath)
	{
		$document = JFactory::getDocument();
		$size = filesize($filepath);

		$document->setMimeEncoding('application/zip');
		$str = JFile::read($filepath);
		// Set the response to indicate a file download
		JResponse::setHeader('Content-Type', 'application/force-download');
		//JResponse::setHeader('Content-Type', 'application/zip');
		JResponse::setHeader('Content-Length', $size);
		JResponse::setHeader('Content-Disposition', 'attachment; filename="'.basename($filepath).'"');
		JResponse::setHeader('Content-Transfer-Encoding', 'binary');

		/*
		 * $size = filesize($filepath);
		header('Content-Type: application/force-download');
		header('Content-Disposition: attachment; filename="'.basename($getfile).'"');
		header('Content-Transfer-Encoding: binary');
		 */
		JResponse::setBody($str);
		echo JResponse::toString(false);
	}

	/**
	 * remove unwanted tmp files
	 * @param string $pkgName the package zip name (the file we dont want to delete)
	 */

	protected function cleanUp($pkgName)
	{
		$exclude = array(($pkgName));
		$files = JFolder::files($this->outputPath, '.', false, true, $exclude);
		foreach ($files as $file) {
			JFile::delete($file);
		}
		$folders = JFolder::folders($this->outputPath, '.', false, true);
		foreach ($folders as $folder) {
			JFolder::delete($folder);
		}
	}

	/**
	 * create the component install.php file which will populate the components
	 * forms/lists/elements etc
	 * @param object $row
	 */

	protected function makeMetaSQL($row)
	{
		$return = array();
		$return[] = "<?php ";
		$row->id = null;
		$row->external_ref = 1;
		$rows = array($row);
		$return[] = $this->rowsToInsert('#__fabrik_packages', $rows, $return);
		$return[] = "\$package_id = \$db->insertid();";
		$lookups = $this->getInstallItems($row);
		$listModel = JModel::getInstance('list', 'FabrikFEModel');
		$lists = $lookups->list;
		$db = FabrikWorker::getDbo(true);
		$query = $db->getQuery(true);

		foreach ($lookups->visualization as $vid) {
			$query->select('*')->from('#__{package}_visualizations')->where('id = ' . $vid);
			$db->setQuery($query);
			$viz = $db->loadObjectList();
			$this->rowsToInsert('#__'.$row->component_name.'_visualizations', $viz, $return);
		}

		foreach ($lists as $listid) {
			$query->clear();
			$query->select('*')->from('#__{package}_lists')->where('id = ' . $listid);
			$db->setQuery($query);

			$list = $db->loadObjectList();
			$this->rowsToInsert('#__'.$row->component_name.'_lists', $list, $return);
			//form
			$query->clear();
			$query->select('*')->from('#__{package}_forms')->where('id = ' . $list[0]->form_id);
			$forms = $db->loadObjectList();
			$this->rowsToInsert('#__'.$row->component_name.'_forms', $forms, $return);
			//form groups
			$query->clear();
			$query->select('*')->from('#__{package}_formgroup')->where('form_id = ' . $list[0]->form_id);
			$formgroups = $db->loadObjectList();
			$this->rowsToInsert('#__'.$row->component_name.'_formgroup', $formgroups, $return);

			$groupids = array();
			foreach ($formgroups as $formgroup) {
				$groupids[] = $formgroup->group_id;
			}
			//groups
			$query->clear();
			$query->select('*')->from('#__{package}_groups')->where('id IN (' .implode(',', $groupids) .')');
			$groups = $db->loadObjectList();
			$this->rowsToInsert('#__'.$row->component_name.'_groups', $groups, $return);

			//elements
			$query->clear();
			$query->select('*')->from('#__{package}_elements')->where('group_id IN (' .implode(',', $groupids) .')');
			$elements = $db->loadObjectList();
			$elementids = array();
			foreach ($elements as $element) {
				$elementids[] = $element->id;
			}
			$this->rowsToInsert('#__'.$row->component_name.'_elements', $elements, $return);

			//joins
			$query->clear();
			$query->select('*')->from('#__{package}_joins')->where('list_id IN (' .implode(',', $lists) .') OR element_id IN (' . implode(',', $elementids) . ')');
			$joins = $db->loadObjectList();
			$this->rowsToInsert('#__'.$row->component_name.'_joins', $joins, $return);

			//js actions
			$query->clear();
			$query->select('*')->from('#__{package}_jsactions')->where('element_id IN (' .implode(',', $elementids) .')');
			$jsactions = $db->loadObjectList();
			$this->rowsToInsert('#__'.$row->component_name.'_jsactions', $jsactions, $return);

			//js actions
			$query->clear();
			$query->select('*')->from('#__{package}_validations')->where('element_id IN (' .implode(',', $elementids) .')');
			$validations = $db->loadObjectList();
			$this->rowsToInsert('#__'.$row->component_name.'_validations', $validations, $return);

		}
		//ok write the code to update components/componentname/componentname.php
		//have to do this in the installer as we don't know what package id the component will be installed as
		$xmlname = str_replace('com_', '', $row->component_name);
		$return[] = "\$path = JPATH_ROOT.DS.'components'.DS.'com_"."$row->component_name'.DS.'$xmlname.php';";
		$return[] = "\$buffer = JFile::read(\$path);";
		$return[] = "\$buffer = str_replace('{packageid}', \$package_id, \$buffer);";
		$return[] = "JFile::write(\$path, \$buffer);";
		$return[] = "?>";
		$return = implode("\n", $return);
		$path = $this->outputPath.DS.'installation'.DS.'install.'.$xmlname.'.php';
		JFile::write($path, $return);
		return $path;
	}

	protected function rowsToInsert($table, $rows, &$return)
	{
		$db = FabrikWorker::getDbo(true);
		foreach ($rows as $row) {
			$fmtsql = 'INSERT INTO '.$db->nameQuote($table).' (%s) VALUES (%s) ';
			$fields = array();
			$values = array();
			foreach (get_object_vars($row) as $k => $v) {
				if (is_array($v) or is_object($v) or $v === NULL) {
					continue;
				}
				/* dont think this is needed as we are inserting into a custom set of tables
				 ///clear the id value so that the rows be inserted rather than updating any row with the same id
				 if ($k == 'id') {
					$v = '';
					}
					//set $package_id var placeholder so that we can replace that with the installed package id
					if ($k == 'package_id') {
					$v = '$package_id';
					}*/
				if ($k[0] == '_') { // internal field
					continue;
				}
				$fields[] = $db->nameQuote($k);
				$values[] = $db->isQuoted($k) ? $db->Quote($v) : (int) $v;
			}
			$sql = sprintf($fmtsql, implode(",", $fields) ,  implode(",", $values)) . ';';
			$return[] = "\$db->setQuery(\"$sql\");";
			$return[] = "\$db->query();";
		}
	}

	/**
	 * recurisive function to add files and folders into the zip
	 * @param array list of file names to add $filenames
	 * @param array list of already added files files
	 */

	protected function addFiles($filenames, &$files, $root = '')
	{
		foreach ($filenames as $fpath) {
			$zippath = str_replace($root, '', $fpath);
			if (is_dir($fpath)) {
				$tmpFiles = JFolder::files($fpath, '.', true, true);
				$this->addFiles($tmpFiles, $files, $root);
			} else {
				$files[] = array('name'=> $zippath,
					'data'=>JFile::read($fpath));
			}
		}
		return $files;
	}

	protected function getComponentName($row)
	{
		return $row->component_name.'_' . $row->version;
	}

	/**
	 * get the lists, forms etc that have been assigned in the package admin edit screen
	 * @param object package $row
	 * @return array items
	 */

	protected function getInstallItems($row)
	{
		if (isset($this->items)) {
			return $this->items;
		}
		$this->items = $row->blocks;
		return $this->items;
	}

	/**
	 * create the SQL install file
	 * @param object package $row
	 */

	protected function makeInstallSQL($row)
	{
		$sql = '';
		$config = JFactory::getConfig();
		$db = FabrikWorker::getDbo(true);
		//create the sql for the cloned fabrik meta data tables
		foreach ($this->tables as $table) {
			$db->setQuery('SHOW CREATE TABLE ' . $table);
			$tbl = $db->loadRow();

			$tbl = str_replace('_fabrik_', '_'.$row->component_name.'_', $tbl[1]);
			$tbl = str_replace($config->get('dbprefix'), '#__', $tbl);
			$sql .= str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $tbl) . ";\n\n";

			$table = str_replace(array('_fabrik_', '{package}'),array('_'.$row->component_name.'_', $row->component_name), $table);
			$sql .= 'TRUNCATE TABLE ' . $table . ";\n\n";
		}
		foreach ($row->blocks as $block => $ids) {
			$key = fabrikString::rtrimword($block, 's');
		}

		// create the sql to build the db tables that store the data.
		$listModel = JModel::getInstance('list', 'FabrikFEModel');
		$formModel = JModel::getInstance('form', 'FabrikFEModel');

		$lookups = $this->getInstallItems($row);
		$lids = $lookups->list;
		JArrayHelper::toInteger($lids);
		foreach ($lids as $lid) {
			$listModel->setId($lid);
			$sql .= "\n\n".$listModel->getCreateTableSQL(true);
		}

		$plugins = array();
		foreach ($lookups->form as $fid) {
			$formModel->setId($fid);
			if (!in_array($fid, $lookups->list)) {
					$lookups->list[] = $fid;
				}
			//@FIXME get sql to create tables for dbjoin/cdd elements (need to do if not exists)
			$dbs = $formModel->getElementOptions(false, 'name', true, true, array());
		}
		$sql .= "\n\n";
		foreach ($lookups->visualization as $vid) {
			$vrow = FabTable::getInstance('Visualization', 'FabrikTable');
			$vrow->load($vid);
			//$modelpaths = JModel::addIncludePath(JPATH_SITE.DS.'plugins'.DS.'fabrik_visualization'.DS.$vrow->plugin.DS.'models');
			$visModel = JModel::getInstance($vrow->plugin, 'fabrikModel');
			$visModel->setId($vid);
			$visModel->setListIds();
			$listModels = $visModel->getlistModels();
			foreach ($listModels as $lmodel) {
				$sql .= $lmodel->getCreateTableSQL(true);
				//add the table ids to the $lookups->list
				echo "do we add " . $lmodel->getId() . "<br>";
				if (!in_array($lmodel->getId(), $lookups->list)) {
					$lookups->list[] = $lmodel->getId();
				}
			}
		}
		$path = $this->outputPath.DS.'admin'.DS.'installation'.DS.'queries.sql';
		JFile::write($path, $sql);
		return $path;
	}

	/**
	 * get a list of plugins that the package uses
	 * @param object package $row
	 * @return array plugins
	 */
	protected function findPlugins($row)
	{
		$listModel = JModel::getInstance('list', 'FabrikFEModel');
		$formModel = JModel::getInstance('form', 'FabrikFEModel');
		$lookups = $this->getInstallItems($row);
		$plugins = array();
		foreach ($lookups->form as $fid) {
			$formModel->setId($fid);
			$groups = $formModel->getGroupsHiarachy();
			foreach ($groups as $groupModel) {
				$elements = $groupModel->getMyElements();
				foreach ($elements as $element) {
					$item = $element->getElement();
					$id = 'element_' . $item->plugin;
					$o = new stdClass();
					$o->id = $id;
					$o->name = $item->plugin;
					$o->group = 'fabrik_element';
					$o->file = 'plg_fabrik_'.$id.'.zip';
					$plugins[$id] = $o;
				}
				//form plugins
				$fplugins = $formModel->getParams()->get('plugins');
				foreach ($fplugins as $fplugin) {
					$id = 'form_' . $fplugin;
					$o = new stdClass();
					$o->id = $id;
					$o->name = $fplugin;
					$o->group = 'fabrik_form';
					$o->file = 'plg_fabrik_'.$id.'.zip';
					$plugins[$id] = $o;
				}
			}
		}
		foreach ($lookups->list as $id) {
			$listModel->setId($id);
			$tplugins = $listModel->getParams()->get('plugins');
			foreach ($tplugins as $tplugin) {
				$id = 'list_' . $tplugin;
				$o = new stdClass();
				$o->id = $id;
				$o->name = $tplugin;
				$o->group = 'fabrik_list';
				$o->file = 'plg_fabrik_'.$id.'.zip';
				$plugins[$id] = $o;
			}
		}
		return $plugins;
	}

	/**
	 * Create the SQL unistall file
	 * @param object package $row
	 */

	protected function makeUnistallSQL($row)
	{
		$listModel = JModel::getInstance('list', 'FabrikFEModel');
		$lookups = $this->getInstallItems($row);
		$db = JFactory::getDbo();
		$sql = array();
		$tids = $lookups->list;
		JArrayHelper::toInteger($tids);
		foreach ($tids as $tid) {
			$listModel->setId($tid);
			$table = $listModel->getTable()->db_table_name;
			$sql[] = "DELETE FROM ". $db->nameQuote($table).";";
			$sql[] = "DROP TABLE ". $db->nameQuote($table).";";
		}

		//drop the meta tables as well (currently we don't have a method for
		//upgrading a package. So unistall should remove these meta tables
		foreach ($this->tables as $table) {
			//as we share the connection table we don't want to remove it on package unistall
			if ($table == '#__fabrik_connections') {
				continue;
			}
			$table = str_replace('{package}', $row->component_name, $table);
			$sql[] = "DROP TABLE ". $db->nameQuote($table).";";
		}

		$path = $this->outputPath.DS.'admin'.DS.'installation'.DS.'uninstall.sql';
		JFile::write($path, implode("\n", $sql));
		return $path;
	}

	/**
	 * copy the files from the skeleton component into the tmp folder
	 * ready to be zipped up
	 * @param object package $row
	 */

	protected function copySkeleton($row, &$filenames)
	{
		$name = str_replace('com_', '', $row->component_name);
		JFolder::create($this->outputPath.DS.'site');
		JFolder::create($this->outputPath.DS.'site'.DS.'views');
		JFolder::create($this->outputPath.DS.'admin');
		JFolder::create($this->outputPath.DS.'admin'.DS.'installation');

		$from = JPATH_ROOT.DS.'components'.DS.'com_fabrik_skeleton'.DS.'fabrik_skeleton.php';
		$to = $this->outputPath.DS.'site'.DS.$name.'.php';
		if (JFile::exists($to)) {
			JFile::delete($to);
		}
		JFile::copy($from, $to);
		$filenames[] = $to;

		//admin holding page
		$from = JPATH_ROOT.DS.'components'.DS.'com_fabrik_skeleton'.DS.'admin.php';
		$to = $this->outputPath.DS.'admin'.DS.$name.'.php';
		if (JFile::exists($to)) {
			JFile::delete($to);
		}
		JFile::copy($from, $to);
		$filenames[] = $to;


		$from = JPATH_ROOT.DS.'components'.DS.'com_fabrik_skeleton'.DS.'index.html';
		$to = $this->outputPath.DS.'admin'.DS.'installation'.DS.'index.html';
		JFile::copy($from, $to);
		$filenames[] = $to;

		$from = JPATH_ROOT.DS.'components'.DS.'com_fabrik_skeleton'.DS.'index.html';
		$to = $this->outputPath.DS.'site'.DS.'index.html';
		JFile::copy($from, $to);
		$filenames[] = $to;


		$from = JPATH_ROOT.DS.'components'.DS.'com_fabrik_skeleton'.DS.'index.html';
		$to = $this->outputPath.DS.'admin'.DS.'index.html';
		JFile::copy($from, $to);
		$filenames[] = $to;

		$from = JPATH_ROOT.DS.'components'.DS.'com_fabrik_skeleton'.DS.'images'.DS;
		$to = $this->outputPath.DS.'admin'.DS.'images';
		JFolder::copy($from, $to, '', true);
		$filenames[] = $to;

		$from = JPATH_ROOT.DS.'components'.DS.'com_fabrik_skeleton'.DS.'views'.DS;
		$to = $this->outputPath.DS.'site'.DS.'views';
		JFolder::copy($from, $to, '', true);
		$filenames[] = $to;

		/*//testing this tmp file
		$from = JPATH_ROOT.DS.'components'.DS.'com_fabrik_skeleton'.DS.'fabrik_skeleton.php';
		$to = $this->outputPath.DS.'admin'.DS.$name.'.php';
		JFile::copy($from, $to);
		$filenames[] = $to;*/

	}

	/**
	 *
	 * rather than just installing the component we want to create a package
	 * containing the component PLUS any Fabrik plugins that component might use
	 * @param unknown_type $row
	 */
	protected function makePackageXML($row, $plugins)
	{
		// @TODO add update url e.g:
		//<update>http://fabrikar.com/update/packages/free</update>

		$xmlname = 'pkg_'.str_replace('com_', '', $row->component_name);
		$str = '<?xml version="1.0" encoding="UTF-8" ?>
<install type="package" version="1.6">
	<name>'.$row->label.'</name>
	<packagename>'.$xmlname.'</packagename>
	<version>'.$row->version.'</version>
	<url>http://www.joomla.org</url>
	<packager>Rob Clayburn</packager>
	<packagerurl>http://www.fabrikar.com</packagerurl>
	<description>Created by Fabrik</description>

	<files folder="packages">
		<file type="component" id="'.$row->component_name.'">com_'.$this->getComponentName($row).'.zip</file>
';
		foreach ($plugins as $plugin) {
			$str .= '
		<file type="plugin"	id="'.$plugin->id.'"	group="'.$plugin->group.'">'.$plugin->file.'</file>';
		}
		$str .='
	</files>
</install>';
		JFile::write($this->outputPath.DS.$xmlname.'.xml', $str);
		return $this->outputPath.DS.$xmlname.'.xml';;
	}

	/**
	 * zip up the plugins used by the package
	 * @param object $row
	 * @param array $plugins
	 */

	protected function zipPlugins($row, &$plugins)
	{
		$archive = JArchive::getAdapter('zip');
		JFolder::create($this->outputPath.DS.'packages');
		foreach ($plugins as &$plugin) {

			$filenames = array(JPATH_ROOT.DS.'plugins'.DS.$plugin->group.DS.$plugin->name);
			$files = array();
			$root = JPATH_ROOT.DS.'plugins'.DS.$plugin->group.DS.$plugin->name.DS;
			$this->addFiles($filenames, $files, $root);
			$plugin->file = str_replace('{version}', $row->version, $plugin->file);
			$pluginZipPath = $this->outputPath.DS.'packages'.DS.$plugin->file;
			$ok = $archive->create($pluginZipPath, $files);
			$plugin->fullfile = $pluginZipPath;
			if (!$ok) {
				JError::raiseError(500, 'Unable to create zip in ' . $pluginZipPath);
			}
		}
	}

	/**
	 * create the component installation xml file
	 * @param object package $table
	 * @return string path to where tmp xml file is saved
	 */

	protected function makeXML($row)
	{
		$date = JFactory::getDate();

		$xmlname = str_replace('com_', '', $row->component_name);
		$str = '<?xml version="1.0" encoding="utf-8"?>
<extension
	xmlns="http://www.joomla.org"
	xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	xsi:schemaLocation="http://www.joomla.org extension.xsd "
	method="upgrade"
	client="site"
	version="1.6.0"
	type="component">

	<name>'.$row->component_name.'</name>
	<creationDate>'.$date->toMySQL().'</creationDate>
	<author>Fabrik</author>
	<copyright>Pollen 8 Design Ltd</copyright>
	<license>GNU/GPL</license>
	<authorEmail>rob@pollen-8.co.uk</authorEmail>
	<authorUrl>www.pollen-8.co.uk</authorUrl>
	<version>'.$row->version.'</version>
	<description>Created with Fabrik: THE Joomla Application Creation Component</description>
	<install>
		<sql>
			<file charset="utf8" driver="mysql">installation/queries.sql</file>
		</sql>
	</install>

	<uninstall>
		<sql>
			<file charset="utf8" driver="mysql">installation/uninstall.sql</file>
		</sql>
	</uninstall>

	<installfile>installation/install.'.$xmlname.'.php</installfile>

	<files folder="site">
		<folder>views</folder>
		<file>'.$xmlname.'.php</file>
		<file>index.html</file>
	</files>

	<administration>
		<menu img="../administrator/components/com_fabrik/images/logo.png">'.$row->label.'</menu>

		<files folder="admin">
			<folder>installation</folder>
			<folder>images</folder>
			<file>index.html</file>
			<file>'.$xmlname.'.php</file>
		</files>
	</administration>

</extension>';
		JFile::write($this->outputPath.DS.$xmlname.'.xml', $str);
		return $this->outputPath.DS.$xmlname.'.xml';;
	}
	
	public function getPackageListForm($data = array(), $loadData = true)
	{
		// Get the form.
		$form = $this->loadForm('com_fabrik.packagelist', 'packagelist', array('control' => 'jform', 'load_data' => $loadData));
		if (empty($form)) {
			return false;
		}
		return $form;
	}
}
