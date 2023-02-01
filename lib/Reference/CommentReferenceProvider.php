<?php
/**
 * @copyright Copyright (c) 2022 Julien Veyssier <eneiluj@posteo.net>
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Deck\Reference;

use OCA\Deck\AppInfo\Application;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\Attachment;
use OCA\Deck\Db\Label;
use OCA\Deck\Model\CardDetails;
use OCA\Deck\NotFoundException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\CommentService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\IReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\Comments\ICommentsManager;
use OCP\IL10N;
use OCP\IURLGenerator;

class CommentReferenceProvider implements IReferenceProvider {
	private CardService $cardService;
	private IURLGenerator $urlGenerator;
	private BoardService $boardService;
	private StackService $stackService;
	private ?string $userId;
	private IL10N $l10n;
	private CommentService $commentService;

	public function __construct(CardService $cardService,
								BoardService $boardService,
								StackService $stackService,
								CommentService $commentService,
								IURLGenerator $urlGenerator,
								IL10N $l10n,
								?string $userId) {
		$this->cardService = $cardService;
		$this->urlGenerator = $urlGenerator;
		$this->boardService = $boardService;
		$this->stackService = $stackService;
		$this->userId = $userId;
		$this->l10n = $l10n;
		$this->commentService = $commentService;
	}

	/**
	 * @inheritDoc
	 */
	public function matchReference(string $referenceText): bool {
		$start = $this->urlGenerator->getAbsoluteURL('/apps/' . Application::APP_ID);
		$startIndex = $this->urlGenerator->getAbsoluteURL('/index.php/apps/' . Application::APP_ID);

		// link example: https://nextcloud.local/index.php/apps/deck/#/board/2/card/11/comments/501
		$noIndexMatch = preg_match('/^' . preg_quote($start, '/') . '\/#\/board\/[0-9]+\/card\/[0-9]+\/comments\/\d+$/', $referenceText) === 1;
		$indexMatch = preg_match('/^' . preg_quote($startIndex, '/') . '\/#\/board\/[0-9]+\/card\/[0-9]+\/comments\/\d+$/', $referenceText) === 1;

		return $noIndexMatch || $indexMatch;
	}

	/**
	 * @inheritDoc
	 */
	public function resolveReference(string $referenceText): ?IReference {
		if ($this->matchReference($referenceText)) {
			$ids = $this->getIds($referenceText);
			if ($ids !== null) {
				[$boardId, $cardId, $commentId] = $ids;

				$card = $this->cardService->find($cardId)->jsonSerialize();
				$board = $this->boardService->find($boardId)->jsonSerialize();
				$stack = $this->stackService->find((int) $card['stackId'])->jsonSerialize();
				$card = $this->sanitizeSerializedCard($card);
				$board = $this->sanitizeSerializedBoard($board);
				$stack = $this->sanitizeSerializedStack($stack);

				$comment = $this->commentService->getFormatted($cardId, $commentId);

				/** @var IReference $reference */
				$reference = new Reference($referenceText);
				$reference->setTitle($comment['message']);
				$boardOwnerDisplayName = $board['owner']['displayname'] ?? $board['owner']['uid'] ?? '???';
				$reference->setDescription(
					$this->l10n->t('From %1$s, in %2$s/%3$s, owned by %4$s', [
						$comment['actorDisplayName'],
						$board['title'],
						$stack['title'],
						$boardOwnerDisplayName
					])
				);
				$imageUrl = $this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath('core', 'actions/comment.svg')
				);
				$reference->setImageUrl($imageUrl);
				$reference->setRichObject(Application::APP_ID . '-comment', [
					'id' => $ids,
					'board' => $board,
					'card' => $card,
					'stack' => $stack,
					'comment' => $comment,
				]);
				return $reference;
			}
		}

		return null;
	}

	private function sanitizeSerializedStack(array $stack): array {
		$stack['cards'] = array_map(function (CardDetails $cardDetails) {
			$result = $cardDetails->jsonSerialize();
			unset($result['assignedUsers']);
			return $result;
		}, $stack['cards']);

		return $stack;
	}

	private function sanitizeSerializedBoard(array $board): array {
		unset($board['labels']);
		$board['owner'] = $board['owner']->jsonSerialize();
		unset($board['acl']);
		unset($board['users']);

		return $board;
	}

	private function sanitizeSerializedCard(array $card): array {
		$card['labels'] = array_map(function (Label $label) {
			return $label->jsonSerialize();
		}, $card['labels']);
		$card['assignedUsers'] = array_map(function (Assignment $assignment) {
			$result = $assignment->jsonSerialize();
			$result['participant'] = $result['participant']->jsonSerialize();
			return $result;
		}, $card['assignedUsers']);
		$card['owner'] = $card['owner']->jsonSerialize();
		unset($card['relatedStack']);
		unset($card['relatedBoard']);
		$card['attachments'] = array_map(function (Attachment $attachment) {
			return $attachment->jsonSerialize();
		}, $card['attachments']);

		return $card;
	}

	private function getIds(string $url): ?array {
		$start = $this->urlGenerator->getAbsoluteURL('/apps/' . Application::APP_ID);
		$startIndex = $this->urlGenerator->getAbsoluteURL('/index.php/apps/' . Application::APP_ID);

		preg_match('/^' . preg_quote($start, '/') . '\/#\/board\/([0-9]+)\/card\/([0-9]+)\/comments\/(\d+)$/', $url, $matches);
		if (!$matches) {
			preg_match('/^' . preg_quote($startIndex, '/') . '\/#\/board\/([0-9]+)\/card\/([0-9]+)\/comments\/(\d+)$/', $url, $matches);
		}
		if ($matches && count($matches) > 3) {
			return [
				(int) $matches[1],
				(int) $matches[2],
				(int) $matches[3],
			];
		}

		return null;
	}

	public function getCachePrefix(string $referenceId): string {
		return $referenceId;
	}

	public function getCacheKey(string $referenceId): ?string {
		return $this->userId ?? '';
	}
}
