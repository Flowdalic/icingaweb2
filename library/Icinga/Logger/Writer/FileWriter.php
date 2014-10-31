<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Logger\Writer;

use Exception;
use Zend_Config;
use Icinga\Exception\ConfigurationError;
use Icinga\Logger\Logger;
use Icinga\Logger\LogWriter;
use Icinga\Util\File;

/**
 * Log to a file
 */
class FileWriter extends LogWriter
{
    /**
     * Path to the file
     *
     * @var string
     */
    protected $file;

    /**
     * Create a new file log writer
     *
     * @param   Zend_Config $config
     *
     * @throws  ConfigurationError  If the configuration directive 'file' is missing or if the path to 'file' does
     *                              not exist or if writing to 'file' is not possible
     */
    public function __construct(Zend_Config $config)
    {
        if ($config->file === null) {
            throw new ConfigurationError('Required logging configuration directive \'file\' missing');
        }
        $this->file = $config->file;

        if (substr($this->file, 0, 6) !== 'php://' && ! file_exists(dirname($this->file))) {
            throw new ConfigurationError(
                'Log path "%s" does not exist',
                dirname($this->file)
            );
        }

        try {
            $this->write(''); // Avoid to handle such errors on every write access
        } catch (Exception $e) {
            throw new ConfigurationError(
                'Cannot write to log file "%s" (%s)',
                $this->file,
                $e->getMessage()
            );
        }
    }

    /**
     * Log a message
     *
     * @param   int     $level      The logging level
     * @param   string  $message    The log message
     */
    public function log($level, $message)
    {
        $this->write(date('c') . ' - ' . Logger::$levels[$level] . ' - ' . $message . PHP_EOL);
    }

    /**
     * Write a message to the log
     *
     * @param string $message
     */
    protected function write($message)
    {
        $file = new File($this->file, 'a');
        $file->fwrite($message);
        $file->fflush();
    }
}
