<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="hq-hours" data-testid="hq-hours-widget">
		<!-- The leaf names itself. A host places it as a card among other cards
		     and hands a mount-mode leaf no title, so without this the tile is a
		     bare number and the reader has to guess what was counted. -->
		<h3 class="hq-hours__caption" data-testid="hq-hours-caption">
			{{ t('humaniq', 'Hours booked') }}
		</h3>

		<!-- Running: the tile becomes the timer. -->
		<div v-if="running" class="hq-hours__figures" data-testid="hq-hours-running">
			<div class="hq-hours__headline">
				<span class="hq-hours__value hq-hours__value--running">{{ elapsed }}</span>
			</div>
			<p class="hq-hours__sub">
				{{ runningHere
					? t('humaniq', 'Timer running on this item')
					: t('humaniq', 'Timer running on another item') }}
			</p>
		</div>

		<!-- Idle: the object's total, over the caller's own share. -->
		<div v-else class="hq-hours__figures">
			<div class="hq-hours__headline">
				<span class="hq-hours__value" data-testid="hq-hours-total">{{ displayTotal }}</span>
				<span class="hq-hours__unit">{{ t('humaniq', 'hours') }}</span>
			</div>
			<p class="hq-hours__sub" data-testid="hq-hours-own">
				{{ ownLine }}
			</p>
		</div>

		<p v-if="error" class="hq-hours__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="!running && entries.length === 0 && !loading" class="hq-hours__empty">
			{{ t('humaniq', 'Nobody has booked hours on this yet.') }}
		</p>
		<ul v-else-if="visibleEntries.length > 0" class="hq-hours__list">
			<li v-for="entry in visibleEntries" :key="entryKey(entry)" class="hq-hours__row">
				<span class="hq-hours__row-hours">{{ formatHours(entry.hours) }}</span>
				<span class="hq-hours__row-desc">{{ entry.description || t('humaniq', 'No description') }}</span>
				<span class="hq-hours__row-date">{{ formatDate(entry) }}</span>
			</li>
		</ul>

		<div class="hq-hours__actions">
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
				<span aria-hidden="true">{{ runningHere ? '■' : '▶' }}</span>
			</button>

			<button
				type="button"
				class="hq-hours__action"
				data-testid="hq-hours-book"
				@click="showBooking = true">
				{{ t('humaniq', 'Book hours') }}
			</button>

			<a
				class="hq-hours__action"
				:href="administrationHref"
				data-testid="hq-hours-view">
				{{ t('humaniq', 'View hours') }}
			</a>
		</div>

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

		/** The render surface the host mounted us into. */
		surface: {
			type: String,
			default: 'detail-page',
		},

		/** How many entries to list under the total. */
		limit: {
			type: Number,
			default: 5,
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
		 * The entries to list under the figures.
		 *
		 * A dashboard tile is a headline figure with room for barely a line, so it
		 * lists none; a detail page or a sidebar has room for the recent bookings
		 * that explain the total. This is what `surface` is for: the host tells the
		 * leaf how much room it has, and the leaf decides.
		 *
		 * @return {object[]} The entries to render.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-humaniq-supplies-the-hours-surface-for-any-object
		 */
		visibleEntries() {
			if (['user-dashboard', 'app-dashboard'].includes(this.surface) === true) {
				return []
			}

			return this.entries.slice(0, this.limit)
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
		 * A stable key for one entry row.
		 *
		 * Falls back to the booking's own facts when the register hands back no
		 * id: a duplicated key makes Vue reuse the wrong row, which shows one
		 * booking's hours against another's description.
		 *
		 * @param {object} entry The entry.
		 *
		 * @return {string} The key.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-humaniq-supplies-the-hours-surface-for-any-object
		 */
		entryKey(entry) {
			return String(entry.id || entry['@self']?.id || `${entry.startedAt || entry.date}-${entry.hours}`)
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

		/**
		 * Format a booking's day as a short local date.
		 *
		 * Reads `startedAt` first and `date` second, because an entry is recorded
		 * in either shape and only one of the two is ever set.
		 *
		 * @param {object} entry The entry.
		 *
		 * @return {string} The formatted date, or ''.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-humaniq-supplies-the-hours-surface-for-any-object
		 */
		formatDate(entry) {
			const raw = entry.startedAt || entry.date || ''
			if (raw === '') {
				return ''
			}

			const d = new Date(raw)

			return Number.isNaN(d.getTime()) === true ? '' : d.toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.hq-hours {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.hq-hours__caption {
	color: var(--color-text-maxcontrast);
	font-size: inherit;
	font-weight: normal;
	margin: 0;
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
.hq-hours__unit,
.hq-hours__empty,
.hq-hours__row-date {
	color: var(--color-text-maxcontrast);
}

.hq-hours__sub {
	margin: 2px 0 0;
}

.hq-hours__error {
	color: var(--color-error);
	margin: 0;
}

.hq-hours__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.hq-hours__row {
	display: flex;
	gap: 8px;
	justify-content: space-between;
}

.hq-hours__row-desc {
	flex: 1 1 auto;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.hq-hours__row-hours {
	font-weight: bold;
	min-width: 3em;
}

.hq-hours__actions {
	align-items: center;
	display: flex;
	gap: 8px;
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
	height: 28px;
	justify-content: center;
	padding: 0;
	width: 28px;
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
