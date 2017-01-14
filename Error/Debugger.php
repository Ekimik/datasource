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
 * @since         1.2.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Error;

use Cake\Core\InstanceConfigTrait;
use Cake\Log\Log;
use Cake\Utility\Hash;
use Cake\Utility\Security;
use Cake\Utility\Text;
use Exception;
use InvalidArgumentException;
use ReflectionObject;
use ReflectionProperty;

/**
 * Provide custom logging and error handling.
 *
 * Debugger overrides PHP's default error handling to provide stack traces and enhanced logging
 *
 * @link http://book.cakephp.org/3.0/en/development/debugging.html#namespace-Cake\Error
 */
class Debugger
{
    use InstanceConfigTrait;

    /**
     * Default configuration
     *
     * @var array
     */
    protected $_defaultConfig = [
        'outputMask' => []
    ];

    /**
     * A list of errors generated by the application.
     *
     * @var array
     */
    public $errors = [];

    /**
     * The current output format.
     *
     * @var string
     */
    protected $_outputFormat = 'js';

    /**
     * Templates used when generating trace or error strings. Can be global or indexed by the format
     * value used in $_outputFormat.
     *
     * @var array
     */
    protected $_templates = [
        'log' => [
            'trace' => '{:reference} - {:path}, line {:line}',
            'error' => "{:error} ({:code}): {:description} in [{:file}, line {:line}]"
        ],
        'js' => [
            'error' => '',
            'info' => '',
            'trace' => '<pre class="stack-trace">{:trace}</pre>',
            'code' => '',
            'context' => '',
            'links' => [],
            'escapeContext' => true,
        ],
        'html' => [
            'trace' => '<pre class="cake-error trace"><b>Trace</b> <p>{:trace}</p></pre>',
            'context' => '<pre class="cake-error context"><b>Context</b> <p>{:context}</p></pre>',
            'escapeContext' => true,
        ],
        'txt' => [
            'error' => "{:error}: {:code} :: {:description} on line {:line} of {:path}\n{:info}",
            'code' => '',
            'info' => ''
        ],
        'base' => [
            'traceLine' => '{:reference} - {:path}, line {:line}',
            'trace' => "Trace:\n{:trace}\n",
            'context' => "Context:\n{:context}\n",
        ]
    ];

    /**
     * Holds current output data when outputFormat is false.
     *
     * @var array
     */
    protected $_data = [];

    /**
     * Constructor.
     *
     */
    public function __construct()
    {
        $docRef = ini_get('docref_root');

        if (empty($docRef) && function_exists('ini_set')) {
            ini_set('docref_root', 'http://php.net/');
        }
        if (!defined('E_RECOVERABLE_ERROR')) {
            define('E_RECOVERABLE_ERROR', 4096);
        }

        $e = '<pre class="cake-error">';
        $e .= '<a href="javascript:void(0);" onclick="document.getElementById(\'{:id}-trace\')';
        $e .= '.style.display = (document.getElementById(\'{:id}-trace\').style.display == ';
        $e .= '\'none\' ? \'\' : \'none\');"><b>{:error}</b> ({:code})</a>: {:description} ';
        $e .= '[<b>{:path}</b>, line <b>{:line}</b>]';

        $e .= '<div id="{:id}-trace" class="cake-stack-trace" style="display: none;">';
        $e .= '{:links}{:info}</div>';
        $e .= '</pre>';
        $this->_templates['js']['error'] = $e;

        $t = '<div id="{:id}-trace" class="cake-stack-trace" style="display: none;">';
        $t .= '{:context}{:code}{:trace}</div>';
        $this->_templates['js']['info'] = $t;

        $links = [];
        $link = '<a href="javascript:void(0);" onclick="document.getElementById(\'{:id}-code\')';
        $link .= '.style.display = (document.getElementById(\'{:id}-code\').style.display == ';
        $link .= '\'none\' ? \'\' : \'none\')">Code</a>';
        $links['code'] = $link;

        $link = '<a href="javascript:void(0);" onclick="document.getElementById(\'{:id}-context\')';
        $link .= '.style.display = (document.getElementById(\'{:id}-context\').style.display == ';
        $link .= '\'none\' ? \'\' : \'none\')">Context</a>';
        $links['context'] = $link;

        $this->_templates['js']['links'] = $links;

        $this->_templates['js']['context'] = '<pre id="{:id}-context" class="cake-context" ';
        $this->_templates['js']['context'] .= 'style="display: none;">{:context}</pre>';

        $this->_templates['js']['code'] = '<pre id="{:id}-code" class="cake-code-dump" ';
        $this->_templates['js']['code'] .= 'style="display: none;">{:code}</pre>';

        $e = '<pre class="cake-error"><b>{:error}</b> ({:code}) : {:description} ';
        $e .= '[<b>{:path}</b>, line <b>{:line}]</b></pre>';
        $this->_templates['html']['error'] = $e;

        $this->_templates['html']['context'] = '<pre class="cake-context"><b>Context</b> ';
        $this->_templates['html']['context'] .= '<p>{:context}</p></pre>';
    }

