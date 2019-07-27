<?php

namespace App\Services;

use App\Controller\Files\FileUploadController;
use App\Controller\Utils\Application;
use App\Controller\Utils\Utils;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * This service is responsible for handling files in terms of internal usage, like moving/renaming/etc...
 * Class FilesHandler
 * @package App\Services
 */

class FilesHandler {

    const KEY_CURRENT_UPLOAD_TYPE       = 'current_upload_type';
    const KEY_TARGET_UPLOAD_TYPE        = 'target_upload_type';
    const KEY_CURRENT_SUBDIRECTORY_NAME = 'current_subdirectory_name';
    const KEY_TARGET_SUBDIRECTORY_NAME  = 'target_subdirectory_name';

    const FILE_KEY                      = 'file';

    /**
     * @var Application $application
     */
    private $application;

    /**
     * @var DirectoriesHandler $directoriesHandler
     */
    private $directoriesHandler;

    /**
     * @var LoggerInterface $logger
     */
    private $logger;

    public function __construct(Application $application, DirectoriesHandler $directoriesHandler, LoggerInterface $logger) {
        $this->application          = $application;
        $this->directoriesHandler   = $directoriesHandler;
        $this->logger               = $logger;

    }

    /**
     * @Route("/upload/action/copy-folder-data", name="upload_copy_folder_data", methods="POST")
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function copyFolderDataToAnotherFolderByPostRequest(Request $request) {

        if ( !$request->query->has(static::KEY_CURRENT_UPLOAD_TYPE) ) {
            return new Response("Current upload type is missing in request.", 500);
        }

        if ( !$request->query->has(static::KEY_TARGET_UPLOAD_TYPE) ) {
            return new Response("Target upload type is missing in request.", 500);
        }

        if ( !$request->query->has(static::KEY_CURRENT_SUBDIRECTORY_NAME) ) {
            return new Response("Current subdirectory name is missing in request.", 500);
        }

        if ( !$request->query->has(static::KEY_TARGET_SUBDIRECTORY_NAME) ) {
            return new Response("Target subdirectory name is missing in request.", 500);
        }

        $current_upload_type        = $request->query->get(static::KEY_CURRENT_UPLOAD_TYPE);
        $target_upload_type         = $request->query->get(static::KEY_TARGET_UPLOAD_TYPE);
        $current_subdirectory_name  = $request->query->get(static::KEY_CURRENT_SUBDIRECTORY_NAME);
        $target_subdirectory_name   = $request->query->get(static::KEY_TARGET_SUBDIRECTORY_NAME);

        $response = $this->copyFolderDataToAnotherFolder($current_upload_type, $target_upload_type, $current_subdirectory_name, $target_subdirectory_name);

        return $response;
    }

    /**
     * @param string $current_upload_type
     * @param string $target_upload_type
     * @param string $current_subdirectory_name
     * @param string $target_subdirectory_name
     * @return Response
     * @throws \Exception
     */
    public function copyFolderDataToAnotherFolder(string $current_upload_type, string $target_upload_type, string $current_subdirectory_name, string $target_subdirectory_name){

        $this->logger->info('Started copying data between folders via Post Request.', [
            'current_upload_type'          => $current_upload_type,
            'target_upload_type'           => $target_upload_type,
            'current_subdirectory_name'    => $current_subdirectory_name,
            'target_subdirectory_name'     => $target_subdirectory_name
        ]);

        $current_directory  = FileUploadController::getTargetDirectoryForUploadType($current_upload_type);
        $target_directory   = FileUploadController::getTargetDirectoryForUploadType($target_upload_type);

        $is_current_subdirectory_existing = !FileUploadController::isSubdirectoryForTypeExisting($current_directory, $current_subdirectory_name);
        $is_target_subdirectory_existing  = !FileUploadController::isSubdirectoryForTypeExisting($target_directory, $target_subdirectory_name);

        if( $is_current_subdirectory_existing ){
            $message = 'Current subdirectory does not exist.';
            $this->logger->info($message);
            return new Response($message, 500);
        }

        if( $is_target_subdirectory_existing ){
            $message = 'Target subdirectory does not exist.';
            $this->logger->info($message);
            return new Response($message, 500);
        }

        $current_subdirectory_path = FileUploadController::getSubdirectoryPath($current_directory, $current_subdirectory_name);
        $target_subdirectory_path  = FileUploadController::getSubdirectoryPath($target_directory, $target_subdirectory_name);

        try{
            Utils::copyFilesRecursively($current_subdirectory_path, $target_subdirectory_path);
        }catch(\Exception $e){
            $this->logger->info('Exception was thrown while moving data between folders', [
                'message' => $e->getMessage()
            ]);

            return new Response('There was an error while moving files from one folder to another.',500);
        }

        $this->logger->info('Finished copying data.');
        return new Response('Data has been successfully moved to new directory', 200);
    }

    /**
     * @Route("/upload/action/copy-and-remove-folder-data", name="upload_copy_and_remove_folder_data", methods="POST")
     * @param Request $request
     * @return Response
     */
    public function copyAndRemoveDataViaPost(Request $request) {

        if ( !$request->query->has(static::KEY_CURRENT_SUBDIRECTORY_NAME) ) {
            return new Response("Current subdirectory name is missing in request.");
        }

        if ( !$request->query->has(static::KEY_CURRENT_SUBDIRECTORY_NAME) ) {
            return new Response("Subdirectory current name is missing in request.");
        }
        $current_upload_type        = $request->query->get(static::KEY_CURRENT_UPLOAD_TYPE);
        $current_subdirectory_name  = $request->query->get(static::KEY_CURRENT_SUBDIRECTORY_NAME);

        try{
            $this->copyFolderDataToAnotherFolderByPostRequest($request);
            $this->directoriesHandler->removeFolder($current_upload_type, $current_subdirectory_name);
        }catch(\Exception $e){
            return new Response ('Then was an error while copying and removing data.');
        }

        return new Response('Data has been successfully copied and removed afterward.');
    }


    /**
     * @param string $current_upload_type
     * @param string $target_upload_type
     * @param string $current_subdirectory_name
     * @param string $target_subdirectory_name
     * @param bool $remove_current_folder
     * @return Response
     */
    public function copyAndRemoveData(
        string $current_upload_type,
        string $target_upload_type,
        string $current_subdirectory_name,
        string $target_subdirectory_name,
        bool   $remove_current_folder = true
    ) {

        $this->logger->info('Started copying and removing data between folders');

        try{
            $this->copyFolderDataToAnotherFolder($current_upload_type, $target_upload_type, $current_subdirectory_name, $target_subdirectory_name);

            $this->logger->info('Started removing folder data.');

            if($remove_current_folder){
                $this->directoriesHandler->removeFolder($current_upload_type, $current_subdirectory_name);
            }
        }catch(\Exception $e){
            $this->logger->info('Exception was thrown while trying to copy and remove data: ', [
                'message' => $e->getMessage()
            ]);
            return new Response ('Then was an error while copying and removing data.');
        }

        $this->logger->info('Copying and removing data has been finished!');
        return new Response('Data has been successfully copied and removed afterward.');
    }

}