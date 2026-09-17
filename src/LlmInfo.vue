<script setup lang="ts">
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import type { LlmProviderInfo } from './api'

const props = defineProps<{
	provider: LlmProviderInfo | null
	promptTemplate: string
	/**
	 * Whether this viewer may see the endpoint URL. The backend already omits
	 * it for non-admins; this says whether to explain the gap or simply treat
	 * the provider as having no URL.
	 */
	canSeeEndpoint: boolean
}>()

const notSet = t('travelmanager', 'Not reported')

const copyPrompt = async () => {
	try {
		await navigator.clipboard.writeText(props.promptTemplate)
		showSuccess(t('travelmanager', 'Copied to clipboard'))
	} catch (e) {
		showError(t('travelmanager', 'Could not copy to clipboard'))
	}
}
</script>

<template>
	<NcSettingsSection :name="t('travelmanager', 'Extraction model')">
		<NcNoteCard v-if="!provider" type="warning">
			{{ t('travelmanager', 'No AI text-processing provider is currently available for core:text2text, so extraction cannot run. Install and select one in the AI admin settings.') }}
		</NcNoteCard>

		<!-- Two things at once, and both belong here rather than beside the
		     mailbox form: that email content leaves the instance for a model at
		     all, and that this panel describes the *configuration* rather than
		     the rows in front of you — Task Processing keeps no record of which
		     model answered a given task, so a booking extracted before the admin
		     last changed the model was produced by something we cannot name. It
		     leads the section because it qualifies everything under it. -->
		<NcNoteCard v-else type="info">
			{{ t('travelmanager', 'Email content is sent to the AI model for extraction. The settings are configured by the administrator in the Nextcloud Assistant admin settings. Please note that bookings extracted earlier may have been produced by a different model.') }}
		</NcNoteCard>

		<div v-if="provider" :class="$style.fields">
			<span :class="$style.label">{{ t('travelmanager', 'Provider') }}</span>
			<span :class="$style.value">
				{{ provider.providerName }}
				<span :class="$style.meta">{{ provider.providerId }}</span>
			</span>

			<span :class="$style.label">{{ t('travelmanager', 'Model') }}</span>
			<span :class="$style.value">{{ provider.model ?? notSet }}</span>

			<span :class="$style.label">{{ t('travelmanager', 'Endpoint') }}</span>
			<span v-if="provider.endpointUrl" :class="$style.value">{{ provider.endpointUrl }}</span>
			<span v-else-if="!canSeeEndpoint" :class="[$style.value, $style.meta]">
				{{ t('travelmanager', 'Only administrators can see the endpoint URL.') }}
			</span>
			<span v-else :class="[$style.value, $style.meta]">
				{{ t('travelmanager', 'Not reported — this provider either runs locally or does not publish a URL.') }}
			</span>

			<span :class="$style.label">{{ t('travelmanager', 'Max output tokens') }}</span>
			<span :class="$style.value">{{ provider.maxTokens ?? notSet }}</span>

			<span :class="$style.label">{{ t('travelmanager', 'Task type') }}</span>
			<span :class="$style.value">{{ provider.taskTypeId }}</span>

			<span :class="$style.label">{{ t('travelmanager', 'Typical response time') }}</span>
			<span :class="$style.value">{{ n('travelmanager', '%n second', '%n seconds', provider.expectedRuntime) }}</span>
		</div>

		<!-- Collapsed like the Messages view's diagnostic sections, and for the
		     same reason: several thousand characters you consult occasionally
		     should not push everything else off the screen. -->
		<details :class="$style.section">
			<summary>{{ t('travelmanager', 'Extraction prompt') }}</summary>
			<p :class="$style.meta">
				{{ t('travelmanager', 'Sent ahead of each email, followed by the email’s subject and plain-text body.') }}
			</p>
			<div :class="$style.textBox">
				<!-- Wrapper, not the button itself: NcButton's own `position: relative`
				     is the same specificity as ours and its stylesheet loads later, so
				     it would win. -->
				<span :class="$style.copyButton">
					<NcButton variant="secondary" @click="copyPrompt">
						{{ t('travelmanager', 'Copy') }}
					</NcButton>
				</span>
				<pre :class="$style.text">{{ promptTemplate }}</pre>
			</div>
		</details>
	</NcSettingsSection>
</template>

<style module>
/* Label/value pairs in one grid so every value starts at the same x, whatever
   the longest label happens to be. Spans rather than a <dl>: the server styles
   bare semantic elements hard (see the <button> note in CLAUDE.md §7), and
   something in that stack was adding ~55px of leading to every row. Same shape
   as .tm-fields in grid.css, which the app's detail panel uses — not that
   stylesheet itself, since it is imported by the app page, not by settings. */
.fields {
	display: grid;
	grid-template-columns: max-content minmax(0, 1fr);
	gap: 1px 12px;
	align-items: baseline;
	line-height: 1.4;
	margin: 0;
}

.label {
	color: var(--color-text-maxcontrast);
	text-align: start;
}

.value {
	min-width: 0;
	overflow-wrap: anywhere;
}

.meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

/* Matches the Messages view's collapsible diagnostic sections. */
.section {
	margin: 16px 0 0;
}

.section p {
	margin: 8px 0 0;
}

/* Positioning context for the copy button, which floats over the text rather
   than sitting above it — one less row of chrome between you and the content. */
.textBox {
	position: relative;
	margin-block-start: 8px;
}

/* Pinned to the box, not the text, so it stays put while the content scrolls
   under it. Inset clears the pre's own scrollbar. */
.copyButton {
	position: absolute;
	inset-block-start: 12px;
	inset-inline-end: 16px;
	z-index: 1;
}

.text {
	margin: 0;
	padding: 8px;
	/* Room at the end of the first line so the button never lands on text. */
	padding-inline-end: 72px;
	max-height: 300px;
	overflow: auto;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
}
</style>
