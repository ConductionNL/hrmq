<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="hq-hours" :data-surface="surface" data-testid="hq-hours-widget">
		<!-- CHROME. A host places a mount-mode leaf into a bare element and hands
		     it no card, so the leaf draws its own or it reads as loose text
		     sitting on the page between the cards that do have one. -->
		<div class="hq-hours__header">
			<!-- The leaf names itself for the same reason: the host hands it no
			     title either, and a KPI that is only a number does not say what
			     was counted. -->
			<h3 class="hq-hours__caption" data-testid="hq-hours-caption">
				{{ t('humaniq', 'Hours booked') }}
			</h3>

			<div class="hq-hours__controls">
				<!-- The stopwatch is its own control, left of the actions, because
				     it is the one-press shortcut for work happening right now.
				     Putting it inside the menu would cost two presses for the
				     thing that has to be instant. -->
				<button
					v-if="canUseTimer"
					type="button"
					class="hq-hours__timer"
					:class="{ 'hq-hours__timer--running': runningHere }"
					:disabled="busy"
					:title="timerTitle"
					:aria-label="timerTitle"
					data-testid="hq-hours-timer"
					@click="toggleTimer">
					<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<template v-if="runningHere">
							<rect x="7" y="7" width="10" height="10" rx="1.5" fill="currentColor" />
						</template>
						<template v-else>
							<path
								d="M9 2h6M12 7a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM12 4.5V7M18.5 6l1.5-1.5M12 10.5V14h2.5"
								fill="none"
								stroke="currentColor"
								stroke-width="1.8"
								stroke-linecap="round"
								stroke-linejoin="round" />
						</template>
					</svg>
				</button>

				<!-- ONE action button, not three. Booking and viewing are the two
				     ordinary paths and they belong together behind the control the
				     reader reaches for; the tile's job is the figure. -->
				<div class="hq-hours__menu">
					<button
						type="button"
						class="hq-hours__action"
						:aria-expanded="String(menuOpen)"
						aria-haspopup="menu"
						data-testid="hq-hours-actions"
						@click="menuOpen = !menuOpen">
						{{ t('humaniq', 'Actions') }}
						<span class="hq-hours__caret" aria-hidden="true">▾</span>
					</button>

					<div v-if="menuOpen" class="hq-hours__menu-list" role="menu">
						<button
							type="button"
							class="hq-hours__menu-item"
							role="menuitem"
							data-testid="hq-hours-book"
							@click="openBooking">
							{{ t('humaniq', 'Book hours') }}
						</button>
						<a
							class="hq-hours__menu-item"
							role="menuitem"
							:href="administrationHref"
							data-testid="hq-hours-view">
							{{ t('humaniq', 'View hours') }}
						</a>
					</div>
				</div>
			</div>
		</div>

		<!-- Timing THIS object: the tile becomes the timer.
		     Only this object. A timer running elsewhere leaves the figures alone,
		     because putting another object's elapsed time where this object's
		     total goes reads as this object's time. -->
		<div v-if="runningHere" class="hq-hours__figures" data-testid="hq-hours-running">
			<div class="hq-hours__headline">
				<span class="hq-hours__value hq-hours__value--running">{{ elapsed }}</span>
			</div>
			<p class="hq-hours__sub">
				{{ t('humaniq', 'Timer running on this item') }}
			</p>
		</div>

		<!-- Idle: the object's total, over the caller's own share.
		     The bookings behind the total are NOT listed here. A KPI answers one
		     question, and the administration is one press away in the actions for
		     the reader who wants the rows. -->
		<div v-else class="hq-hours__figures">
			<div class="hq-hours__headline">
				<span class="hq-hours__value" data-testid="hq-hours-total">{{ displayTotal }}</span>
				<span class="hq-hours__unit">{{ t('humaniq', 'hours') }}</span>
			</div>
			<p class="hq-hours__sub" data-testid="hq-hours-own">
				{{ ownLine }}
			</p>
			<p v-if="running" class="hq-hours__sub" data-testid="hq-hours-running-elsewhere">
				{{ t('humaniq', 'Timer running on another item') }}
			</p>
		</div>

		<p v-if="error" class="hq-hours__error" role="alert">
			{{ error }}
		</p>

		<HoursBookingDialog
			v-if="showBooking"
			:domainObjectType="domainObjectType"
			:domainObjectRef="objectId"
			@close="showBooking = false"
			@booked="onBooked" />
	</div>
</template>

