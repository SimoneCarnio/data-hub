<?php
declare(strict_types=1);
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

namespace Pimcore\Bundle\DataHubBundle\GraphQL\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use Pimcore\Bundle\DataHubBundle\GraphQL\ElementDescriptor;
use Pimcore\Bundle\DataHubBundle\GraphQL\Traits\ServiceTrait;
use Pimcore\Bundle\DataHubBundle\PimcoreDataHubBundle;
use Pimcore\Bundle\DataHubBundle\WorkspaceHelper;
use Pimcore\Model\Asset;

/**
 * Class HotspotType
 * @package Pimcore\Bundle\DataHubBundle\GraphQL\Resolver
 */
class HotspotType
{

    use ServiceTrait;

    /**
     * @param null $value
     * @param array $args
     * @param $context
     * @param ResolveInfo|null $resolveInfo
     * @return array
     * @throws \Exception
     */
    public function resolveImage($value = null, $args = [], $context, ResolveInfo $resolveInfo = null)
    {
        if ($value instanceof ElementDescriptor) {

            $image = Asset::getById($value["id"]);
            if (!WorkspaceHelper::isAllowed($image, $context['configuration'], 'read')) {
                if (PimcoreDataHubBundle::getNotAllowedPolicy() == PimcoreDataHubBundle::NOT_ALLOWED_POLICY_EXCEPTION) {
                    throw new \Exception('not allowed to view asset');
                } else {
                    return null;
                }
            }


            $data = new ElementDescriptor();
            $fieldHelper = $this->getGraphQlService()->getAssetFieldHelper();
            $fieldHelper->extractData($data, $image, $args, $context, $resolveInfo);
            $data['data'] = $data['data'] ? base64_encode($data['data']) : null;
            $data['__elementSubtype'] = $image->getType();
            return $data;

        }
        return null;

    }

    /**
     * @param null $value
     * @param array $args
     * @param $context
     * @param ResolveInfo|null $resolveInfo
     * @return array
     * @throws \Exception
     */
    public function resolveCrop($value = null, $args = [], $context, ResolveInfo $resolveInfo = null)
    {
        return !empty($value['crop']) ? $value['crop'] : null;
    }

    /**
     * @param null $value
     * @param array $args
     * @param $context
     * @param ResolveInfo|null $resolveInfo
     * @return array
     * @throws \Exception
     */
    public function resolveHotspots($value = null, $args = [], $context, ResolveInfo $resolveInfo = null)
    {
        return !empty($value['hotspots']) ? $value['hotspots'] : null;
    }

    /**
     * @param null $value
     * @param array $args
     * @param $context
     * @param ResolveInfo|null $resolveInfo
     * @return array
     * @throws \Exception
     */
    public function resolveMarker($value = null, $args = [], $context, ResolveInfo $resolveInfo = null)
    {
        return !empty($value['marker']) ? $value['marker'] : null;
    }
}