    /**
     * Returns a reference to the Debugger singleton object instance.
     *
     * @param string|null $class Class name.
     * @return object|\Cake\Error\Debugger
     */
    public static function getInstance($class = null)
    {
        static $instance = [];
        if (!empty($class)) {
            if (!$instance || strtolower($class) !== strtolower(get_class($instance[0]))) {
                $instance[0] = new $class();
            }
        }
        if (!$instance) {
            $instance[0] = new Debugger();
        }

        return $instance[0];
    }

    /**
     * Read or write configuration options for the Debugger instance.
     *
     * @param string|array|null $key The key to get/set, or a complete array of configs.
     * @param mixed|null $value The value to set.
     * @param bool $merge Whether to recursively merge or overwrite existing config, defaults to true.
     * @return mixed Config value being read, or the object itself on write operations.
     * @throws \Cake\Core\Exception\Exception When trying to set a key that is invalid.
     */
    public static function configInstance($key = null, $value = null, $merge = true)
    {
        if (is_array($key) || func_num_args() >= 2) {
            return static::getInstance()->config($key, $value, $merge);
        }

        return static::getInstance()->config($key);
    }

    /**
     * Reads the current output masking.
     *
     * @return array
     */
    public static function outputMask()
    {
        return static::configInstance('outputMask');
    }

    /**
     * Sets configurable masking of debugger output by property name and array key names.
     *
     * ### Example
     *
     * Debugger::setOutputMask(['password' => '[*************]');
     *
     * @param array $value An array where keys are replaced by their values in output.
     * @param bool $merge Whether to recursively merge or overwrite existing config, defaults to true.
     * @return void
     */
    public static function setOutputMask(array $value, $merge = true)
    {
        static::configInstance('outputMask', $value, $merge);
    }

    /**
     * Recursively formats and outputs the contents of the supplied variable.
     *
     * @param mixed $var The variable to dump.
     * @param int $depth The depth to output to. Defaults to 3.
     * @return void
     * @see \Cake\Error\Debugger::exportVar()
     * @link http://book.cakephp.org/3.0/en/development/debugging.html#outputting-values
     */
    public static function dump($var, $depth = 3)
    {
        pr(static::exportVar($var, $depth));
    }

    /**
     * Creates an entry in the log file. The log entry will contain a stack trace from where it was called.
     * as well as export the variable using exportVar. By default the log is written to the debug log.
     *
     * @param mixed $var Variable or content to log.
     * @param int|string $level Type of log to use. Defaults to 'debug'.
     * @param int $depth The depth to output to. Defaults to 3.
     * @return void
     */
    public static function log($var, $level = 'debug', $depth = 3)
    {
        $source = static::trace(['start' => 1]) . "\n";
        Log::write($level, "\n" . $source . static::exportVar($var, $depth));
    }

