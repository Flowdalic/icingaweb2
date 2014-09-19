<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Module\Monitoring\Web\Controller;

use Icinga\Module\Monitoring\Controller;
use Icinga\Module\Monitoring\Form\Command\Object\AcknowledgeProblemCommandForm;
use Icinga\Module\Monitoring\Form\Command\Object\CheckNowCommandForm;
use Icinga\Module\Monitoring\Form\Command\Object\DeleteCommentCommandForm;
use Icinga\Module\Monitoring\Form\Command\Object\DeleteDowntimeCommandForm;
use Icinga\Module\Monitoring\Form\Command\Object\ObjectsCommandForm;
use Icinga\Module\Monitoring\Form\Command\Object\RemoveAcknowledgementCommandForm;
use Icinga\Module\Monitoring\Form\Command\Object\ToggleObjectFeaturesCommandForm;
use Icinga\Web\Url;

/**
 * Base class for the host and service controller
 */
abstract class MonitoredObjectController extends Controller
{
    /**
     * The requested host or service
     *
     * @var \Icinga\Module\Monitoring\Object\Host|\Icinga\Module\Monitoring\Object\Host
     */
    protected $object;

    /**
     * URL to redirect to after a command was handled
     *
     * @var string
     */
    protected $commandRedirectUrl;

    /**
     * Show a host or service
     */
    public function showAction()
    {
        $this->setAutorefreshInterval(10);
        $checkNowForm = new CheckNowCommandForm();
        $checkNowForm
            ->setObjects($this->object)
            ->handleRequest();
        $this->view->checkNowForm = $checkNowForm;
        if ( ! in_array((int) $this->object->state, array(0, 99))) {
            if ((bool) $this->object->acknowledged) {
                $removeAckForm = new RemoveAcknowledgementCommandForm();
                $removeAckForm
                    ->setObjects($this->object)
                    ->handleRequest();
                $this->view->removeAckForm = $removeAckForm;
            } else {
                $ackForm = new AcknowledgeProblemCommandForm();
                $ackForm
                    ->setObjects($this->object)
                    ->handleRequest();
                $this->view->ackForm = $ackForm;
            }
        }
        if (count($this->object->comments) > 0) {
            $delCommentForm = new DeleteCommentCommandForm();
            $delCommentForm
                ->setObjects($this->object)
                ->handleRequest();
            $this->view->delCommentForm = $delCommentForm;
        }
        if (count($this->object->downtimes > 0)) {
            $delDowntimeForm = new DeleteDowntimeCommandForm();
            $delDowntimeForm
                ->setObjects($this->object)
                ->handleRequest();
            $this->view->delDowntimeForm = $delDowntimeForm;
        }
        $toggleFeaturesForm = new ToggleObjectFeaturesCommandForm();
        $toggleFeaturesForm
            ->load($this->object)
            ->setObjects($this->object)
            ->handleRequest();
        $this->view->toggleFeaturesForm = $toggleFeaturesForm;
        $this->view->object = $this->object->populate();
    }

    /**
     * Handle a command form
     *
     * @param   ObjectsCommandForm $form
     *
     * @return  ObjectsCommandForm
     */
    protected function handleCommandForm(ObjectsCommandForm $form)
    {
        $form
            ->setObjects($this->object)
            ->setRedirectUrl(Url::fromPath($this->commandRedirectUrl)->setParams($this->params))
            ->handleRequest();
        $this->view->form = $form;
        $this->_helper->viewRenderer('partials/command-form', null, true);
        return $form;
    }

    /**
     * Acknowledge a problem
     */
    abstract public function acknowledgeProblemAction();

    /**
     * Add a comment
     */
    abstract public function addCommentAction();

    /**
     * Reschedule a check
     */
    abstract public function rescheduleCheckAction();

    /**
     * Schedule a downtime
     */
    abstract public function scheduleDowntimeAction();
}