<?php


namespace App\Action\Modules\Job;


use App\Controller\Core\AjaxResponse;
use App\Controller\Core\Application;
use App\Controller\Core\Controllers;
use App\Controller\Core\Repositories;
use App\Controller\Validators\Entities\EntityValidator;
use App\Entity\Modules\Job\MyJobHolidays;
use App\Form\Modules\Job\MyJobHolidaysType;
use App\VO\Validators\ValidationResultVO;
use Doctrine\DBAL\DBALException;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\ORMException;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MyJobHolidaysAction extends AbstractController {

    const KEY_CHOICES = 'choices';

    /**
     * @var Application
     */
    private $app;

    /**
     * @var Controllers $controllers
     */
    private $controllers;

    /**
     * @var EntityValidator $entity_validator
     */
    private $entity_validator;

    public function __construct(Application $app, Controllers $controllers, EntityValidator $entity_validator) {
        $this->app              = $app;
        $this->controllers      = $controllers;
        $this->entity_validator = $entity_validator;
    }

    /**
     * @Route("/my-job/holidays", name="my-job-holidays")
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function display(Request $request) {

        $ajax_response     = new AjaxResponse();
        $validation_result = $this->add($request);

        if (!$request->isXmlHttpRequest()) {
            return $this->renderTemplate(false);
        }

        try{
            $template_content = $this->renderTemplate(true)->getContent();

            if( !is_null($validation_result) && !$validation_result->isValid() ){
                $message = $this->app->translator->translate('messages.general.couldNotHandleTheRequest');

                $ajax_response->setCode(Response::HTTP_BAD_REQUEST);
                $ajax_response->setSuccess(false);
                $ajax_response->setMessage($message);
                $ajax_response->setTemplate($template_content);
                $ajax_response->setInvalidFormFields($validation_result->getInvalidFieldsMessages());
                $ajax_response->setValidatedFormPrefix(MyJobHolidaysType::getFormPrefix());

                return $ajax_response->buildJsonResponse();
            }
        }catch (Exception $e){
            $this->app->logExceptionWasThrown($e);
            $message = $this->app->translator->translate('messages.general.internalServerError');

            $ajax_response->setCode(Response::HTTP_INTERNAL_SERVER_ERROR);
            $ajax_response->setSuccess(false);
            $ajax_response->setMessage($message);

            return $ajax_response->buildJsonResponse();
        }

        $ajax_response->setCode(Response::HTTP_OK);
        $ajax_response->setSuccess(true);
        $ajax_response->setTemplate($template_content);

        return $ajax_response->buildJsonResponse();
    }

    /**
     * @Route("/my-job/holidays/update/",name="my-job-holidays-update")
     * @param Request $request
     * @return JsonResponse
     *
     * @throws MappingException
     * @throws NonUniqueResultException
     * @throws ORMException
     */
    public function update(Request $request) {
        $parameters = $request->request->all();
        $entity_id  = trim($parameters['id']);

        $entity     = $this->controllers->getMyJobHolidaysController()->findOneEntityByIdOrNull($entity_id);
        $response   = $this->app->repositories->update($parameters, $entity);

        return AjaxResponse::initializeFromResponse($response)->buildJsonResponse();
    }

    /**
     * @Route("/my-job/holidays/remove/",name="my-job-holidays-remove")
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function remove(Request $request) {

        $response = $this->app->repositories->deleteById(
            Repositories::MY_JOB_HOLIDAYS_REPOSITORY_NAME,
            $request->request->get('id')
        );

        $message = $response->getContent();

        if ($response->getStatusCode() == 200) {
            $rendered_template = $this->renderTemplate(true, true);
            $template_content  = $rendered_template->getContent();

            return AjaxResponse::buildJsonResponseForAjaxCall(200, $message, $template_content);
        }

        return AjaxResponse::buildJsonResponseForAjaxCall(500, $message);
    }

    /**
     * @param Request $request
     * @return ValidationResultVO
     * @throws DBALException
     * @throws Exception
     */
    private function add(Request $request): ?ValidationResultVO {

        $all_pools_years = $this->controllers->getMyJobHolidaysPoolController()->getAllPoolsYears();

        $form = $this->app->forms->jobHolidaysForm([
            static::KEY_CHOICES => $all_pools_years
        ]);

        $form->handleRequest($request);

        if ( $form->isSubmitted() && $form->isValid() ) {

            /**
             * @var MyJobHolidays $job_holiday
             */
            $job_holiday       = $form->getData();
            $validation_result = $this->entity_validator->handleValidation($job_holiday, EntityValidator::ACTION_CREATE);

            if ( $validation_result->isValid() ){
                $em = $this->getDoctrine()->getManager();
                $em->persist($job_holiday);
                $em->flush();
            }

            return $validation_result;
        }

        return null;
    }

    /**
     * @param bool $ajax_render
     * @param bool $skip_rewriting_twig_vars_to_js
     * @return Response
     * @throws DBALException
     */
    private function renderTemplate(bool $ajax_render = false, bool $skip_rewriting_twig_vars_to_js = false) {

        $all_pools_years                    = $this->controllers->getMyJobHolidaysPoolController()->getAllPoolsYears();
        $all_holidays_spent                 = $this->controllers->getMyJobHolidaysController()->getAllNotDeleted();
        $job_holidays_summary               = $this->controllers->getMyJobHolidaysPoolController()->getHolidaysSummaryGroupedByYears();
        $job_holidays_available_totally     = $this->controllers->getMyJobHolidaysPoolController()->getAvailableDaysTotally();

        $job_holidays_form  = $this->app->forms->jobHolidaysForm([
            static::KEY_CHOICES => $all_pools_years
        ]);

        $twig_data = [
            'ajax_render'                       => $ajax_render,
            'all_holidays_spent'                => $all_holidays_spent,
            'job_holidays_form'                 => $job_holidays_form->createView(),
            'job_holidays_summary'              => $job_holidays_summary,
            'job_holidays_available_totally'    => $job_holidays_available_totally,
            'skip_rewriting_twig_vars_to_js'    => $skip_rewriting_twig_vars_to_js,
        ];

        return $this->render('modules/my-job/holidays.html.twig', $twig_data);
    }

}