    /**
     * Outputs a stack trace based on the supplied options.
     *
     * ### Options
     *
     * - `depth` - The number of stack frames to return. Defaults to 999
     * - `format` - The format you want the return. Defaults to the currently selected format. If
     *    format is 'array' or 'points' the return will be an array.
     * - `args` - Should arguments for functions be shown?  If true, the arguments for each method call
     *   will be displayed.
     * - `start` - The stack frame to start generating a trace from. Defaults to 0
     *
     * @param array $options Format for outputting stack trace.
     * @return mixed Formatted stack trace.
     * @link http://book.cakephp.org/3.0/en/development/debugging.html#generating-stack-traces
     */
    public static function trace(array $options = [])
    {
        return Debugger::formatTrace(debug_backtrace(), $options);
    }

    /**
     * Formats a stack trace based on the supplied options.
     *
     * ### Options
     *
     * - `depth` - The number of stack frames to return. Defaults to 999
     * - `format` - The format you want the return. Defaults to the currently selected format. If
     *    format is 'array' or 'points' the return will be an array.
     * - `args` - Should arguments for functions be shown?  If true, the arguments for each method call
     *   will be displayed.
     * - `start` - The stack frame to start generating a trace from. Defaults to 0
     *
     * @param array|\Exception $backtrace Trace as array or an exception object.
     * @param array $options Format for outputting stack trace.
     * @return mixed Formatted stack trace.
     * @link http://book.cakephp.org/3.0/en/development/debugging.html#generating-stack-traces
     */
    public static function formatTrace($backtrace, $options = [])
    {
        if ($backtrace instanceof Exception) {
            $backtrace = $backtrace->getTrace();
        }
        $self = Debugger::getInstance();
        $defaults = [
            'depth' => 999,
            'format' => $self->_outputFormat,
            'args' => false,
            'start' => 0,
            'scope' => null,
            'exclude' => ['call_user_func_array', 'trigger_error']
        ];
        $options = Hash::merge($defaults, $options);

        $count = count($backtrace);
        $back = [];

        $_trace = [
            'line' => '??',
            'file' => '[internal]',
            'class' => null,
            'function' => '[main]'
        ];

        for ($i = $options['start']; $i < $count && $i < $options['depth']; $i++) {
            $trace = $backtrace[$i] + ['file' => '[internal]', 'line' => '??'];
            $signature = $reference = '[main]';

            if (isset($backtrace[$i + 1])) {
                $next = $backtrace[$i + 1] + $_trace;
                $signature = $reference = $next['function'];

                if (!empty($next['class'])) {
                    $signature = $next['class'] . '::' . $next['function'];
                    $reference = $signature . '(';
                    if ($options['args'] && isset($next['args'])) {
                        $args = [];
                        foreach ($next['args'] as $arg) {
                            $args[] = Debugger::exportVar($arg);
                        }
                        $reference .= implode(', ', $args);
                    }
                    $reference .= ')';
                }
            }
            if (in_array($signature, $options['exclude'])) {
                continue;
            }
            if ($options['format'] === 'points' && $trace['file'] !== '[internal]') {
                $back[] = ['file' => $trace['file'], 'line' => $trace['line']];
            } elseif ($options['format'] === 'array') {
                $back[] = $trace;
            } else {
                if (isset($self->_templates[$options['format']]['traceLine'])) {
                    $tpl = $self->_templates[$options['format']]['traceLine'];
                } else {
                    $tpl = $self->_templates['base']['traceLine'];
                }
                $trace['path'] = static::trimPath($trace['file']);
                $trace['reference'] = $reference;
                unset($trace['object'], $trace['args']);
                $back[] = Text::insert($tpl, $trace, ['before' => '{:', 'after' => '}']);
            }
        }

        if ($options['format'] === 'array' || $options['format'] === 'points') {
            return $back;
        }

        return implode("\n", $back);
    }

