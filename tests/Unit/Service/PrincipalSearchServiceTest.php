<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Service\PrincipalSearchService;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PrincipalSearchServiceTest extends TestCase {
	/** @var ISearch&MockObject */
	private ISearch $search;
	/** @var IUserManager&MockObject */
	private IUserManager $userManager;
	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;
	/** @var list<string> */
	private array $admins = [];

	protected function setUp(): void {
		$this->search = $this->createMock(ISearch::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturnCallback(
			fn (string $uid): bool => in_array($uid, $this->admins, true),
		);
	}

	private function serviceFor(string $uid, string $displayName = ''): PrincipalSearchService {
		$user = $this->userMock($uid, $displayName === '' ? $uid : $displayName);

		/** @var IUserSession&MockObject $session */
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new PrincipalSearchService($this->search, $this->userManager, $this->groupManager, $session);
	}

	private function userMock(string $uid, string $displayName): IUser {
		/** @var IUser&MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);

		return $user;
	}

	private function groupMock(string $gid, string $displayName): IGroup {
		/** @var IGroup&MockObject $group */
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('getDisplayName')->willReturn($displayName);

		return $group;
	}

	/**
	 * @param list<array{0: string, 1: string}> $users
	 * @param list<array{0: string, 1: string}> $groups
	 * @param list<array{0: string, 1: string}> $exactUsers
	 * @return array<string, mixed>
	 */
	private function sharee(array $users, array $groups = [], array $exactUsers = []): array {
		$entry = static fn (int $type): \Closure => static fn (array $row): array => [
			'label' => $row[1],
			'value' => ['shareType' => $type, 'shareWith' => $row[0]],
		];

		return [
			'exact' => [
				'users' => array_map($entry(IShare::TYPE_USER), $exactUsers),
				'groups' => [],
			],
			'users' => array_map($entry(IShare::TYPE_USER), $users),
			'groups' => array_map($entry(IShare::TYPE_GROUP), $groups),
		];
	}

	public function testRegularUserSearchGoesThroughTheShareeSearch(): void {
		$this->search->expects(self::once())
			->method('search')
			->with('bo', [IShare::TYPE_USER, IShare::TYPE_GROUP], false, 25, 0)
			->willReturn([$this->sharee([['bob', 'Bob']], [['board', 'Board']], [['bo', 'Bo Exact']]), false]);
		$this->userManager->expects(self::never())->method('searchDisplayName');
		$this->groupManager->expects(self::never())->method('search');

		$result = $this->serviceFor('alice')->search('bo', 25);

		self::assertSame([
			['principalType' => 'USER', 'principalId' => 'bo', 'displayName' => 'Bo Exact'],
			['principalType' => 'USER', 'principalId' => 'bob', 'displayName' => 'Bob'],
			['principalType' => 'GROUP', 'principalId' => 'board', 'displayName' => 'Board'],
		], $result);
	}

	public function testUsersHiddenByTheShareeSearchAreNotReturned(): void {
		// The sharee search applies enumeration and group restrictions and
		// returns nothing for this term; the raw user backend must not be used.
		$this->search->method('search')->willReturn([$this->sharee([]), false]);
		$this->userManager->expects(self::never())->method('searchDisplayName');

		self::assertSame([], $this->serviceFor('alice')->search('admin', 25));
	}

	public function testCurrentUserIsIncludedWhenMatching(): void {
		// The sharee search always drops the current user, but a user may
		// legitimately assign a step to themselves.
		$this->search->method('search')->willReturn([$this->sharee([['alicia', 'Alicia']]), false]);

		$result = $this->serviceFor('alice', 'Alice Smith')->search('ali', 25);

		self::assertSame([
			['principalType' => 'USER', 'principalId' => 'alice', 'displayName' => 'Alice Smith'],
			['principalType' => 'USER', 'principalId' => 'alicia', 'displayName' => 'Alicia'],
		], $result);
	}

	public function testCurrentUserIsNotAddedWhenNotMatching(): void {
		$this->search->method('search')->willReturn([$this->sharee([['bob', 'Bob']]), false]);

		$result = $this->serviceFor('alice', 'Alice Smith')->search('bob', 25);

		self::assertSame([
			['principalType' => 'USER', 'principalId' => 'bob', 'displayName' => 'Bob'],
		], $result);
	}

	public function testDuplicatesAreRemoved(): void {
		$this->search->method('search')->willReturn([$this->sharee([['bob', 'Bob']], [], [['bob', 'Bob']]), false]);

		self::assertCount(1, $this->serviceFor('alice')->search('bob', 25));
	}

	public function testAdministratorsSearchAllUsersAndGroups(): void {
		// Administration settings need every group, regardless of the
		// administrator's own sharing restrictions.
		$this->admins = ['root'];
		$this->search->expects(self::never())->method('search');
		$this->userManager->method('searchDisplayName')->with('en', 25)
			->willReturn([$this->userMock('ben', 'Ben')]);
		$this->groupManager->method('search')->with('en', 25)
			->willReturn([$this->groupMock('engineering', 'Engineering')]);

		self::assertSame([
			['principalType' => 'USER', 'principalId' => 'ben', 'displayName' => 'Ben'],
			['principalType' => 'GROUP', 'principalId' => 'engineering', 'displayName' => 'Engineering'],
		], $this->serviceFor('root')->search('en', 25));
	}

	public function testEmptySearchReturnsNothing(): void {
		$this->search->expects(self::never())->method('search');

		self::assertSame([], $this->serviceFor('alice')->search('   ', 25));
	}

	public function testLimitIsBounded(): void {
		$this->search->expects(self::once())
			->method('search')
			->with('bob', self::anything(), false, 50, 0)
			->willReturn([$this->sharee([]), false]);

		$this->serviceFor('alice')->search('bob', 1000);
	}

	public function testMalformedShareeEntriesAreIgnored(): void {
		$this->search->method('search')->willReturn([[
			'users' => [
				['label' => 'No value'],
				['label' => 'Bob', 'value' => ['shareType' => IShare::TYPE_USER, 'shareWith' => 'bob']],
			],
			'groups' => 'not-a-list',
		], false]);

		self::assertSame([
			['principalType' => 'USER', 'principalId' => 'bob', 'displayName' => 'Bob'],
		], $this->serviceFor('alice')->search('bob', 25));
	}
}
