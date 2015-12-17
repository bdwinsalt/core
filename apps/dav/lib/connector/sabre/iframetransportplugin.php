<?php
/**
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\DAV\Connector\Sabre;

use Sabre\DAV\IFile;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\DAV\Exception\BadRequest;

/**
 * Plugin to receive Webdav PUT through POST,
 * mostly used as a workaround for browsers that
 * do not support PUT upload.
 */
class IFrameTransportPlugin extends \Sabre\DAV\ServerPlugin {

	/**
	 * @var \Sabre\DAV\Server $server
	 */
	private $server;

	/**
	 * This initializes the plugin.
	 *
	 * @param \Sabre\DAV\Server $server
	 * @return void
	 */
	public function initialize(\Sabre\DAV\Server $server) {

		$this->server = $server;
		$this->server->on('method:POST', [$this, 'handlePost']);
	}

	/**
	 * POST operation
	 *
	 * @param RequestInterface $request request object
	 * @param ResponseInterface $response response object
	 * @return null|false
	 */
	public function handlePost(RequestInterface $request, ResponseInterface $response) {
		try {
			return $this->processUpload($request, $response);
		} catch (\Sabre\DAV\Exception $e) {
			$this->respond($response, $e->getHTTPCode(), [
				'message' => $e->getMessage()
			]);
			return false;
		}
	}

	/**
	 * Send response in JSON format
	 *
	 * @param ResponseInterface $response response object
	 * @param int $status status code
	 * @param string|array $data response data
	 */
	private function respond(ResponseInterface $response, $status, $data) {
		// IE needs this content type
		$response->setHeader('Content-Type', 'text/plain');
		$response->setStatus(200);
		if (!is_string($data)) {
			$data = json_encode($data);
		}
		$response->setBody(json_encode([
			'status' => $status,
			'data' => $data,
		]));
	}

	/**
	 * Process upload
	 *
	 * @param RequestInterface $request request object
	 * @param ResponseInterface $response response object
	 * @return null|false
	 */
	private function processUpload(RequestInterface $request, ResponseInterface $response) {
		$queryParams = $request->getQueryParameters();

		if (!isset($queryParams['_method'])) {
			return null;
		}

		$method = $queryParams['_method'];
		if ($method !== 'PUT') {
			return null;
		}

		$contentType = $request->getHeader('Content-Type');
		list($contentType) = explode(';', $contentType);
		if ($contentType !== 'application/x-www-form-urlencoded'
			&& $contentType !== 'multipart/form-data'
		) {
			return null;
		}

		if (!isset($_FILES['files'])) {
			return null;
		}

		// TODO: move this to another plugin ?
		if (!\OC::$server->getRequest()->passesCSRFCheck()) {
			throw new BadRequest('Invalid CSRF token');
		}

		if ($_FILES) {
			$file = current($_FILES);
		} else {
			return null;
		}

		if ($file['error'][0] !== 0) {
			throw new BadRequest('Error during upload, code ' . $file['error'][0]);
		}

		if (!is_uploaded_file($file['tmp_name'][0])) {
			return null;
		}

		if (count($file['tmp_name']) > 1) {
			throw new BadRequest('Only a single file can be uploaded');
		}

		$postData = $request->getPostData();
		if (isset($postData['headers'])) {
			$headers = json_decode($postData['headers'], true);

			// copy safe headers into the request
			$allowedHeaders = [
				'If',
				'If-Match',
				'If-None-Match',
				'If-Modified-Since',
				'If-Unmodified-Since',
			];

			foreach ($allowedHeaders as $allowedHeader) {
				if (isset($headers[$allowedHeader])) {
					$request->setHeader($allowedHeader, $headers[$allowedHeader]);
				}
			}

			// check preconditions again with new headers
			$this->server->checkPreconditions($request, $response);
		}

		// MEGAHACK, because the Sabre File impl reads this property directly
		$_SERVER['CONTENT_LENGTH'] = $file['size'][0];

		$etag = null;

		$tmpFile = $file['tmp_name'][0];
		$resource = fopen($tmpFile, 'r');

		$path = $request->getPath();
		if ($this->server->tree->nodeExists($path)) {
			$node = $this->server->tree->getNodeForPath($path);

			// If the node is a collection, we'll deny it
			if (!($node instanceof IFile)) throw new Exception\Conflict('PUT is not allowed on non-files.');

			$result = $this->server->updateFile($path, $resource, $etag);
		} else {
			$result = $this->server->createFile($path, $resource, $etag);
		}
		fclose($resource);
		unlink($tmpFile);

		if (!$result) {
			return false;
		}

		$this->respond($response, 201, [
			'etag' => $etag
		]);

		return false;
	}

}
