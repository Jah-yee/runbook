<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Notification;

use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunMapper;
use OCA\Runbook\Notification\Notifier;
use OCA\Runbook\Service\NotificationService;
use OCA\Runbook\Service\RunAccessService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase {
	/** @var IFactory&MockObject */
	private IFactory $l10nFactory;
	/** @var IUserManager&MockObject */
	private IUserManager $userManager;
	/** @var IURLGenerator&MockObject */
	private IURLGenerator $url;
	/** @var RunMapper&MockObject */
	private RunMapper $runs;
	/** @var RunAccessService&MockObject */
	private RunAccessService $access;
	/** @var list<int> */
	private array $deletedRuns = [];
	/** @var list<string> "runId:uid" pairs whose access was revoked */
	private array $revoked = [];

	protected function setUp(): void {
		/** @var IL10N&MockObject $l10n */
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(function (string $text, array $parameters = []): string {
			foreach ($parameters as $key => $value) {
				$text = str_replace('{' . $key . '}', (string)$value, $text);
			}

			return $text;
		});

		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->l10nFactory->method('get')->willReturn($l10n);

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('getDisplayName')->willReturnCallback(
			static fn (string $uid): string => $uid === 'bob' ? 'Bob' : $uid,
		);

		$this->url = $this->createMock(IURLGenerator::class);
		$this->url->method('linkToRoute')->willReturn('/index.php/apps/runbook/');
		$this->url->method('linkToRouteAbsolute')->willReturn('https://cloud.example.com/index.php/apps/runbook/');

		$this->runs = $this->createMock(RunMapper::class);
		$this->runs->method('find')->willReturnCallback(function (int $id): Run {
			if (in_array($id, $this->deletedRuns, true)) {
				throw new DoesNotExistException('run not found');
			}
			$run = new Run();
			$run->setId($id);
			$run->setOwner('alice');

			return $run;
		});
		$this->access = $this->createMock(RunAccessService::class);
		$this->access->method('canView')->willReturnCallback(
			fn (Run $run, string $uid): bool => !in_array($run->getId() . ':' . $uid, $this->revoked, true),
		);
	}

	private function notifier(): Notifier {
		return new Notifier($this->l10nFactory, $this->url, $this->userManager, $this->runs, $this->access);
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array{parsed: string, rich: string, richParams: array<string, array<string, string>>, link: string}
	 */
	private function prepare(string $app, string $subject, array $params): array {
		$captured = ['parsed' => '', 'rich' => '', 'richParams' => [], 'link' => ''];

		/** @var INotification&MockObject $notification */
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getUser')->willReturn('bob');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('setParsedSubject')->willReturnCallback(function (string $value) use (&$captured, $notification): INotification {
			$captured['parsed'] = $value;

			return $notification;
		});
		$notification->method('setRichSubject')->willReturnCallback(function (string $value, array $parameters = []) use (&$captured, $notification): INotification {
			$captured['rich'] = $value;
			$captured['richParams'] = $parameters;

			return $notification;
		});
		$notification->method('setLink')->willReturnCallback(function (string $value) use (&$captured, $notification): INotification {
			$captured['link'] = $value;

			return $notification;
		});

		$this->notifier()->prepare($notification, 'en');

		return $captured;
	}

	public function testIdentity(): void {
		self::assertSame('runbook', $this->notifier()->getID());
		self::assertSame('Runbook', $this->notifier()->getName());
	}

	public function testStepAssignedSubjectAndLink(): void {
		$result = $this->prepare('runbook', NotificationService::SUBJECT_STEP_ASSIGNED, [
			'runId' => 5,
			'stepId' => 9,
			'runTitle' => 'Release',
			'stepTitle' => 'Deploy',
			'actor' => 'alice',
		]);

		self::assertStringContainsString('Deploy', $result['parsed']);
		self::assertStringContainsString('Release', $result['parsed']);
		self::assertSame('https://cloud.example.com/index.php/apps/runbook/#/run/5/step/9', $result['link']);
		self::assertStringContainsString('{step}', $result['rich']);
		self::assertSame('Deploy', $result['richParams']['step']['name']);
	}

	public function testRunAssignedSubjectAndLink(): void {
		$result = $this->prepare('runbook', NotificationService::SUBJECT_RUN_ASSIGNED, [
			'runId' => 7,
			'runTitle' => 'Maintenance',
			'actor' => 'alice',
		]);

		self::assertStringContainsString('Maintenance', $result['parsed']);
		self::assertSame('https://cloud.example.com/index.php/apps/runbook/#/run/7', $result['link']);
	}

	public function testMentionSubjectIncludesActor(): void {
		$result = $this->prepare('runbook', NotificationService::SUBJECT_MENTIONED, [
			'runId' => 3,
			'stepId' => 4,
			'runTitle' => 'Onboarding',
			'stepTitle' => 'Create account',
			'commentId' => 11,
			'actor' => 'bob',
		]);

		self::assertStringContainsString('bob', $result['parsed']);
		self::assertStringContainsString('Create account', $result['parsed']);
		self::assertSame('https://cloud.example.com/index.php/apps/runbook/#/run/3/step/4', $result['link']);
		self::assertSame('Bob', $result['richParams']['actor']['name']);
	}

	public function testDueAndOverdueSubjects(): void {
		$due = $this->prepare('runbook', NotificationService::SUBJECT_STEP_DUE, [
			'runId' => 1,
			'stepId' => 2,
			'runTitle' => 'Run',
			'stepTitle' => 'Step',
			'actor' => null,
		]);
		self::assertStringContainsString('due tomorrow', $due['parsed']);

		$overdue = $this->prepare('runbook', NotificationService::SUBJECT_STEP_OVERDUE, [
			'runId' => 1,
			'stepId' => 2,
			'runTitle' => 'Run',
			'stepTitle' => 'Step',
			'actor' => null,
		]);
		self::assertStringContainsString('overdue', $overdue['parsed']);
		self::assertSame('https://cloud.example.com/index.php/apps/runbook/#/run/1/step/2', $overdue['link']);
	}

	public function testUnknownAppIsRejected(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->prepare('other-app', NotificationService::SUBJECT_RUN_ASSIGNED, []);
	}

	public function testUnknownSubjectIsRejected(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->prepare('runbook', 'totally_unknown', []);
	}

	public function testNotificationForDeletedRunIsWithdrawn(): void {
		$this->deletedRuns = [5];

		$this->expectException(AlreadyProcessedException::class);
		$this->prepare('runbook', NotificationService::SUBJECT_STEP_ASSIGNED, [
			'runId' => 5,
			'stepId' => 9,
			'runTitle' => 'Release',
			'stepTitle' => 'Deploy',
			'actor' => 'alice',
		]);
	}

	public function testNotificationIsWithdrawnWhenRecipientLostAccess(): void {
		$this->revoked = ['7:bob'];

		$this->expectException(AlreadyProcessedException::class);
		$this->prepare('runbook', NotificationService::SUBJECT_RUN_ASSIGNED, [
			'runId' => 7,
			'runTitle' => 'Maintenance',
			'actor' => 'alice',
		]);
	}

	public function testNotificationWithoutRunIsWithdrawn(): void {
		$this->expectException(AlreadyProcessedException::class);
		$this->prepare('runbook', NotificationService::SUBJECT_RUN_ASSIGNED, [
			'runTitle' => 'Maintenance',
			'actor' => 'alice',
		]);
	}
}
