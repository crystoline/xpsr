<?php


namespace Crystoline\Xpsr;

use Illuminate\Config\Repository;
use Symfony\Component\Finder\Finder;

class Configuration extends Repository
{
    /**
     * @var string
     */
    private $configPath;


    /**
     * @param string $path
     * @param string|null $environment
     * @return void
     */
    public function loadConfigurationFiles($path, $environment = null)
    {

        $this->configPath = $path;
        foreach ($this->getConfigurationFiles() as $fileKey => $path) {
            $this->set($fileKey, require $path);
        }

        foreach ($this->getConfigurationFiles($environment) as $fileKey => $path) {
            $envConfig = require $path;
            if (is_array($envConfig)) {

                foreach ($envConfig as $envKey => $value) {
                    $this->set($fileKey . '.' . $envKey, $value);
                }
            }
        }
    }

    /**
     * Get the configuration files for the selected environment
     * @param string|null $environment
     * @return array
     */
    protected function getConfigurationFiles($environment = null)
    {
        $path = $this->configPath;
        if ($environment) {
            $path .= '/' . $environment;
        }

        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $phpFiles = Finder::create()->files()->name('*.php')->in($path)->depth(0);

        foreach ($phpFiles as $file) {
            $files[basename($file->getRealPath(), '.php')] = $file->getRealPath();
        }

        return $files;
    }

//    private static $configs = [];
//
//    /** @var string */
//    private $path;
//
//
//    public function __construct($path)
//    {
//        $this->path = $path;
//        $this->load();
//    }
//
//    public function load(){
//        if(!empty(self::$configs)){
//            return;
//        }
//
//        $this->reload();;
//    }
//
//    public function reload(){
//
//        $files = scandir($this->path);
//
//        if($files === false){
//            return;
//        }
//
//        foreach ($files as $file){
//
//            $filePath = $this->path . DIRECTORY_SEPARATOR . $file;
//            if(!is_file($filePath)){
//                continue;
//            }
//
//            $extension = pathinfo($file, PATHINFO_EXTENSION);
//            $name = pathinfo($file, PATHINFO_BASENAME);
//
//            if($extension !== 'php'){
//                continue;
//            }
//
//            self::$configs[strtolower($name)] = require $filePath;
//
//        }
//    }
//
//    /**
//     * @param string $name
//     * @return mixed
//     */
//    public function getConfig($name){
//        return $name;
//    }
}