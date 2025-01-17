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
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\DataHubBundle\GraphQL\DataObjectType;

use GraphQL\Error\InvariantViolation;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\DataHubBundle\GraphQL\FieldcollectionDecriptor;
use Pimcore\Bundle\DataHubBundle\GraphQL\FieldHelper\DataObjectFieldHelper;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service;
use Pimcore\Bundle\DataHubBundle\GraphQL\Traits\ServiceTrait;
use Pimcore\Bundle\DataHubBundle\GraphQL\TypeInterface\Element;
use Pimcore\Cache\Runtime;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Fieldcollection\Data\AbstractData;
use Pimcore\Model\DataObject\Fieldcollection\Definition;

class PimcoreObjectType extends ObjectType
{
    use ServiceTrait;

    /**
     * @var string
     */
    protected $className;

    /**
     * @var int
     */
    protected $classId;

    protected static $skipOperators;

    protected $fields;


    /**
     * PimcoreObjectType constructor.
     * @param Service $graphQlService
     * @param string $className
     * @param string $classId
     * @param array $config
     * @param array $context
     */
    public function __construct(Service $graphQlService, string $className, $classId, $config = [], $context = [])
    {
        $this->className = $className;
        $this->classId = $classId;
        $this->name = $config['name'] = 'object_' . $className;
        $this->setGraphQLService($graphQlService);
        $config['interfaces'] = [Element::getInstance()];
        parent::__construct($config);
    }

    /**
     * @param $config
     * @param array $context
     */
    public function build($context = [])
    {
        // these are the system fields that are always available, maybe move some of them to FieldHelper so that they
        // are only visible if explicitly configured by the user
        $fields = ['id' =>
            ['name' => 'id',
                'type' => Type::id(),
            ],
            'classname' => [
                'type' => Type::string(),
            ],

        ];

        if ($context['clientname']) {

            /** @var $configurationItem Configuration */
            $configurationItem = $context['configuration'];

            $columns = $configurationItem->getQueryColumnConfig($this->className)['columns'];

            if ($columns) {
                $class = ClassDefinition::getById($this->classId);
                foreach ($columns as $column) {
                    if ($column['isOperator'] && self::$skipOperators) {
                        continue;
                    }


                    if (!$column["isOperator"] && is_array($column["attributes"]) && $column["attributes"]["dataType"] == "fieldcollections") {
                        $this->addFieldCollectionDefs($column, $class, $fields);

                    } else {
                        /** @var $fieldHelper DataObjectFieldHelper */
                        $fieldHelper = $this->getGraphQlService()->getObjectFieldHelper();
                        $result = $fieldHelper->getQueryFieldConfigFromConfig($column, $class);
                        if (is_array($result)) {
                            $fields[$result['key']] = $result['config'];
                        }
                    }
                }
            }
        }

        $this->fields = null;
        $this->config['fields'] = $fields;
    }

    public function addFieldCollectionDefs($column, ClassDefinition $class, &$fields)
    {

        $fieldname = $column["attributes"]["attribute"];
        /** @var $fieldDef ClassDefinition\Data\Fieldcollections */
        $fieldDef = $class->getFieldDefinition(($fieldname));
        $allowedFcs = $fieldDef->getAllowedTypes();

        /** @var $fieldHelper DataObjectFieldHelper */
        $fieldHelper = $this->getGraphQlService()->getObjectFieldHelper();

        $unionTypes = [];

        foreach ($allowedFcs as $allowedFcName) {

            $fcKey = "graphql_fieldcollection_" . $allowedFcName;
            if (Runtime::isRegistered($fcKey)) {
                $itemFcType = Runtime::get($fcKey);
            } else {
                $fcDef = Definition::getByKey($allowedFcName);
                $fcFields = [];


                $fcFieldDefs = $fcDef->getFieldDefinitions();

                foreach ($fcFieldDefs as $key => $fieldDef) {
                    $attrName = $fieldDef->getName();
                    $columnDesc = [
                        "isOperator" => false,
                        "attributes" => [
                            "attribute" => $attrName,
                            "label" => $fieldDef->getName(),
                            "dataType" => $fieldDef->getFieldtype()
                        ]
                    ];
                    $fcResult = $fieldHelper->getQueryFieldConfigFromConfig($columnDesc, $fcDef);
                    if ($fcResult) {
                        $fcFields[$fcResult['key']] = $fcResult['config'];
                    }
                }

                $fcLocalizedFields = $fcDef->getFieldDefinition("localizedfields");
                if ($fcLocalizedFields) {


                    $fcLocalizedFieldDefs = $fcLocalizedFields->getFieldDefinitions();

                    foreach ($fcLocalizedFieldDefs as $key => $fieldDef) {
                        $attrName = $fieldDef->getName();

                        $columnDesc = [
                            "isOperator" => false,
                            "attributes" => [
                                "attribute" => $attrName,
                                "label" => $fieldDef->getName(),
                                "dataType" => $fieldDef->getFieldtype()
                            ]
                        ];
                        $fcResult = $fieldHelper->getQueryFieldConfigFromConfig($columnDesc, $fcDef);
                        if ($fcResult) {
                            $fcFields[$fcResult['key']] = $fcResult['config'];
                        }
                    }
                }

                $typename = "fieldcollection_" . $allowedFcName;

                $itemFcType = new ObjectType([
                    "name" => $typename,
                    "fields" => $fcFields
                ]);

                Runtime::save($itemFcType, $fcKey);
            }

            $unionTypes[] = $itemFcType;
        }

        $unionname = "object_" . $this->className . "_" . $fieldname;

        $unionTypesConfig = [
            "name" => $unionname,
            "types" => $unionTypes
        ];

        $union = new FieldcollectionType($this->getGraphQlService(), $unionTypesConfig);


        $fields[$fieldname] =
            [
                "name" => $fieldname,
                "type" => Type::listOf($union),
                "resolve" => function ($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null) use ($fieldname) {
                    if ($value[$fieldname] instanceof Fieldcollection) {
                        $fcData = $value[$fieldname];
                        $lofItems = [];

                        $items = $fcData->getItems();
                        if ($items)
                            /** @var  $item AbstractData */
                            $idx = -1;

                        foreach ($items as $item) {
                            $idx++;
                            $data = new FieldcollectionDecriptor();
                            $data["__fcType"] = $item->getType();
                            $data["__fcFieldname"] = $fieldname;
                            $data["__itemIdx"] = $idx;
                            $data["id"] = $value["id"];
                            $fieldHelper = $this->getGraphQlService()->getObjectFieldHelper();
                            $fieldHelper->extractData($data, $item, $args, $context, $resolveInfo);
                            $lofItems[] = $data;
                        }
                    }
                    return $lofItems;
                }

            ];

    }

    /**
     * @return FieldDefinition[]
     *
     * @throws InvariantViolation
     */
    public function getFields()
    {
        if (null === $this->fields) {
            $fields = isset($this->config['fields']) ? $this->config['fields'] : [];
            $this->fields = FieldDefinition::defineFieldMap($this, $fields);
        }

        return $this->fields;
    }


}