<script>
/**
 * CnHoursWidget — hours booked against ANY object, and the three ways to act.
 *
 * humaniq owns hours (ADR-107 decision 6: "hours logged on a case are humaniq
 * time entries carrying the case reference"), so humaniq renders them. The
 * consuming app places this leaf and passes the object context; it does not
 * query humaniq's register itself.
 *
 * That indirection is the point. dossiq used to aggregate `humaniq/TimeEntry`
 * from its own manifest, which meant that on an install without humaniq the
 * request 404'd and the tile rendered `0`, indistinguishable from a real zero
 * (ADR-113). A leaf cannot render at all when its app is absent, so the failure
 * mode disappears rather than being handled.
 *
 * TWO FIGURES FROM ONE READ. The headline is what the object cost everyone; the
 * line beneath is what it cost the reader. Both are summed from the same array,
 * because a second request for the second figure would let the two disagree.
 *
 * THE TIMER IS A ROW, NOT A FLAG. Starting one writes a `TimeEntry` with no end,
 * so it is still running when the reader comes back to the page, or opens it on
 * another device. This component asks the server what is running on mount rather
 * than remembering, which is the only version of that promise it can keep.
 *
 * The bound object is identified the way humaniq stores it: `domainObjectType`
 * is the `<app>:<schema>` literal (`dossiq:case`) and `domainObjectRef` is the
 * object's uuid. Both are written by integrations rather than typed by an
 * employee, which is why neither appears in any `includeFields` allowlist.
 */
import { translate as t } from '@nextcloud/l10n'
import HoursBookingDialog from '../dialogs/HoursBookingDialog.vue'
import {
	administrationUrl,
	fetchEntries,
	fetchRunningTimer,
	startTimer,
	stopTimer,
} from './hoursApi.js'

/** How often the running figure is redrawn, in milliseconds. */
const TICK_MS = 1000

