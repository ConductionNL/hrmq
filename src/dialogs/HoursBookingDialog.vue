<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<!-- The backdrop carries the Escape handler because keydown bubbles up from
	     the focused panel inside it, so one listener covers the whole dialog.
	     tabindex="-1" keeps it out of the tab order while giving it the focus
	     target a keyboard-reachable control needs. Clicking it is a convenience
	     that duplicates Cancel; a keyboard user has Escape and that button. -->
	<div
		class="hq-booking"
		role="presentation"
		tabindex="-1"
		@click.self="close"
		@keydown.esc.stop.prevent="close">
		<div
			class="hq-booking__panel"
			role="dialog"
			aria-modal="true"
			:aria-labelledby="titleId"
			data-testid="hq-hours-booking-dialog"
			tabindex="-1">
			<h2 :id="titleId" class="hq-booking__title">
				{{ t('humaniq', 'Book hours') }}
			</h2>

			<p class="hq-booking__context">
				{{ t('humaniq', 'These hours are booked against this item.') }}
			</p>

			<p v-if="error" class="hq-booking__error" role="alert">
				{{ error }}
			</p>

			<form class="hq-booking__form" @submit.prevent="submit">
				<label class="hq-booking__field">
					<span class="hq-booking__label">{{ t('humaniq', 'Date') }}</span>
					<input
						ref="firstField"
						v-model="date"
						type="date"
						required
						class="hq-booking__input"
						data-testid="hq-hours-booking-date">
				</label>

				<label class="hq-booking__field">
					<span class="hq-booking__label">{{ t('humaniq', 'Hours') }}</span>
					<input
						v-model="hours"
						type="number"
						min="0.25"
						max="24"
						step="0.25"
						required
						class="hq-booking__input"
						data-testid="hq-hours-booking-hours">
				</label>

				<label class="hq-booking__field hq-booking__field--wide">
					<span class="hq-booking__label">{{ t('humaniq', 'What did you work on?') }}</span>
					<input
						v-model="description"
						type="text"
						maxlength="255"
						class="hq-booking__input"
						data-testid="hq-hours-booking-description">
				</label>

				<div class="hq-booking__actions">
					<button
						type="button"
						class="hq-booking__button"
						:disabled="saving"
						@click="close">
						{{ t('humaniq', 'Cancel') }}
					</button>
					<button
						type="submit"
						class="hq-booking__button hq-booking__button--primary"
						:disabled="saving"
						data-testid="hq-hours-booking-submit">
						{{ saving ? t('humaniq', 'Booking') : t('humaniq', 'Book hours') }}
					</button>
				</div>
			</form>
		</div>
	</div>
</template>

<script>
/**
 * HoursBookingDialog — book hours against the object the reader is looking at.
 *
 * OVER THE HOST PAGE, NOT INSTEAD OF IT. The surface this opens from lives on
 * another app's detail page, and the reader opened it because of the object in
 * front of them. Sending them to humaniq to book time against that object loses
 * the object, and every field this dialog seeds has to be found again by hand.
 *
 * PLAIN MARKUP, NO COMPONENT LIBRARY. This file is reachable from the
 * `humaniq-leaves` bundle, which lands on every page of every consuming app, so
 * it carries its own small dialog rather than pulling a component library in
 * behind it. That also removes the question of whose Vue interprets it: the leaf
 * mounts its own Vue 3 instance into a bare host element, and nothing here
 * crosses that boundary.
 *
 * THE DAY SHAPE, NOT THE CLOCK. `TimeEntry` accepts either a start and an end or
 * a date and a number of hours. A person booking against a case knows how long
 * they spent; they rarely know the clock times, and asking for times they would
 * have to invent is worse than asking for the figure they have. The timer covers
 * the other case, where the clock is the point.
 *
 * THE TWO REFERENCE FIELDS ARE NOT ON THE FORM. `domainObjectType` and
 * `domainObjectRef` are written by the integration, which is why neither appears
 * in any `includeFields` allowlist. Offering them for editing would invite an
 * employee to correct a reference they have no way to verify.
 */
import { translate as t } from '@nextcloud/l10n'
import { bookHours } from '../integrations/hoursApi.js'

/** Counter behind the generated label id, so two open dialogs cannot collide. */
let dialogSeq = 0