    /**
     * Shortens file paths by replacing the application base path with 'APP', and the CakePHP core
     * path with 'CORE'.
     *
     * @param string $path Path to shorten.
     * @return string Normalized path
     */
    public static function trimPath($path)
    {
        if (defined('APP') && strpos($path, APP) === 0) {
            return str_replace(APP, 'APP/', $path);
        }
        if (defined('CAKE_CORE_INCLUDE_PATH') && strpos($path, CAKE_CORE_INCLUDE_PATH) === 0) {
            return str_replace(CAKE_CORE_INCLUDE_PATH, 'CORE', $path);
        }
        if (defined('ROOT') && strpos($path, ROOT) === 0) {
            return str_replace(ROOT, 'ROOT', $path);
        }

        return $path;
    }

    /**
     * Grabs an excerpt from a file and highlights a given line of code.
     *
     * Usage:
     *
     * ```
     * Debugger::excerpt('/path/to/file', 100, 4);
     * ```
     *
     * The above would return an array of 8 items. The 4th item would be the provided line,
     * and would be wrapped in `<span class="code-highlight"></span>`. All of the lines
     * are processed with highlight_string() as well, so they have basic PHP syntax highlighting
     * applied.
     *
     * @param string $file Absolute path to a PHP file.
     * @param int $line Line number to highlight.
     * @param int $context Number of lines of context to extract above and below $line.
     * @return array Set of lines highlighted
     * @see http://php.net/highlight_string
     * @link http://book.cakephp.org/3.0/en/development/debugging.html#getting-an-excerpt-from-a-file
     */
    public static function excerpt($file, $line, $context = 2)
    {
        $lines = [];
        if (!file_exists($file)) {
            return [];
        }
        $data = file_get_contents($file);
        if (empty($data)) {
            return $lines;
        }
        if (strpos($data, "\n") !== false) {
            $data = explode("\n", $data);
        }
        if (!isset($data[$line])) {
            return $lines;
        }
        $line = $line - 1;
        for ($i = $line - $context; $i < $line + $context + 1; $i++) {
            if (!isset($data[$i])) {
                continue;
            }
            $string = str_replace(["\r\n", "\n"], "", static::_highlight($data[$i]));
            if ($i == $line) {
                $lines[] = '<span class="code-highlight">' . $string . '</span>';
            } else {
                $lines[] = $string;
            }
        }

        return $lines;
    }

    /**
     * Wraps the highlight_string function in case the server API does not
     * implement the function as it is the case of the HipHop interpreter
     *
     * @param string $str The string to convert.
     * @return string
     */
    protected static function _highlight($str)
    {
        if (function_exists('hphp_log') || function_exists('hphp_gettid')) {
            return htmlentities($str);
        }
        $added = false;
        if (strpos($str, '<?php') === false) {
            $added = true;
            $str = "<?php \n" . $str;
        }
        $highlight = highlight_string($str, true);
        if ($added) {
            $highlight = str_replace(
                ['&lt;?php&nbsp;<br/>', '&lt;?php&nbsp;<br />'],
                '',
                $highlight
            );
        }

        return $highlight;
    }

    /**
     * Converts a variable to a string for debug output.
     *
     * *Note:* The following keys will have their contents
     * replaced with `*****`:
     *
     *  - password
     *  - login
     *  - host
     *  - database
     *  - port
     *  - prefix
     *  - schema
     *
     * This is done to protect database credentials, which could be accidentally
     * shown in an error message if CakePHP is deployed in development mode.
     *
     * @param string $var Variable to convert.
     * @param int $depth The depth to output to. Defaults to 3.
     * @return string Variable as a formatted string
     */
    public static function exportVar($var, $depth = 3)
    {
        return static::_export($var, $depth, 0);
    }

    /**
     * Protected export function used to keep track of indentation and recursion.
     *
     * @param mixed $var The variable to dump.
     * @param int $depth The remaining depth.
     * @param int $indent The current indentation level.
     * @return string The dumped variable.
     */
    protected static function _export($var, $depth, $indent)
    {
        switch (static::getType($var)) {
            case 'boolean':
                return ($var) ? 'true' : 'false';
            case 'integer':
                return '(int) ' . $var;
            case 'float':
                return '(float) ' . $var;
            case 'string':
                if (trim($var) === '') {
                    return "''";
                }

                return "'" . $var . "'";
            case 'array':
                return static::_array($var, $depth - 1, $indent + 1);
            case 'resource':
                return strtolower(gettype($var));
            case 'null':
                return 'null';
            case 'unknown':
                return 'unknown';
            default:
                return static::_object($var, $depth - 1, $indent + 1);
        }
    }

