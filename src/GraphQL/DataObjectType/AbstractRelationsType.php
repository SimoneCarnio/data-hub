<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\DataHubBundle\GraphQL\DataObjectType;

use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\UnionType;
use Pimcore\Bundle\DataHubBundle\GraphQL\ClassTypeDefinitions;
use Pimcore\Bundle\DataHubBundle\GraphQL\DocumentType\DocumentType;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service;
use Pimcore\Bundle\DataHubBundle\GraphQL\Traits\ServiceTrait;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Fieldcollection\Definition;
use Pimcore\Model\Document;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

class AbstractRelationsType extends UnionType implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    use ServiceTrait;

    protected $class;

    /**
     * @var Data
     */
    protected $fieldDefinition;
    
    /**
     * AbstractRelationsType constructor.
     * @param Service $graphQlService
     * @param Data|null $fieldDefinition
     * @param null $class
     * @param array $config
     */
    public function __construct(Service $graphQlService, Data $fieldDefinition = null, $class = null, $config = [])
    {
        $this->class = $class;
        $this->fieldDefinition = $fieldDefinition;
        $this->setGraphQLService($graphQlService);
        if ($fieldDefinition && $class) {
            if ($class instanceof ClassDefinition) {
                $name = 'object_' . $class->getName() . '_' . $fieldDefinition->getName();
            } else if ($class instanceof Definition) {
                $name = 'fieldcollection_' . $class->getKey() . '_' . $fieldDefinition->getName();
            }
        }
        if ($fieldDefinition instanceof Data\AdvancedManyToManyRelation || $fieldDefinition instanceof Data\AdvancedManyToManyObjectRelation) {
            $name .= '_element';
        }

        $config['name'] = $name;
        parent::__construct($config);
    }

    /**
     * @return mixed
     */
    public function getClass()
    {
        return $this->class;
    }

    /**
     * @param mixed $class
     */
    public function setClass($class): void
    {
        $this->class = $class;
    }

    /**
     * @return array|\GraphQL\Type\Definition\ObjectType[]
     *
     * @throws \Exception
     */
    public function getTypes()
    {
        $fd = $this->getFieldDefinition();

        $types = [];

        if ($fd->getObjectsAllowed()) {
            if (!$fd->getClasses()) {
                $types = array_merge($types, array_values(ClassTypeDefinitions::getAll()));
            } else {
                $classes = $fd->getClasses();
                if (!is_array($classes)) {
                    $classes = [$classes];
                }
                foreach ($classes as $className) {
                    if (is_array($className)) {
                        $className = $className['classes'];
                    }
                    $types[] = ClassTypeDefinitions::get($className);
                }
            }
        }

        if (!$fd instanceof Data\ManyToManyObjectRelation) {
            if ($fd->getAssetsAllowed()) {
                $types[] = $this->getGraphQlService()->getAssetTypeDefinition("asset");
            }

            if ($fd->getDocumentsAllowed()) {
                /** @var DocumentType $documentUnionType */
                $documentUnionType = $this->getGraphQlService()->getDocumentTypeDefinition("document");
                $supportedDocumentTypes = $documentUnionType->getTypes();
                $types = array_merge($types, $supportedDocumentTypes);
            }
        }

        return $types;
    }

    /**
     * @inheritdoc
     */
    public function resolveType($element, $context, ResolveInfo $info)
    {
        if ($element) {
            if ($element['__elementType'] == 'object') {
                $type = ClassTypeDefinitions::get($element['__elementSubtype']);

                return $type;
            } else if ($element['__elementType'] == 'asset') {
                return  $this->getGraphQlService()->getAssetTypeDefinition("asset");
            } else if ($element['__elementType'] == 'document') {
                $document = Document::getById($element['id']);
                if ($document) {
                    $documentType = $document->getType();
                    $service = $this->getGraphQlService();
                    //TODO maybe catch unsupported types for now ?
                    $typeDefinition = $service->getDocumentTypeDefinition("document_" . $documentType);
                    return $typeDefinition;
                }
            }
        }

        return null;
    }

    /**
     * @return Data
     */
    public function getFieldDefinition(): Data
    {
        return $this->fieldDefinition;
    }
}