export default {
	name: 'HoursBookingDialog',

	props: {
		/** The `<app>:<schema>` literal of the host object. */
		domainObjectType: {
			type: String,
			required: true,
		},

		/** The host object's uuid. */
		domainObjectRef: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'booked'],

	data() {
		dialogSeq += 1

		return {
			titleId: `hq-booking-title-${dialogSeq}`,
			// Today, because the overwhelmingly common case is booking work just
			// finished. A person backdating knows to change it; a person booking
			// today should not have to fill it in at all.
			date: new Date().toISOString().slice(0, 10),
			hours: '',
			description: '',
			saving: false,
			error: '',
		}
	},

	/**
	 * Put focus on the first field.
	 *
	 * @return {void}
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
	 */
	mounted() {
		// Focus lands inside the dialog, or a keyboard reader is left on the page
		// behind it with no way to know the dialog opened.
		this.$nextTick(() => {
			const field = this.$refs.firstField
			if (field !== undefined && field !== null) {
				field.focus()
			}
		})
	},

	methods: {
		t,

		/**
		 * Close without booking anything.
		 *
		 * Refuses while a write is in flight: closing then would leave the reader
		 * unable to tell whether their hours were booked.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		close() {
			if (this.saving === true) {
				return
			}
			this.$emit('close')
		},

		/**
		 * Write the booking and hand the created entry back to the tile.
		 *
		 * @return {Promise<void>} Resolves when the write has settled.
		 *
		 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
		 */
		async submit() {
			const hours = Number(this.hours)
			if (this.date === '' || Number.isFinite(hours) === false || hours <= 0) {
				this.error = t('humaniq', 'Fill in a date and how many hours you worked.')
				return
			}

			this.saving = true
			this.error = ''
			try {
				const entry = await bookHours({
					domainObjectType: this.domainObjectType,
					domainObjectRef: this.domainObjectRef,
					date: this.date,
					hours,
					description: this.description,
				})
				this.$emit('booked', entry)
			} catch (e) {
				// humaniq refuses a booking with a message written for a person to
				// read, and that message is the whole point of the refusal. Show it
				// rather than replacing it with a generic failure.
				this.error = e?.response?.data?.error
					|| e?.response?.data?.message
					|| t('humaniq', 'Your hours could not be booked. Try again.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.hq-booking {
	align-items: center;
	background-color: rgba(0, 0, 0, 0.4);
	display: flex;
	inset: 0;
	justify-content: center;
	padding: 16px;
	position: fixed;
	z-index: 10000;
}

.hq-booking__panel {
	background-color: var(--color-main-background);
	border-radius: var(--border-radius-large, 12px);
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
	color: var(--color-main-text);
	max-height: 90vh;
	max-width: 460px;
	overflow-y: auto;
	padding: 20px;
	width: 100%;
}

.hq-booking__title {
	font-size: 18px;
	margin: 0 0 4px;
}

.hq-booking__context {
	color: var(--color-text-maxcontrast);
	margin: 0 0 12px;
}

.hq-booking__error {
	background-color: var(--color-error);
	border-radius: var(--border-radius);
	color: var(--color-primary-text, #fff);
	margin: 0 0 12px;
	padding: 8px 10px;
}

.hq-booking__form {
	display: grid;
	gap: 12px;
	grid-template-columns: 1fr 1fr;
}

.hq-booking__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.hq-booking__field--wide,
.hq-booking__actions {
	grid-column: 1 / -1;
}

.hq-booking__label {
	color: var(--color-text-maxcontrast);
}

.hq-booking__input {
	background-color: var(--color-main-background);
	border: 2px solid var(--color-border-maxcontrast, var(--color-border));
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	padding: 6px 8px;
	width: 100%;
}

.hq-booking__input:focus-visible {
	border-color: var(--color-primary-element);
	outline: none;
}

.hq-booking__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

.hq-booking__button {
	background-color: var(--color-background-dark);
	border: none;
	border-radius: var(--border-radius-pill, 16px);
	color: var(--color-main-text);
	cursor: pointer;
	padding: 6px 14px;
}

.hq-booking__button:hover:enabled,
.hq-booking__button:focus-visible {
	background-color: var(--color-background-hover);
}

.hq-booking__button:disabled {
	cursor: default;
	opacity: 0.6;
}

.hq-booking__button--primary {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
}

.hq-booking__button--primary:hover:enabled,
.hq-booking__button--primary:focus-visible {
	background-color: var(--color-primary-element-hover);
}
</style>