    /**
     * Export an array type object. Filters out keys used in datasource configuration.
     *
     * The following keys are replaced with ***'s
     *
     * - password
     * - login
     * - host
     * - database
     * - port
     * - prefix
     * - schema
     *
     * @param array $var The array to export.
     * @param int $depth The current depth, used for recursion tracking.
     * @param int $indent The current indentation level.
     * @return string Exported array.
     */
    protected static function _array(array $var, $depth, $indent)
    {
        $out = "[";
        $break = $end = null;
        if (!empty($var)) {
            $break = "\n" . str_repeat("\t", $indent);
            $end = "\n" . str_repeat("\t", $indent - 1);
        }
        $vars = [];

        if ($depth >= 0) {
            $outputMask = (array)static::outputMask();
            foreach ($var as $key => $val) {
                // Sniff for globals as !== explodes in < 5.4
                if ($key === 'GLOBALS' && is_array($val) && isset($val['GLOBALS'])) {
                    $val = '[recursion]';
                } elseif (array_key_exists($key, $outputMask)) {
                    $val = (string)$outputMask[$key];
                } elseif ($val !== $var) {
                    $val = static::_export($val, $depth, $indent);
                }
                $vars[] = $break . static::exportVar($key) .
                    ' => ' .
                    $val;
            }
        } else {
            $vars[] = $break . '[maximum depth reached]';
        }

        return $out . implode(',', $vars) . $end . ']';
    }

    /**
     * Handles object to string conversion.
     *
     * @param object $var Object to convert.
     * @param int $depth The current depth, used for tracking recursion.
     * @param int $indent The current indentation level.
     * @return string
     * @see \Cake\Error\Debugger::exportVar()
     */
    protected static function _object($var, $depth, $indent)
    {
        $out = '';
        $props = [];

        $className = get_class($var);
        $out .= 'object(' . $className . ') {';
        $break = "\n" . str_repeat("\t", $indent);
        $end = "\n" . str_repeat("\t", $indent - 1);

        if ($depth > 0 && method_exists($var, '__debugInfo')) {
            try {
                return $out . "\n" .
                    substr(static::_array($var->__debugInfo(), $depth - 1, $indent), 1, -1) .
                    $end . '}';
            } catch (Exception $e) {
                $message = $e->getMessage();

                return $out . "\n(unable to export object: $message)\n }";
            }
        }

        if ($depth > 0) {
            $outputMask = (array)static::outputMask();
            $objectVars = get_object_vars($var);
            foreach ($objectVars as $key => $value) {
                $value = array_key_exists($key, $outputMask) ? $outputMask[$key] : static::_export($value, $depth - 1, $indent);
                $props[] = "$key => " . $value;
            }

            $ref = new ReflectionObject($var);

            $filters = [
                ReflectionProperty::IS_PROTECTED => 'protected',
                ReflectionProperty::IS_PRIVATE => 'private',
            ];
            foreach ($filters as $filter => $visibility) {
                $reflectionProperties = $ref->getProperties($filter);
                foreach ($reflectionProperties as $reflectionProperty) {
                    $reflectionProperty->setAccessible(true);
                    $property = $reflectionProperty->getValue($var);

                    $value = static::_export($property, $depth - 1, $indent);
                    $key = $reflectionProperty->name;
                    $props[] = sprintf(
                        '[%s] %s => %s',
                        $visibility,
                        $key,
                        array_key_exists($key, $outputMask) ? $outputMask[$key] : $value
                    );
                }
            }

            $out .= $break . implode($break, $props) . $end;
        }
        $out .= '}';

        return $out;
    }

