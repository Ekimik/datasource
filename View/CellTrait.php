<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\View;

use Cake\Core\App;
use Cake\Utility\Inflector;
use Cake\View\View;

/**
 * Provides cell() method for usage in Controller and View classes.
 *
 */
trait CellTrait {

/**
 * Renders the given cell.
 *
 * Example:
 *
 * {{{
 * // Taxonomy\View\Cell\TagCloudCell::smallList()
 * $cell = $this->cell('Taxonomy.TagCloud::smallList', ['limit' => 10]);
 *
 * // App\View\Cell\TagCloudCell::smallList()
 * $cell = $this->cell('TagCloud::smallList', ['limit' => 10]);
 * }}}
 *
 * The `display` action will be used by default when no action is provided:
 *
 * {{{
 * // Taxonomy\View\Cell\TagCloudCell::display()
 * $cell = $this->cell('Taxonomy.TagCloud');
 * }}}
 *
 * Cells are not rendered until they are echoed.
 *
 * @param string $cell You must indicate cell name, and optionally a cell action. e.g.: `TagCloud::smallList`
 * will invoke `View\Cell\TagCloudCell::smallList()`, `display` action will be invoked by default when none is provided.
 * @param array $data Additional arguments for cell method. e.g.:
 *    `cell('TagCloud::smallList', ['a1' => 'v1', 'a2' => 'v2'])` maps to `View\Cell\TagCloud::smallList(v1, v2)`
 * @param array $options Options for Cell's constructor
 * @return \Cake\View\Cell The cell instance
 * @throws \Cake\View\Exception\MissingCellException If Cell class was not found.
 * @throws \BadMethodCallException If Cell class does not specified cell action.
 */
	public function cell($cell, array $data = [], array $options = []) {
		$parts = explode('::', $cell);

		if (count($parts) === 2) {
			list($pluginAndCell, $action) = [$parts[0], $parts[1]];
		} else {
			list($pluginAndCell, $action) = [$parts[0], 'display'];
		}

		list($plugin, $cellName) = pluginSplit($pluginAndCell);
		$className = App::className($pluginAndCell, 'View/Cell', 'Cell');

		if (!$className) {
			throw new Exception\MissingCellException(array('className' => $pluginAndCell . 'Cell'));
		}

		$cell = $this->_createCell($className, $action, $plugin, $options);
		if (!empty($data)) {
			$data = array_values($data);
		}

		try {
			$reflect = new \ReflectionMethod($cell, $action);
			$reflect->invokeArgs($cell, $data);
			return $cell;
		} catch (\ReflectionException $e) {
			throw new \BadMethodCallException(sprintf(
				'Class %s does not have a "%s" method.',
				$className,
				$action
			));
		}
	}

/**
 * Create and configure the cell instance.
 *
 * @param string $className The cell classname.
 * @param string $action The action name.
 * @param string $plugin The plugin name.
 * @param array $options The constructor options for the cell.
 * @return Cake\View\Cell;
 */
	protected function _createCell($className, $action, $plugin, $options) {
		$instance = new $className($this->request, $this->response, $this->eventManager(), $options);
		$instance->template = Inflector::underscore($action);
		$instance->plugin = !empty($plugin) ? $plugin : null;
		$instance->theme = !empty($this->theme) ? $this->theme : null;
		if (!empty($this->helpers)) {
			$instance->helpers = $this->helpers;
		}
		if (isset($this->viewClass)) {
			$instance->viewClass = $this->viewClass;
		}
		if ($this instanceof View) {
			$instance->viewClass = get_class($this);
		}
		return $instance;
	}

}
