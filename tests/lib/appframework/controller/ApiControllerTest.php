<?php

/**
 * ownCloud - App Framework
 *
 * @author Bernhard Posselt
 * @copyright 2012 Bernhard Posselt <dev@bernhard-posselt.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCP\AppFramework;

use OC\AppFramework\Http\Request;


class ChildApiController extends ApiController {};


class ApiControllerTest extends \Test\TestCase {
    /** @var ChildApiController */
    protected $controller;

    public function testCors() {
        $request = new Request(
            ['server' => ['HTTP_ORIGIN' => 'test']],
            $this->getMock('\OCP\Security\ISecureRandom'),
            $this->getMock('\OCP\IConfig')
        );
        $this->controller = new ChildApiController('app', $request, 'verbs, verbs2',
            'headers, headers2', 100);

        $response = $this->controller->preflightedCors();

        $headers = $response->getHeaders();

        $this->assertEquals('test', $headers['Access-Control-Allow-Origin']);
        $this->assertEquals('VERBS, VERBS2', $headers['Access-Control-Allow-Methods']);
        $this->assertEquals('headers, headers2', $headers['Access-Control-Allow-Headers']);
        $this->assertEquals('false', $headers['Access-Control-Allow-Credentials']);
        $this->assertEquals(100, $headers['Access-Control-Max-Age']);
    }

}
