<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\AppInfo;

use OCA\Runbook\AppInfo\Application;
use OCA\Runbook\BackgroundJob\DueStepNotificationJob;
use OCA\Runbook\BackgroundJob\FilesCleanupRetryJob;
use OCA\Runbook\BackgroundJob\LegacyMigrationJob;
use OCA\Runbook\Dashboard\Widget;
use OCA\Runbook\Middleware\ExceptionMiddleware;
use OCA\Runbook\Notification\Notifier;
use OCA\Runbook\Search\Provider;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase {
	private function application(): Application {
		/** @var Application $application */
		$application = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();

		return $application;
	}

	public function testRegistersIntegrations(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects(self::once())->method('registerMiddleware')->with(ExceptionMiddleware::class);
		$context->expects(self::once())->method('registerNotifierService')->with(Notifier::class);
		$context->expects(self::once())->method('registerDashboardWidget')->with(Widget::class);
		$context->expects(self::once())->method('registerSearchProvider')->with(Provider::class);

		$this->application()->register($context);
	}

	public function testBootDoesNotTouchTheJobList(): void {
		$context = $this->createMock(IBootContext::class);
		$context->expects(self::never())->method('injectFn');

		$this->application()->boot($context);
	}

	public function testBackgroundJobsAreDeclaredInInfoXml(): void {
		$info = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		self::assertNotFalse($info);

		$jobs = [];
		foreach ($info->{'background-jobs'}->job ?? [] as $job) {
			$jobs[] = (string)$job;
		}

		self::assertSame([
			DueStepNotificationJob::class,
			FilesCleanupRetryJob::class,
			LegacyMigrationJob::class,
		], $jobs);
		foreach ($jobs as $job) {
			self::assertTrue(class_exists($job), $job . ' must exist');
		}
	}
}