    /**
     * Get/Set the output format for Debugger error rendering.
     *
     * @param string|null $format The format you want errors to be output as.
     *   Leave null to get the current format.
     * @return string|null Returns null when setting. Returns the current format when getting.
     * @throws \InvalidArgumentException When choosing a format that doesn't exist.
     */
    public static function outputAs($format = null)
    {
        $self = Debugger::getInstance();
        if ($format === null) {
            return $self->_outputFormat;
        }

        if ($format !== false && !isset($self->_templates[$format])) {
            throw new InvalidArgumentException('Invalid Debugger output format.');
        }
        $self->_outputFormat = $format;

        return null;
    }

    /**
     * Add an output format or update a format in Debugger.
     *
     * ```
     * Debugger::addFormat('custom', $data);
     * ```
     *
     * Where $data is an array of strings that use Text::insert() variable
     * replacement. The template vars should be in a `{:id}` style.
     * An error formatter can have the following keys:
     *
     * - 'error' - Used for the container for the error message. Gets the following template
     *   variables: `id`, `error`, `code`, `description`, `path`, `line`, `links`, `info`
     * - 'info' - A combination of `code`, `context` and `trace`. Will be set with
     *   the contents of the other template keys.
     * - 'trace' - The container for a stack trace. Gets the following template
     *   variables: `trace`
     * - 'context' - The container element for the context variables.
     *   Gets the following templates: `id`, `context`
     * - 'links' - An array of HTML links that are used for creating links to other resources.
     *   Typically this is used to create javascript links to open other sections.
     *   Link keys, are: `code`, `context`, `help`. See the js output format for an
     *   example.
     * - 'traceLine' - Used for creating lines in the stacktrace. Gets the following
     *   template variables: `reference`, `path`, `line`
     *
     * Alternatively if you want to use a custom callback to do all the formatting, you can use
     * the callback key, and provide a callable:
     *
     * ```
     * Debugger::addFormat('custom', ['callback' => [$foo, 'outputError']];
     * ```
     *
     * The callback can expect two parameters. The first is an array of all
     * the error data. The second contains the formatted strings generated using
     * the other template strings. Keys like `info`, `links`, `code`, `context` and `trace`
     * will be present depending on the other templates in the format type.
     *
     * @param string $format Format to use, including 'js' for JavaScript-enhanced HTML, 'html' for
     *    straight HTML output, or 'txt' for unformatted text.
     * @param array $strings Template strings, or a callback to be used for the output format.
     * @return array The resulting format string set.
     */
    public static function addFormat($format, array $strings)
    {
        $self = Debugger::getInstance();
        if (isset($self->_templates[$format])) {
            if (isset($strings['links'])) {
                $self->_templates[$format]['links'] = array_merge(
                    $self->_templates[$format]['links'],
                    $strings['links']
                );
                unset($strings['links']);
            }
            $self->_templates[$format] = $strings + $self->_templates[$format];
        } else {
            $self->_templates[$format] = $strings;
        }

        return $self->_templates[$format];
    }