export default {
	name: 'CnHoursWidget',

	components: {
		HoursBookingDialog,
	},

	props: {
		/** OpenRegister register slug of the HOST object (not humaniq's). */
		register: {
			type: String,
			default: '',
		},

		/** OpenRegister schema slug of the host object. */
		schema: {
			type: String,
			default: '',
		},

		/** The host object's uuid, what `domainObjectRef` points at. */
		objectId: {
			type: String,
			default: '',
		},

		/**
		 * The render surface the host mounted us into. Part of the leaf contract the
		 * host always passes, exposed as `data-surface` so a host can style the card
		 * per surface without the leaf guessing how much room it has.
		 */
		surface: {
			type: String,
			default: 'detail-page',
		},

	},

	data() {
		return {
			entries: [],
			loading: false,
			busy: false,
			error: '',
			/** The caller's running entry, from the server, or null. */
			running: null,
			/** The current user's Nextcloud id, for the caller's own share. */
			uid: '',
			now: Date.now(),
			tick: null,
			showBooking: false,
			menuOpen: false,
		}
	},

	computed: {
		/**
		 * The summed hours over every booking on this object, or a dash.
		 *
		 * A dash rather than 0 on failure, deliberately: a zero that means "could
		 * not read" is the defect this whole leaf exists to remove.
		 *
		 * @return {string} The total, or the dash.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-unreadable-total-is-not-rendered-as-a-number
		 */
		displayTotal() {
			if (this.error !== '' || (this.loading === true && this.entries.length === 0)) {
				return '–'
			}

			return this.formatHours(this.sumOf(this.entries))
		},

		/**
		 * The caller's own share of the object's hours, as a full line.
		 *
		 * Rendered even at zero. An absent sub-line and a zero one are not the
		 * same claim: the first says nothing, the second says the reader has
		 * booked nothing here.
		 *
		 * @return {string} The line.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-surface-reads-as-a-kpi-tile
		 */
		ownLine() {
			if (this.error !== '' || (this.loading === true && this.entries.length === 0)) {
				return t('humaniq', 'Your share is not known yet')
			}

			if (this.uid === '') {
				// No resolvable caller. Matching on '' would count every entry
				// that carries no userId as the reader's own, which is a claim
				// about them that nothing supports.
				return t('humaniq', 'Your share is not known yet')
			}

			const mine = this.entries.filter((e) => String(e.userId || '') === this.uid)

			return t('humaniq', '{hours} booked by you', { hours: this.formatHours(this.sumOf(mine)) })
		},

		/**
		 * Whether the running timer belongs to the object on screen.
		 *
		 * @return {boolean} True when this object is the one being timed.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
		 */
		runningHere() {
			return this.running !== null
				&& String(this.running.domainObjectRef || '') === this.objectId
		},

		/**
		 * Whether the timer control does anything if pressed.
		 *
		 * Hidden rather than disabled while a timer runs on ANOTHER object: a
		 * disabled start invites the reader to work out why, and the sub-line has
		 * already told them.
		 *
		 * @return {boolean} True when the control is offered.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
		 */
		canUseTimer() {
			return this.running === null || this.runningHere === true
		},

		/**
		 * The timer control's accessible name.
		 *
		 * The control is an icon alone, so its whole name lives here: without it
		 * a screen reader announces a button with no label.
		 *
		 * @return {string} The name.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		timerTitle() {
			return this.runningHere ? t('humaniq', 'Stop the timer') : t('humaniq', 'Start a timer')
		},

		/**
		 * The running timer as `H:MM:SS`, counting from the stored start.
		 *
		 * Counted from the SERVER's `startedAt` rather than from the moment this
		 * component mounted, which is what makes the figure survive a reload.
		 *
		 * @return {string} The elapsed time.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
		 */
		elapsed() {
			const started = Date.parse(String(this.running?.startedAt || ''))
			if (Number.isNaN(started) === true) {
				return '0:00:00'
			}

			const seconds = Math.max(0, Math.floor((this.now - started) / 1000))
			const h = Math.floor(seconds / 3600)
			const m = Math.floor((seconds % 3600) / 60)
			const s = seconds % 60

			return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
		},

		/**
		 * The `<app>:<schema>` literal humaniq stores for the host object.
		 *
		 * @return {string} For example `dossiq:case`.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-humaniq-supplies-the-hours-surface-for-any-object
		 */
		domainObjectType() {
			return this.register !== '' && this.schema !== '' ? `${this.register}:${this.schema}` : ''
		},

		/**
		 * The link into humaniq's hour administration for this object.
		 *
		 * @return {string} The url.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		administrationHref() {
			return administrationUrl(this.domainObjectType, this.objectId)
		},
	},

	/**
	 * Read the object's hours, ask what timer is running, and start ticking.
	 *
	 * The uid is read from Nextcloud rather than from the entries, because the
	 * caller's own share must render `0` when they have booked nothing here, and
	 * a uid inferred from the rows cannot tell that apart from having no rows.
	 *
	 * @return {void}
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-surface-reads-as-a-kpi-tile
	 */
	mounted() {
		this.uid = String(window?.OC?.getCurrentUser?.()?.uid || '')
		this.load()
		this.loadRunning()
		this.tick = window.setInterval(() => {
			this.now = Date.now()
		}, TICK_MS)
	},

	/**
	 * Stop the tick.
	 *
	 * The host unmounts this leaf when the bound object changes or the surface
	 * hides, and an interval that outlives its component keeps redrawing a tile
	 * nobody is looking at, forever.
	 *
	 * @return {void}
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
	 */
	beforeUnmount() {
		if (this.tick !== null) {
			window.clearInterval(this.tick)
			this.tick = null
		}
	},

	methods: {
		t,

		/**
		 * Read this object's time entries from OpenRegister.
		 *
		 * @return {Promise<void>} Resolves when the list has settled.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-unreadable-total-is-not-rendered-as-a-number
		 */
		async load() {
			if (this.objectId === '' || this.domainObjectType === '') {
				return
			}

			this.loading = true
			this.error = ''
			try {
				this.entries = await fetchEntries(this.domainObjectType, this.objectId)
			} catch {
				// Say so rather than render 0. See the component docblock.
				this.error = t('humaniq', 'The hours for this item could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Ask the server what timer, if any, the caller has running.
		 *
		 * Failing quietly is right here and nowhere else on this tile: not knowing
		 * whether a timer runs costs the reader the timer control, while the
		 * figures are still true. Saying so would put an error over a working
		 * surface.
		 *
		 * @return {Promise<void>} Resolves when the timer state has settled.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
		 */
		async loadRunning() {
			try {
				this.running = await fetchRunningTimer()
			} catch {
				this.running = null
			}
		},

		/**
		 * Start a timer on this object, or stop the one running on it.
		 *
		 * @return {Promise<void>} Resolves when the entry has been written.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
		 */
		async toggleTimer() {
			this.busy = true
			this.error = ''
			try {
				if (this.runningHere === true) {
					await stopTimer()
					this.running = null
					await this.load()
				} else {
					const result = await startTimer(this.domainObjectType, this.objectId)
					if (result.status === 'running') {
						this.running = result.entry || null
					} else {
						// A refusal is an answer. Keep the entry the server named, so
						// the tile can say a timer runs elsewhere rather than only
						// that this one would not start.
						this.running = result.entry || null
						this.error = result.error || t('humaniq', 'You already have a timer running.')
					}
				}
			} catch (e) {
				this.error = e?.response?.data?.error || t('humaniq', 'The timer could not be started or stopped.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Take up a booking made in the dialog.
		 *
		 * @return {Promise<void>} Resolves when the figures have caught up.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		async onBooked() {
			this.showBooking = false
			await this.load()
		},

		/**
		 * Open the booking dialog from the actions menu.
		 *
		 * Closes the menu first: leaving it open behind a modal puts two focus
		 * traps on the page, and the one underneath is the one a screen reader
		 * finds on Escape.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		openBooking() {
			this.menuOpen = false
			this.showBooking = true
		},

		/**
		 * The sum of an entry list's hours.
		 *
		 * Used for both figures, so the headline and the caller's share can never
		 * be computed two different ways.
		 *
		 * @param {object[]} entries The entries.
		 *
		 * @return {number} The total.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-surface-reads-as-a-kpi-tile
		 */
		sumOf(entries) {
			return entries.reduce((sum, e) => sum + (Number(e.hours) || 0), 0)
		},

		/**
		 * Format an hours figure to at most two decimals, without trailing zeroes.
		 *
		 * @param {number} value The hours.
		 *
		 * @return {string} The formatted figure.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-unreadable-total-is-not-rendered-as-a-number
		 */
		formatHours(value) {
			return String(Math.round(((Number(value) || 0) * 100)) / 100)
		},

	},
}
</script>

<style scoped>
/* The card the host does not draw. A mount-mode leaf is handed a bare element,
   so without this the tile is loose text between neighbours that have chrome. */
.hq-hours {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px 16px;
}

.hq-hours__header {
	align-items: flex-start;
	display: flex;
	gap: 8px;
	justify-content: space-between;
}

.hq-hours__caption {
	color: var(--color-text-maxcontrast);
	font-size: inherit;
	font-weight: normal;
	margin: 0;
}

.hq-hours__controls {
	align-items: center;
	display: flex;
	flex: 0 0 auto;
	gap: 6px;
}

.hq-hours__menu {
	position: relative;
}

.hq-hours__menu-list {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.2);
	display: flex;
	flex-direction: column;
	inset-inline-end: 0;
	min-width: 160px;
	padding: 4px;
	position: absolute;
	top: calc(100% + 4px);
	z-index: 100;
}

.hq-hours__menu-item {
	background: transparent;
	border: none;
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	cursor: pointer;
	font: inherit;
	padding: 8px 10px;
	text-align: start;
	text-decoration: none;
	white-space: nowrap;
}

.hq-hours__menu-item:hover,
.hq-hours__menu-item:focus-visible {
	background-color: var(--color-background-hover);
}

.hq-hours__caret {
	margin-inline-start: 4px;
}

.hq-hours__headline {
	align-items: baseline;
	display: flex;
	gap: 6px;
}

.hq-hours__value {
	color: var(--color-primary-element);
	font-size: 28px;
	font-weight: bold;
	line-height: 1.1;
}

.hq-hours__value--running {
	font-variant-numeric: tabular-nums;
}

.hq-hours__sub,
.hq-hours__unit {
	color: var(--color-text-maxcontrast);
}

.hq-hours__sub {
	margin: 2px 0 0;
}

.hq-hours__error {
	color: var(--color-error);
	margin: 0;
}

.hq-hours__action {
	background: transparent;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
	color: var(--color-main-text);
	cursor: pointer;
	padding: 4px 12px;
	text-decoration: none;
}

.hq-hours__action:hover,
.hq-hours__action:focus-visible {
	background-color: var(--color-background-hover);
}

.hq-hours__timer {
	align-items: center;
	background: transparent;
	border: 1px solid var(--color-border);
	border-radius: 50%;
	color: var(--color-main-text);
	cursor: pointer;
	display: flex;
	flex: 0 0 auto;
	height: 32px;
	justify-content: center;
	padding: 0;
	width: 32px;
}

.hq-hours__timer:hover:enabled,
.hq-hours__timer:focus-visible {
	background-color: var(--color-background-hover);
}

.hq-hours__timer:disabled {
	cursor: default;
	opacity: 0.6;
}

.hq-hours__timer--running {
	background-color: var(--color-primary-element);
	border-color: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
}
</style>
