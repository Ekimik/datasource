<?php
/**
 * Internationalization Management Shell
 *
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
namespace Cake\Shell;

use Cake\Console\Shell;

/**
 * Shell for I18N management.
 *
 */
class I18nShell extends Shell
{

    /**
     * Contains tasks to load and instantiate
     *
     * @var array
     */
    public $tasks = ['Extract'];

    /**
     * Override main() for help message hook
     *
     * @return void
     */
    public function main()
    {
        $this->out('<info>I18n Shell</info>');
        $this->hr();
        $this->out('[E]xtract POT file from sources');
        $this->out('[I]inialize a language from POT file');
        $this->out('[H]elp');
        $this->out('[Q]uit');

        $choice = strtolower($this->in('What would you like to do?', ['E', 'I', 'H', 'Q']));
        switch ($choice) {
            case 'e':
                $this->Extract->main();
                break;
            case 'i':
                $this->init();
                break;
            case 'h':
                $this->out($this->OptionParser->help());
                break;
            case 'q':
                $this->_stop();
                return;
            default:
                $this->out('You have made an invalid selection. Please choose a command to execute by entering E, I, H, or Q.');
        }
        $this->hr();
        $this->main();
    }

    /**
     * Inits PO file from POT file.
     *
     * @return void
     */
    public function init($language = null) {
        if (!$language) {
            $language = strtolower($this->in('What language? Please use the two-letter ISO code, e.g. `en`.'));
        }
        if (strlen($language) !== 2) {
            return $this->error('Must be a two-letter ISO code');
        }

        $this->_paths = [APP];
        if (!empty($this->params['plugin'])) {
            $plugin = Inflector::camelize($this->params['plugin']);
            $this->_paths = [Plugin::classPath($plugin)];
            $this->params['plugin'] = $plugin;
        }

        $response = $this->in('What folder?', null, rtrim($this->_paths[0], DS) . DS . 'Locale');
        $sourceFolder = rtrim($response, DS) . DS;
        $targetFolder = $sourceFolder . $language . DS;
        if (!is_dir($targetFolder)) {
            mkdir($targetFolder, 0770, true);
        }

        $count = 0;
        $iterator = new \DirectoryIterator($sourceFolder);
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) {
                continue;
            }
            $filename = $fileinfo->getFilename();
            $newFilename = $fileinfo->getBasename('.pot');
            $newFilename = $newFilename . '.po';
            if (empty($this->params['force']) && is_file($targetFolder . $newFilename)) {
                $this->err('File ' . $newFilename . ' exists, skipping. Use --force or -f to force overwriting');
                continue;
            }

            copy($sourceFolder . $filename, $targetFolder . $newFilename);
            $count++;
        }

        $this->out('Generated ' . $count . ' PO files in ' . $targetFolder);
    }

    /**
     * Gets the option parser instance and configures it.
     *
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function getOptionParser()
    {
        $parser = parent::getOptionParser();
        $initParser = [
            'options' => [
                'plugin' => [
                    'help' => 'Plugin name.',
                    'short' => 'p'
                ],
                'force' => [
                    'help' => 'Force overwriting.',
                    'short' => 'f',
                    'boolean' => true
                ]
            ],
            'arguments' => [
                'language' => [
                    'help' => 'Two-letter language code.'
                ]
            ]
        ];

        $parser->description(
            'I18n Shell generates .pot files(s) with translations.'
        )->addSubcommand('extract', [
            'help' => 'Extract the po translations from your application',
            'parser' => $this->Extract->getOptionParser()
        ])
        ->addSubcommand('init', [
            'help' => 'Init PO language file from POT file',
            'parser' => $initParser
        ]);

        return $parser;
    }
}
