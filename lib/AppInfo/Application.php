<?php

declare(strict_types=1);

namespace OCA\Runbook\AppInfo;

use OCA\Runbook\Dashboard\Widget;
use OCA\Runbook\Middleware\ExceptionMiddleware;
use OCA\Runbook\Notification\Notifier;
use OCA\Runbook\Search\Provider;
use OCA\Runbook\Service\FilesRootProvider;
use OCA\Runbook\Service\NextcloudFilesRootProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'runbook';

	/**
	 * @param array<string, string> $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerMiddleware(ExceptionMiddleware::class);
		$context->registerNotifierService(Notifier::class);
		$context->registerDashboardWidget(Widget::class);
		$context->registerSearchProvider(Provider::class);
		$context->registerService(
			FilesRootProvider::class,
			static function (ContainerInterface $container): FilesRootProvider {
				/** @var FilesRootProvider $provider */
				$provider = $container->get(NextcloudFilesRootProvider::class);
				return $provider;
			},
		);
	}

	/**
	 * Background jobs are declared in appinfo/info.xml and registered by
	 * Nextcloud on install and upgrade, not on every request.
	 */
	public function boot(IBootContext $context): void {
	}
}
