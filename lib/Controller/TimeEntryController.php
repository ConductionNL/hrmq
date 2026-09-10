<?php

/**
 * Time Entry Controller
 *
 * The three timer endpoints behind the hours leaf: ask what is running, start a
 * timer against a host object, stop the one that is running.
 *
 * ONE THING ONLY, no CRUD (ADR-022). Time entries are read and written
 * declaratively through OpenRegister's object API by the pages and by the leaf
 * itself; the only thing that cannot be expressed that way is the constraint
 * "one running timer per user", because it spans rows the caller does not send
 * and has to be decided from the caller's identity.
 *
 * EVERY METHOD RESOLVES FROM THE CALLER (ADR-005 Rule 3). None of the three
 * accepts a time-entry id, so there is no reference a caller could swap for
 * someone else's entry: `stop()` takes no parameters at all, and `start()` takes
 * only the host object's reference, which the integration supplies and no
 * employee ever types. The `#[NoAdminRequired]` posture is therefore safe by
 * construction rather than by a check that could be forgotten.
 *
 * @category Controller
 * @package  OCA\Humaniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hours-leaf-for-any-object/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Listener\HoursWriteRefusedException;
use OCA\Humaniq\Service\RunningTimerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Start, stop and resolve the calling user's running timer.
 */
class TimeEntryController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request      The request object.
	 * @param IUserSession        $userSession  The calling user, the only identity any of these methods trusts.
	 * @param RunningTimerService $timers       Start, stop and resolve the running timer.
	 * @param LoggerInterface     $logger       Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly RunningTimerService $timers,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `GET /api/time-entries/timer` — the caller's running timer, or none.
	 *
	 * This is what lets a timer survive leaving the page: the surface asks on
	 * mount rather than remembering, so a timer started before navigating away
	 * is still running on return, on any device.
	 *
	 * @return JSONResponse `{status: 'running', entry: {...}}` or `{status: 'none'}`.
	 *
	 * @spec openspec/changes/hours-leaf-for-any-object/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
	 */
	#[NoAdminRequired]
	public function timer(): JSONResponse {
		$uid = $this->callerUid();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Log in om je timer te gebruiken.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$running = $this->timers->running($uid);
		} catch (HoursWriteRefusedException $e) {
			return $this->refused($e);
		}

		if ($running === null) {
			return new JSONResponse(['status' => RunningTimerService::STATUS_NONE]);
		}

		return new JSONResponse(['status' => RunningTimerService::STATUS_RUNNING, 'entry' => $running]);
	}//end timer()

	/**
	 * `POST /api/time-entries/timer/start` — start a timer against a host object.
	 *
	 * Refuses a second timer with 409 and hands back the entry that is already
	 * running, so the caller can say WHICH object is being timed rather than
	 * only that something is.
	 *
	 * @param string|null $domainObjectType The `<app>:<schema>` literal of the host object.
	 * @param string|null $domainObjectRef  The host object's uuid.
	 *
	 * @return JSONResponse The started entry, 400 on a missing reference, 409 when one is already running.
	 *
	 * @spec openspec/changes/hours-leaf-for-any-object/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
	 */
	#[NoAdminRequired]
	public function startTimer(?string $domainObjectType = null, ?string $domainObjectRef = null): JSONResponse {
		$uid = $this->callerUid();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Log in om je timer te gebruiken.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->timers->start($uid, (string)$domainObjectType, (string)$domainObjectRef);
		} catch (HoursWriteRefusedException $e) {
			return $this->refused($e);
		}

		return match ((string)$result['status']) {
			RunningTimerService::STATUS_INVALID => new JSONResponse($result, Http::STATUS_BAD_REQUEST),
			RunningTimerService::STATUS_ALREADY_RUNNING => new JSONResponse($result, Http::STATUS_CONFLICT),
			default => new JSONResponse($result),
		};
	}//end startTimer()

	/**
	 * `POST /api/time-entries/timer/stop` — stop the caller's running timer.
	 *
	 * Takes nothing. The entry is resolved from the caller, so stopping someone
	 * else's timer is not a request that can be phrased.
	 *
	 * @return JSONResponse The stopped entry, or 404 when nothing was running.
	 *
	 * @spec openspec/changes/hours-leaf-for-any-object/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
	 */
	#[NoAdminRequired]
	public function stopTimer(): JSONResponse {
		$uid = $this->callerUid();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Log in om je timer te gebruiken.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->timers->stop($uid);
		} catch (HoursWriteRefusedException $e) {
			return $this->refused($e);
		}

		if ((string)$result['status'] === RunningTimerService::STATUS_NONE) {
			return new JSONResponse(
				['status' => RunningTimerService::STATUS_NONE, 'error' => 'Er loopt geen timer om te stoppen.'],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse($result);
	}//end stopTimer()

	/**
	 * The calling user's id, or '' when there is no session.
	 *
	 * @return string The Nextcloud user id.
	 */
	private function callerUid(): string {
		$user = $this->userSession->getUser();

		return $user === null ? '' : trim((string)$user->getUID());
	}//end callerUid()

	/**
	 * Turn a deliberate refusal into the message it carries.
	 *
	 * The exception's message is written for a person to read and is the whole
	 * point of raising it, so it is passed through rather than replaced with a
	 * generic failure.
	 *
	 * @param HoursWriteRefusedException $e The refusal.
	 *
	 * @return JSONResponse The refusal, as 400.
	 */
	private function refused(HoursWriteRefusedException $e): JSONResponse {
		$this->logger->info('TimeEntryController weigerde een timerverzoek: ' . $e->getMessage());

		return new JSONResponse(
			['status' => RunningTimerService::STATUS_INVALID, 'error' => $e->getMessage()],
			Http::STATUS_BAD_REQUEST
		);
	}//end refused()

}//end class
