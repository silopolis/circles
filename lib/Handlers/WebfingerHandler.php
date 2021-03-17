<?php

declare(strict_types=1);


/**
 * Circles - Bring cloud-users closer together.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2021
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Circles\Handlers;


use daita\MySmallPhpTools\Exceptions\SignatoryException;
use daita\MySmallPhpTools\Exceptions\SignatureException;
use daita\MySmallPhpTools\Traits\TArrayTools;
use OC\URLGenerator;
use OCA\Circles\AppInfo\Application;
use OCA\Circles\Service\ConfigService;
use OCA\Circles\Service\RemoteStreamService;
use OCP\Http\WellKnown\IHandler;
use OCP\Http\WellKnown\IRequestContext;
use OCP\Http\WellKnown\IResponse;
use OCP\Http\WellKnown\JrdResponse;
use OCP\IURLGenerator;


/**
 * Class WebfingerHandler
 *
 * @package OCA\Circles\Handlers
 */
class WebfingerHandler implements IHandler {


	use TArrayTools;


	/** @var URLGenerator */
	private $urlGenerator;

	/** @var RemoteStreamService */
	private $remoteStreamService;

	/** @var ConfigService */
	private $configService;


	/**
	 * WebfingerHandler constructor.
	 *
	 * @param IURLGenerator $urlGenerator
	 * @param RemoteStreamService $remoteStreamService
	 * @param ConfigService $configService
	 */
	public function __construct(
		IURLGenerator $urlGenerator, RemoteStreamService $remoteStreamService, ConfigService $configService
	) {
		$this->urlGenerator = $urlGenerator;
		$this->remoteStreamService = $remoteStreamService;
		$this->configService = $configService;
	}


	/**
	 * @param string $service
	 * @param IRequestContext $context
	 * @param IResponse|null $response
	 *
	 * @return IResponse|null
	 * @throws SignatureException
	 */
	public function handle(string $service, IRequestContext $context, ?IResponse $response): ?IResponse {
		if ($service !== 'webfinger') {
			return $response;
		}

		$subject = $this->get('resource', $context->getHttpRequest()->getParams());
		if ($subject !== Application::APP_SUBJECT) {
			return $response;
		}

		if (!($response instanceof JrdResponse)) {
			$response = new JrdResponse($subject);
		}

		try {
			$this->remoteStreamService->getAppSignatory();
		} catch (SignatoryException $e) {
			return $response;
		}

		$href = $this->configService->getFrontalPath();
		$info = [
			'app'     => Application::APP_ID,
			'name'    => Application::APP_NAME,
			'version' => $this->configService->getAppValue('installed_version'),
			'api'     => Application::APP_API
		];

		return $response->addLink(Application::APP_REL, 'application/json', $href, [], $info);
	}

}

