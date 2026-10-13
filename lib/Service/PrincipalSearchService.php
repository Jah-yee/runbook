<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Enum\PrincipalType;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IShare;

/**
 * User and group search for the ACL editors, assignments and USER steps.
 *
 * Regular users search through the Nextcloud sharee search, which applies the
 * instance's sharing and enumeration policy (enumeration limits, "only share
 * with group members", excluded groups, phone-book matching, group sharing).
 * Runbook must never reveal more accounts than the Files share dialog does.
 *
 * Administrators search the user and group backends directly: the
 * administration settings need every group, and administrators can already
 * list all accounts in Nextcloud.
 */
class PrincipalSearchService {
	private const MAX_RESULTS = 50;

	public function __construct(
		private readonly ISearch $collaboratorSearch,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {
	}

	/**
	 * @return list<array{principalType: string, principalId: string, displayName: string}>
	 */
	public function search(string $search, int $limit): array {
		$search = trim($search);
		if ($search === '') {
			return [];
		}

		$limit = max(1, min($limit, self::MAX_RESULTS));
		$user = $this->userSession->getUser();
		if ($user !== null && $this->groupManager->isAdmin($user->getUID())) {
			return $this->searchUnrestricted($search, $limit);
		}

		/** @var array{0: array<string, mixed>, 1: bool} $response */
		$response = $this->collaboratorSearch->search(
			$search,
			[IShare::TYPE_USER, IShare::TYPE_GROUP],
			false,
			$limit,
			0,
		);
		$result = $response[0];
		$exact = $this->arrayValue($result, 'exact');

		$principals = [];
		if ($user !== null && $this->matches($search, $user->getUID(), $user->getDisplayName())) {
			// The sharee search always drops the current user.
			$this->add($principals, PrincipalType::User, $user->getUID(), $user->getDisplayName());
		}
		$this->addSharees($principals, PrincipalType::User, $this->arrayValue($exact, 'users'));
		$this->addSharees($principals, PrincipalType::User, $this->arrayValue($result, 'users'));
		$this->addSharees($principals, PrincipalType::Group, $this->arrayValue($exact, 'groups'));
		$this->addSharees($principals, PrincipalType::Group, $this->arrayValue($result, 'groups'));

		return array_values($principals);
	}

	/**
	 * @return list<array{principalType: string, principalId: string, displayName: string}>
	 */
	private function searchUnrestricted(string $search, int $limit): array {
		$principals = [];
		foreach ($this->userManager->searchDisplayName($search, $limit) as $user) {
			$this->add($principals, PrincipalType::User, $user->getUID(), $user->getDisplayName());
		}
		foreach ($this->groupManager->search($search, $limit) as $group) {
			$this->add($principals, PrincipalType::Group, $group->getGID(), $group->getDisplayName());
		}

		return array_values($principals);
	}

	/**
	 * @param array<string, array{principalType: string, principalId: string, displayName: string}> $principals
	 * @param array<array-key, mixed> $entries
	 */
	private function addSharees(array &$principals, PrincipalType $type, array $entries): void {
		/** @psalm-suppress MixedAssignment entries come from an untyped core API */
		foreach ($entries as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$id = $this->stringValue($this->arrayValue($entry, 'value'), 'shareWith');
			if ($id === '') {
				continue;
			}
			$label = $this->stringValue($entry, 'label');
			$this->add($principals, $type, $id, $label !== '' ? $label : $id);
		}
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @return array<array-key, mixed>
	 */
	private function arrayValue(array $data, string $key): array {
		return isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	private function stringValue(array $data, string $key): string {
		return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
	}

	/**
	 * @param array<string, array{principalType: string, principalId: string, displayName: string}> $principals
	 */
	private function add(array &$principals, PrincipalType $type, string $id, string $displayName): void {
		$key = $type->value . ':' . $id;
		if (isset($principals[$key])) {
			return;
		}

		$principals[$key] = [
			'principalType' => $type->value,
			'principalId' => $id,
			'displayName' => $displayName,
		];
	}

	private function matches(string $search, string $uid, string $displayName): bool {
		return mb_stripos($uid, $search) !== false || mb_stripos($displayName, $search) !== false;
	}
}
