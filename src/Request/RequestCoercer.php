<?php
/*
 * This file is part of the KleijnWeb\SwaggerBundle package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KleijnWeb\SwaggerBundle\Request;

use KleijnWeb\SwaggerBundle\Exception\UnsupportedException;
use KleijnWeb\SwaggerBundle\Request\ParameterCoercer;
use Symfony\Component\HttpFoundation\Request;
use KleijnWeb\SwaggerBundle\Exception\MalformedContentException;

/**
 * @author John Kleijn <john@kleijnweb.nl>
 */
class RequestCoercer
{
    /**
     * @param Request $request
     * @param array   $operationDefinition
     * @param mixed   $content
     *
     * @throws MalformedContentException
     * @throws UnsupportedException
     */
    public function coerceRequest(Request $request, array $operationDefinition, $content = null)
    {
        if (!isset($operationDefinition['parameters'])) {
            return;
        }
        $paramBagMapping = [
            'query'  => 'query',
            'path'   => 'attributes',
            'header' => 'headers'
        ];
        foreach ($operationDefinition['parameters'] as $paramDefinition) {
            $paramName = $paramDefinition['name'];

            if ($paramDefinition['in'] === 'body') {
                if ($content !== null) {
                    $request->attributes->set($paramName, $content);
                }

                continue;
            }

            if (!isset($paramBagMapping[$paramDefinition['in']])) {
                throw new UnsupportedException(
                    "Unsupported parameter 'in' value in definition '{$paramDefinition['in']}'"
                );
            }
            $paramBagName = $paramBagMapping[$paramDefinition['in']];
            if (!$request->$paramBagName->has($paramName)) {
                continue;
            }
            $request->attributes->set(
                $paramName,
                ParameterCoercer::coerceParameter(
                    $paramDefinition,
                    $request->$paramBagName->get($paramName)
                )
            );
        }
    }
}
