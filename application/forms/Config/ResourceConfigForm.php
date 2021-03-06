<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Form\Config;

use InvalidArgumentException;
use Icinga\Web\Request;
use Icinga\Web\Notification;
use Icinga\Form\ConfigForm;
use Icinga\Form\Config\Resource\DbResourceForm;
use Icinga\Form\Config\Resource\FileResourceForm;
use Icinga\Form\Config\Resource\LdapResourceForm;
use Icinga\Form\Config\Resource\LivestatusResourceForm;
use Icinga\Application\Platform;
use Icinga\Exception\ConfigurationError;

class ResourceConfigForm extends ConfigForm
{
    /**
     * Initialize this form
     */
    public function init()
    {
        $this->setName('form_config_resource');
        $this->setSubmitLabel(t('Save Changes'));
    }

    /**
     * Return a form object for the given resource type
     *
     * @param   string      $type      The resource type for which to return a form
     *
     * @return  Form
     */
    public function getResourceForm($type)
    {
        if ($type === 'db') {
            return new DbResourceForm();
        } elseif ($type === 'ldap') {
            return new LdapResourceForm();
        } elseif ($type === 'livestatus') {
            return new LivestatusResourceForm();
        } elseif ($type === 'file') {
            return new FileResourceForm();
        } else {
            throw new InvalidArgumentException(sprintf(t('Invalid resource type "%s" provided'), $type));
        }
    }

    /**
     * Add a particular resource
     *
     * The backend to add is identified by the array-key `name'.
     *
     * @param   array   $values             The values to extend the configuration with
     *
     * @return  self
     *
     * @thrwos  InvalidArgumentException    In case the resource does already exist
     */
    public function add(array $values)
    {
        $name = isset($values['name']) ? $values['name'] : '';
        if (! $name) {
            throw new InvalidArgumentException(t('Resource name missing'));
        } elseif ($this->config->{$name} !== null) {
            throw new InvalidArgumentException(t('Resource already exists'));
        }

        unset($values['name']);
        $this->config->{$name} = $values;
        return $this;
    }

    /**
     * Edit a particular resource
     *
     * @param   string      $name           The name of the resource to edit
     * @param   array       $values         The values to edit the configuration with
     *
     * @return  array                       The edited configuration
     *
     * @throws  InvalidArgumentException    In case the resource does not exist
     */
    public function edit($name, array $values)
    {
        if (! $name) {
            throw new InvalidArgumentException(t('Old resource name missing'));
        } elseif (! ($newName = isset($values['name']) ? $values['name'] : '')) {
            throw new InvalidArgumentException(t('New resource name missing'));
        } elseif (($resourceConfig = $this->config->get($name)) === null) {
            throw new InvalidArgumentException(t('Unknown resource provided'));
        }

        unset($values['name']);
        unset($this->config->{$name});
        $this->config->{$newName} = array_merge($resourceConfig->toArray(), $values);
        return $this->config->{$newName};
    }

    /**
     * Remove a particular resource
     *
     * @param   string      $name           The name of the resource to remove
     *
     * @return  array                       The removed resource configuration
     *
     * @throws  InvalidArgumentException    In case the resource does not exist
     */
    public function remove($name)
    {
        if (! $name) {
            throw new InvalidArgumentException(t('Resource name missing'));
        } elseif (($resourceConfig = $this->config->get($name)) === null) {
            throw new InvalidArgumentException(t('Unknown resource provided'));
        }

        unset($this->config->{$name});
        return $resourceConfig;
    }

    /**
     * Add or edit a resource and save the configuration
     *
     * Performs a connectivity validation using the submitted values. A checkbox is
     * added to the form to skip the check if it fails and redirection is aborted.
     *
     * @see Form::onSuccess()
     */
    public function onSuccess(Request $request)
    {
        if (($el = $this->getElement('force_creation')) === null || false === $el->isChecked()) {
            $resourceForm = $this->getResourceForm($this->getElement('type')->getValue());
            if (method_exists($resourceForm, 'isValidResource') && false === $resourceForm->isValidResource($this)) {
                $this->addElement($this->getForceCreationCheckbox());
                return false;
            }
        }

        $resource = $request->getQuery('resource');
        try {
            if ($resource === null) { // create new resource
                $this->add($this->getValues());
                $message = t('Resource "%s" has been successfully created');
            } else { // edit existing resource
                $this->edit($resource, $this->getValues());
                $message = t('Resource "%s" has been successfully changed');
            }
        } catch (InvalidArgumentException $e) {
            Notification::error($e->getMessage());
            return;
        }

        if ($this->save()) {
            Notification::success(sprintf($message, $this->getElement('name')->getValue()));
        } else {
            return false;
        }
    }

    /**
     * Populate the form in case a resource is being edited
     *
     * @see Form::onRequest()
     *
     * @throws  ConfigurationError      In case the backend name is missing in the request or is invalid
     */
    public function onRequest(Request $request)
    {
        $resource = $request->getQuery('resource');
        if ($resource !== null) {
            if ($resource === '') {
                throw new ConfigurationError(t('Resource name missing'));
            } elseif (false === isset($this->config->{$resource})) {
                throw new ConfigurationError(t('Unknown resource provided'));
            }

            $configValues = $this->config->{$resource}->toArray();
            $configValues['name'] = $resource;
            $this->populate($configValues);
        }
    }

    /**
     * Return a checkbox to be displayed at the beginning of the form
     * which allows the user to skip the connection validation
     *
     * @return  Zend_Form_Element
     */
    protected function getForceCreationCheckbox()
    {
        return $this->createElement(
            'checkbox',
            'force_creation',
            array(
                'order'         => 0,
                'ignore'        => true,
                'label'         => t('Force Changes'),
                'description'   => t('Check this box to enforce changes without connectivity validation')
            )
        );
    }

    /**
     * @see Form::createElemeents()
     */
    public function createElements(array $formData)
    {
        $resourceType = isset($formData['type']) ? $formData['type'] : 'db';

        $resourceTypes = array(
            'file'          => t('File'),
            'livestatus'    => 'Livestatus',
        );
        if ($resourceType === 'ldap' || Platform::extensionLoaded('ldap')) {
            $resourceTypes['ldap'] = 'LDAP';
        }
        if ($resourceType === 'db' || Platform::extensionLoaded('mysql') || Platform::extensionLoaded('pgsql')) {
            $resourceTypes['db'] = t('SQL Database');
        }

        $this->addElement(
            'text',
            'name',
            array(
                'required'      => true,
                'label'         => t('Resource Name'),
                'description'   => t('The unique name of this resource')
            )
        );
        $this->addElement(
            'select',
            'type',
            array(
                'required'          => true,
                'autosubmit'        => true,
                'label'             => t('Resource Type'),
                'description'       => t('The type of resource'),
                'multiOptions'      => $resourceTypes,
                'value'             => $resourceType
            )
        );

        if (isset($formData['force_creation']) && $formData['force_creation']) {
            // In case another error occured and the checkbox was displayed before
            $this->addElement($this->getForceCreationCheckbox());
        }

        $this->addElements($this->getResourceForm($resourceType)->createElements($formData)->getElements());
    }
}