    /**
     * Takes a processed array of data from an error and displays it in the chosen format.
     *
     * @param string $data Data to output.
     * @return void
     */
    public function outputError($data)
    {
        $defaults = [
            'level' => 0,
            'error' => 0,
            'code' => 0,
            'description' => '',
            'file' => '',
            'line' => 0,
            'context' => [],
            'start' => 2,
        ];
        $data += $defaults;

        $files = $this->trace(['start' => $data['start'], 'format' => 'points']);
        $code = '';
        $file = null;
        if (isset($files[0]['file'])) {
            $file = $files[0];
        } elseif (isset($files[1]['file'])) {
            $file = $files[1];
        }
        if ($file) {
            $code = $this->excerpt($file['file'], $file['line'] - 1, 1);
        }
        $trace = $this->trace(['start' => $data['start'], 'depth' => '20']);
        $insertOpts = ['before' => '{:', 'after' => '}'];
        $context = [];
        $links = [];
        $info = '';

        foreach ((array)$data['context'] as $var => $value) {
            $context[] = "\${$var} = " . $this->exportVar($value, 3);
        }

        switch ($this->_outputFormat) {
            case false:
                $this->_data[] = compact('context', 'trace') + $data;

                return;
            case 'log':
                $this->log(compact('context', 'trace') + $data);

                return;
        }

        $data['trace'] = $trace;
        $data['id'] = 'cakeErr' . uniqid();
        $tpl = $this->_templates[$this->_outputFormat] + $this->_templates['base'];

        if (isset($tpl['links'])) {
            foreach ($tpl['links'] as $key => $val) {
                $links[$key] = Text::insert($val, $data, $insertOpts);
            }
        }

        if (!empty($tpl['escapeContext'])) {
            $context = h($context);
            $data['description'] = h($data['description']);
        }

        $infoData = compact('code', 'context', 'trace');
        foreach ($infoData as $key => $value) {
            if (empty($value) || !isset($tpl[$key])) {
                continue;
            }
            if (is_array($value)) {
                $value = implode("\n", $value);
            }
            $info .= Text::insert($tpl[$key], [$key => $value] + $data, $insertOpts);
        }
        $links = implode(' ', $links);

        if (isset($tpl['callback']) && is_callable($tpl['callback'])) {
            call_user_func($tpl['callback'], $data, compact('links', 'info'));

            return;
        }
        echo Text::insert($tpl['error'], compact('links', 'info') + $data, $insertOpts);
    }

    /**
     * Get the type of the given variable. Will return the class name
     * for objects.
     *
     * @param mixed $var The variable to get the type of.
     * @return string The type of variable.
     */
    public static function getType($var)
    {
        if (is_object($var)) {
            return get_class($var);
        }
        if ($var === null) {
            return 'null';
        }
        if (is_string($var)) {
            return 'string';
        }
        if (is_array($var)) {
            return 'array';
        }
        if (is_int($var)) {
            return 'integer';
        }
        if (is_bool($var)) {
            return 'boolean';
        }
        if (is_float($var)) {
            return 'float';
        }
        if (is_resource($var)) {
            return 'resource';
        }

        return 'unknown';
    }

    /**
     * Prints out debug information about given variable.
     *
     * @param mixed $var Variable to show debug information for.
     * @param array $location If contains keys "file" and "line" their values will
     *    be used to show location info.
     * @param bool|null $showHtml If set to true, the method prints the debug
     *    data in a browser-friendly way.
     * @return void
     */
    public static function printVar($var, $location = [], $showHtml = null)
    {
        $location += ['file' => null, 'line' => null];
        $file = $location['file'];
        $line = $location['line'];
        $lineInfo = '';
        if ($file) {
            $search = [];
            if (defined('ROOT')) {
                $search = [ROOT];
            }
            if (defined('CAKE_CORE_INCLUDE_PATH')) {
                array_unshift($search, CAKE_CORE_INCLUDE_PATH);
            }
            $file = str_replace($search, '', $file);
        }
        $html = <<<HTML
<div class="cake-debug-output">
%s
<pre class="cake-debug">
%s
</pre>
</div>
HTML;
        $text = <<<TEXT
%s
########## DEBUG ##########
%s
###########################

TEXT;
        $template = $html;
        if ((PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') || $showHtml === false) {
            $template = $text;
            if ($file && $line) {
                $lineInfo = sprintf('%s (line %s)', $file, $line);
            }
        }
        if ($showHtml === null && $template !== $text) {
            $showHtml = true;
        }
        $var = Debugger::exportVar($var, 25);
        if ($showHtml) {
            $template = $html;
            $var = h($var);
            if ($file && $line) {
                $lineInfo = sprintf('<span><strong>%s</strong> (line <strong>%s</strong>)</span>', $file, $line);
            }
        }
        printf($template, $lineInfo, $var);
    }

    /**
     * Verifies that the application's salt and cipher seed value has been changed from the default value.
     *
     * @return void
     */
    public static function checkSecurityKeys()
    {
        if (Security::salt() === '__SALT__') {
            trigger_error(sprintf('Please change the value of %s in %s to a salt value specific to your application.', '\'Security.salt\'', 'ROOT/config/app.php'), E_USER_NOTICE);
        }
    }
}
