<?php


namespace App\Controller\Files;

use App\Controller\Modules\Files\MyFilesController;
use App\Controller\Modules\Images\MyImagesController;
use App\Controller\Core\Application;
use App\Controller\Core\Env;
use App\Controller\Modules\ModulesController;
use App\Controller\Modules\Video\MyVideoController;
use App\Services\Files\DirectoriesHandler;
use App\Services\Files\FilesHandler;
use App\Services\Core\Translator;
use DirectoryIterator;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class FileUploadController extends AbstractController {

    const MODULE_UPLOAD_DIR_FOR_IMAGES  = 'images';
    const MODULE_UPLOAD_DIR_FOR_VIDEO   = 'videos';
    const MODULE_UPLOAD_DIR_FOR_FILES   = 'files';

    const KEY_SUBDIRECTORY_NEW_NAME       = 'subdirectory_new_name';
    const KEY_SUBDIRECTORY_CURRENT_NAME   = 'subdirectory_current_name';

    const KEY_SUBDIRECTORY_CURRENT_PATH_IN_MODULE_UPLOAD_DIR   = 'subdirectory_current_path_in_module_upload_dir';
    const KEY_SUBDIRECTORY_TARGET_PATH_IN_MODULE_UPLOAD_DIR    = 'subdirectory_target_path_in_module_upload_dir';

    const KEY_SUBDIRECTORY_NAME         = 'subdirectory_name';

    const KEY_UPLOAD_MODULE_DIR         = 'upload_module_dir';

    const KEY_MAIN_FOLDER               = 'Main folder';

    const KEY_TAG           = 'tag';
    const KEY_FILENAME      = 'fileName';
    const KEY_EXTENSION     = 'fileExtension';
    const KEY_UPLOAD_TABLE  = 'upload_table';

    // info: might cause issue upon creating subdirectory named `upload`
    const REGEX_MATCH_UPLOAD_MODULE_DIR_FOR_FILE_PATH         = "[\/]?upload\/(?<" . self::REGEX_MATCH_UPLOAD_MODULE_DIR_FOR_FILE_PATH_DIRNAME . ">[a-zA-z]+)\/";
    const REGEX_MATCH_UPLOAD_MODULE_DIR_FOR_FILE_PATH_DIRNAME = "DIR_NAME";

    const MODULES_UPLOAD_DIRS = [
        self::MODULE_UPLOAD_DIR_FOR_IMAGES => self::MODULE_UPLOAD_DIR_FOR_IMAGES,
        self::MODULE_UPLOAD_DIR_FOR_VIDEO  => self::MODULE_UPLOAD_DIR_FOR_VIDEO,
        self::MODULE_UPLOAD_DIR_FOR_FILES  => self::MODULE_UPLOAD_DIR_FOR_FILES
    ];

    const MODULES_UPLOAD_DIRS_FOR_MODULES_NAMES = [
        MyImagesController::MODULE_NAME       => self::MODULE_UPLOAD_DIR_FOR_IMAGES,
        MyFilesController::MODULE_NAME        => self::MODULE_UPLOAD_DIR_FOR_FILES,
        ModulesController::MODULE_NAME_VIDEO  => self::MODULE_UPLOAD_DIR_FOR_VIDEO,
    ];

    const MODULE_UPLOAD_DIR_TO_MODULE_NAME = [
       self::MODULE_UPLOAD_DIR_FOR_IMAGES => ModulesController::MODULE_NAME_IMAGES,
       self::MODULE_UPLOAD_DIR_FOR_FILES  => MyFilesController::MODULE_NAME,
       self::MODULE_UPLOAD_DIR_FOR_VIDEO  => ModulesController::MODULE_NAME_VIDEO,
    ];

    /**
     * @var FilesHandler $files_handler
     */
    private $files_handler;

    /**
     * @var Application $app
     */
    private $app;

    /**
     * @var DirectoriesHandler $directories_handler
     */
    private $directories_handler;

    public function __construct(FilesHandler $filesHandler, DirectoriesHandler $directoriesHandler, Application $app) {
        $this->app                 = $app;
        $this->files_handler       = $filesHandler;
        $this->directories_handler = $directoriesHandler;
    }

    /**
     * @param string $upload_module_dir
     * @return mixed
     * @throws Exception
     */
    public static function getTargetDirectoryForUploadModuleDir(string $upload_module_dir){
        $translator = new Translator();

        switch ($upload_module_dir) {
            case FileUploadController::MODULE_UPLOAD_DIR_FOR_FILES:
                $targetDirectory = Env::getFilesUploadDir();
                break;
            case FileUploadController::MODULE_UPLOAD_DIR_FOR_IMAGES:
                $targetDirectory = Env::getImagesUploadDir();
                break;
            case FileUploadController::MODULE_UPLOAD_DIR_FOR_VIDEO:
                $targetDirectory = Env::getVideoUploadDir();
                break;
            default:
                $message  = $translator->translate('responses.upload.uploadDirNotSupported');
                throw new Exception($message);
        }

        return $targetDirectory;
    }

    /**
     * @param string $target_directory
     * @param string $subdirectory_name
     * @return bool
     */
    public static function isSubdirectoryForModuleDirExisting(string $target_directory, string $subdirectory_name): bool {
        $subdirectory_path = static::getSubdirectoryPath($target_directory, $subdirectory_name);
        return file_exists($subdirectory_path);
    }

    /**
     * @param string $target_directory
     * @param string $subdirectory_name
     * @return string
     */
    public static function getSubdirectoryPath(string $target_directory, string $subdirectory_name){
        return $target_directory . '/' . $subdirectory_name;
    }

    /**
     * @param bool $grouped_by_module_upload_dirs
     * @param bool $include_main_folder
     * @return array
     * @throws Exception
     */
    public static function getFoldersTreesForAllUploadModulesDirs($grouped_by_module_upload_dirs = false, $include_main_folder = false){

        $subdirectories = [];

        if( !$grouped_by_module_upload_dirs ){
            foreach(static::MODULES_UPLOAD_DIRS as $module_upload_dir){
                $subdirectories = array_merge($subdirectories, static::getFoldersTreesForUploadModuleDir($module_upload_dir, $include_main_folder) );
            }
        }else{
            foreach(static::MODULES_UPLOAD_DIRS as $module_upload_dir){
                $subdirectories[$module_upload_dir] = static::getFoldersTreesForUploadModuleDir($module_upload_dir, $include_main_folder);
            }
        }

        return $subdirectories;
    }

    /**
     * @param string $upload_module_dir
     * @param bool $include_main_folder
     * @return array|false
     * @throws Exception
     */
    public static function getFoldersTreesForUploadModuleDir(string $upload_module_dir, $include_main_folder = false)
    {
        $target_directory_for_module_upload_dir = static::getTargetDirectoryForUploadModuleDir($upload_module_dir);
        $folders_trees                          = DirectoriesHandler::buildFoldersTreeForDirectory( new DirectoryIterator( $target_directory_for_module_upload_dir ), true );

        if( $include_main_folder ){
            $subdirectories[static::KEY_MAIN_FOLDER] = "";
        }

        return $folders_trees;
    }

    /**
     * Will return the upload module name for file path
     *
     * @param string $filepath
     * @return string
     * @throws Exception
     */
    public static function getUploadModuleNameForFilePath(string $filepath): string
    {
        preg_match("#" . self::REGEX_MATCH_UPLOAD_MODULE_DIR_FOR_FILE_PATH . "#", $filepath, $matches);
        $upload_module_dir = $matches[self::REGEX_MATCH_UPLOAD_MODULE_DIR_FOR_FILE_PATH_DIRNAME];

        if( !array_key_exists($upload_module_dir,FileUploadController::MODULE_UPLOAD_DIR_TO_MODULE_NAME) ){
            $message = "Given upload_module_dir is not an upload module dir";
            throw new Exception($message);
        }

        $module_name = FileUploadController::MODULE_UPLOAD_DIR_TO_MODULE_NAME[$upload_module_dir];

        return $module_name;
    }
}