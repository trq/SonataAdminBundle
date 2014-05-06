<?php
/*
 * This file is part of the Sonata package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\AdminBundle\Admin;

use Doctrine\Common\Util\ClassUtils;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormView;
use Sonata\AdminBundle\Exception\NoValueException;
use Sonata\AdminBundle\Util\FormViewIterator;
use Sonata\AdminBundle\Util\FormBuilderIterator;
use Sonata\AdminBundle\Admin\BaseFieldDescription;

function traverseFieldHierarchy($fieldDescription)
{
    if (!$fieldDescription) {
        return;
    };

    $parent = $fieldDescription->getAdmin()->getParentFieldDescription();
    foreach (traverseFieldHierarchy($parent) as $field) {
        yield $field;
    };

    yield $fieldDescription;
}

function traverseFormBuilderDepthFirst($formBuilder)
{
    foreach (new FormBuilderIterator($formBuilder) as $base => $builder) {
        yield [$base, $builder];

        foreach (traverseFormBuilderDepthFirst($builder) as $pair) {
            list($name, $builder) = $pair;
            yield $pair;
        }
    };
}

class AdminHelper
{
    protected $pool;

    /**
     * @param Pool $pool
     */
    public function __construct(Pool $pool)
    {
        $this->pool = $pool;
    }

    /**
     * @throws \RuntimeException
     *
     * @param \Symfony\Component\Form\FormBuilder $formBuilder
     * @param string                              $elementId
     *
     * @return \Symfony\Component\Form\FormBuilder
     */
    public function getChildFormBuilder(FormBuilder $formBuilder, $elementId)
    {
        foreach (traverseFormBuilderDepthFirst($formBuilder) as $pair) {
            list($name, $builder) = $pair;

            if ($name == $elementId) {
                return $builder;
            }
        }

        return null;
    }

    /**
     * @param \Symfony\Component\Form\FormView $formView
     * @param string                           $elementId
     *
     * @return null|\Symfony\Component\Form\FormView
     */
    public function getChildFormView(FormView $formView, $elementId)
    {
        foreach (new \RecursiveIteratorIterator(new FormViewIterator($formView), \RecursiveIteratorIterator::SELF_FIRST) as $name => $formView) {
            if ($name === $elementId) {
                return $formView;
            }
        }

        return null;
    }

    /**
     * @deprecated
     *
     * @param string $code
     *
     * @return \Sonata\AdminBundle\Admin\AdminInterface
     */
    public function getAdmin($code)
    {
        return $this->pool->getInstance($code);
    }

    protected function getFormFieldDescription(AdminInterface $admin, array $elements)
    {
        if (count($elements) == 0) {
            return null;
        };

        while ( is_numeric($elementId = array_shift($elements)) ) {};

        $descriptions = $admin->getFormFieldDescriptions();
        $fieldDescription = $descriptions[$elementId];

        $recursionAdmin = $fieldDescription->getAssociationAdmin();

        if ($fieldDescription && $recursionAdmin) {
            $newFieldDescription = $this->getFormFieldDescription($recursionAdmin, $elements);
            if ($newFieldDescription) {
                return $newFieldDescription;
            }
        }

        return $fieldDescription;
    }

    /**
     * Note:
     *   This code is ugly, but there is no better way of doing it.
     *   For now the append form element action used to add a new row works
     *   only for direct FieldDescription (not nested one)
     *
     * @throws \RuntimeException
     *
     * @param \Sonata\AdminBundle\Admin\AdminInterface $admin
     * @param object                                   $subject
     * @param string                                   $elementId
     *
     * @return array
     */
    public function appendFormFieldElement(AdminInterface $admin, $subject, $elementId)
    {
        // retrieve the subject
        $formBuilder = $admin->getFormBuilder();

        $form = $formBuilder->getForm();
        $form->setData($subject);
        $form->bind($admin->getRequest());

        // get the field element
        $childElementPath = explode('_', $elementId);
        $childElementName = array_pop($childElementPath);

        // retrieve the FieldDescription
        $elements = explode('_', substr($elementId, strpos($elementId, '_') + 1));
        $fieldDescription = $this->getFormFieldDescription($admin, $elements);

        try {
            $value = $fieldDescription->getValue($form->getData());
        } catch (NoValueException $e) {
            $value = null;
        };

        // retrieve the posted data
        $data = $admin->getRequest()->get($formBuilder->getName());

        $childData =& $data;
        foreach ($elements as $pathElement) {
            $childData =& $childData[$pathElement];
        };

        $objectCount = count($value);
        $postCount   = count($childData);

        $fields = array_keys(
            $fieldDescription
            ->getAssociationAdmin()
            ->getFormFieldDescriptions()
        );

        // for now, not sure how to do that
        $value = array();
        foreach ($fields as $name) {
            $value[$name] = '';
        }

        $childObject = $form->getData();
        $hierarchyElements = $elements;
        foreach (traverseFieldHierarchy($fieldDescription) as $field) {
            if ($field == $fieldDescription) continue;

            $mapping = $field->getAssociationMapping();
            $method = sprintf(
                'get%s',
                $this->camelize($mapping['fieldName'])
            );

            $childObject = $childObject->$method();

            $hierarchyElements = array_slice($hierarchyElements, 1);
            if (is_numeric($hierarchyElements[0])) {
                $childObject = $childObject[(int)$hierarchyElements[0]];
                $hierarchyElements = array_slice($hierarchyElements, 1);
            };
        };

        // add new elements to the subject
        while ($objectCount < $postCount) {
            // append a new instance into the object
            $this->addNewInstance($childObject, $fieldDescription);
            $objectCount++;
        }

        $this->addNewInstance($childObject, $fieldDescription);
        $childData[] = $value;

        $finalForm = $admin->getFormBuilder()->getForm();
        $finalForm->setData($subject);

        // bind the data
        $finalForm->setData($form->getData());

        return array($fieldDescription, $finalForm);
    }

    /**
     * Add a new instance to the related FieldDescriptionInterface value
     *
     * @param object                                              $object
     * @param \Sonata\AdminBundle\Admin\FieldDescriptionInterface $fieldDescription
     *
     * @throws \RuntimeException
     */
    public function addNewInstance($object, FieldDescriptionInterface $fieldDescription)
    {
        $mapping  = $fieldDescription->getAssociationMapping();
        $method = sprintf('add%s', $this->camelize($mapping['fieldName']));

        if (!method_exists($object, $method)) {
            $method = rtrim($method, 's');

            if (!method_exists($object, $method)) {
                throw new \RuntimeException(
                    sprintf(
                        'Please add a method %s in the %s class!',
                        $method,
                        ClassUtils::getClass($object)
                    )
                );
            }
        }

        $object->$method(
            $fieldDescription->getAssociationAdmin()
                             ->getNewInstance()
        );
    }

    /**
     * Camelize a string
     *
     * @static
     *
     * @param string $property
     *
     * @return string
     */
    public function camelize($property)
    {
        return BaseFieldDescription::camelize($property);
    }
}
