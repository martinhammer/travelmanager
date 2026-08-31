<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { loadState } from '@nextcloud/initial-state'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import { t } from '@nextcloud/l10n'
import {
	type LogEntry,
	type UserSettings,
	clearLogs,
	fetchLogs,
	runIngestNow,
	saveSettings,
	testConnection,
	wipeData,
} from './api'

const initial = loadState<UserSettings>('travelmanager', 'settings')
const featureEnabled = loadState<boolean>('travelmanager', 'featureEnabled', true)

const form = reactive({
	enabled: initial.enabled,
	imapHost: initial.imapHost,
	imapPort: initial.imapPort,
	imapSecurity: initial.imapSecurity,
	imapUser: initial.imapUser,
	mailbox: initial.mailbox,
	intervalMinutes: initial.intervalMinutes,
	imapPassword: '',
})
const hasPassword = ref(initial.hasPassword)
const securityOptions = ['ssl', 'tls', 'none']
const saving = ref(false)

const onSave = async () => {
	// The update endpoint is PasswordConfirmationRequired (it writes the IMAP
	// app password), so re-confirm the account password first — Nextcloud only
	// prompts if the sudo window has lapsed. A rejection means the user
	// dismissed the dialog, so just abort silently.
	try {
		await confirmPassword()
	} catch (e) {
		return
	}

	saving.value = true
	try {
		const payload = { ...form }
		if (!payload.imapPassword) {
			delete (payload as Record<string, unknown>).imapPassword
		}
		const updated = await saveSettings(payload)
		hasPassword.value = updated.hasPassword
		form.imapPassword = ''
		showSuccess(t('travelmanager', 'Settings saved'))
	} catch (e) {
		showError(t('travelmanager', 'Could not save settings'))
	} finally {
		saving.value = false
	}
}

const onTest = async () => {
	try {
		const res = await testConnection()
		if (res.ok) {
			showSuccess(t('travelmanager', 'Connection successful'))
		} else {
			showError(res.error || t('travelmanager', 'Connection failed'))
		}
	} catch (e) {
		showError(t('travelmanager', 'Connection failed'))
	}
}

/* -------------------------------------------------- developer / debug tools */

const logs = ref<LogEntry[]>([])
const logsLoading = ref(false)
const running = ref(false)
const wipeOpen = ref(false)
const wiping = ref(false)

const refreshLogs = async () => {
	logsLoading.value = true
	try {
		logs.value = await fetchLogs()
	} catch (e) {
		showError(t('travelmanager', 'Could not load the activity log'))
	} finally {
		logsLoading.value = false
	}
}

const onRunNow = async () => {
	running.value = true
	try {
		const res = await runIngestNow()
		showSuccess(t('travelmanager', 'Read the mailbox: {count} new message(s) scheduled for extraction', { count: res.enqueued }))
	} catch (e) {
		showError(t('travelmanager', 'Reading the mailbox failed — see the activity log below'))
	} finally {
		running.value = false
		await refreshLogs()
	}
}

const onClearLogs = async () => {
	try {
		await clearLogs()
		await refreshLogs()
	} catch (e) {
		showError(t('travelmanager', 'Could not clear the activity log'))
	}
}

const confirmWipe = async () => {
	wiping.value = true
	try {
		await wipeData()
		showSuccess(t('travelmanager', 'Extracted data wiped — the mailbox will be reprocessed from scratch'))
		wipeOpen.value = false
	} catch (e) {
		showError(t('travelmanager', 'Could not wipe the data'))
	} finally {
		wiping.value = false
		await refreshLogs()
	}
}

const formatTime = (iso: string | null): string => (iso ? new Date(iso).toLocaleString() : '')

const copyDetails = async (entry: LogEntry) => {
	const text = `[${entry.level}] ${entry.step} — ${entry.message}\n\n${entry.context ?? ''}`
	try {
		await navigator.clipboard.writeText(text)
		showSuccess(t('travelmanager', 'Copied to clipboard'))
	} catch (e) {
		showError(t('travelmanager', 'Could not copy to clipboard'))
	}
}

onMounted(refreshLogs)
</script>

<template>
	<NcSettingsSection :name="t('travelmanager', 'Travel Manager')"
		:description="t('travelmanager', 'Connect a dedicated mailbox that receives your travel booking emails. Messages are read only — Travel Manager never modifies your mailbox.')">
		<NcNoteCard v-if="!featureEnabled" type="warning">
			{{ t('travelmanager', 'Travel Manager is currently disabled by your administrator.') }}
		</NcNoteCard>

		<NcNoteCard type="info">
			{{ t('travelmanager', 'Email content is sent to the AI text-processing provider configured for this Nextcloud instance. Depending on the administrator’s choice, this may be a local model or an external third-party API.') }}
		</NcNoteCard>

		<NcCheckboxRadioSwitch v-model="form.enabled" :class="$style.field">
			{{ t('travelmanager', 'Enable automatic extraction for my mailbox') }}
		</NcCheckboxRadioSwitch>

		<NcTextField v-model="form.imapHost" :class="$style.field" :label="t('travelmanager', 'IMAP host')" />
		<NcTextField v-model="form.imapPort"
			:class="$style.field"
			type="number"
			:label="t('travelmanager', 'IMAP port')" />
		<div :class="[$style.field, $style.selectField]">
			<label>{{ t('travelmanager', 'Encryption') }}</label>
			<NcSelect v-model="form.imapSecurity" :options="securityOptions" :clearable="false" />
		</div>
		<NcTextField v-model="form.imapUser" :class="$style.field" :label="t('travelmanager', 'Account / username')" />
		<NcPasswordField v-model="form.imapPassword"
			:class="$style.field"
			:label="hasPassword ? t('travelmanager', 'App password (leave blank to keep current)') : t('travelmanager', 'App password')" />
		<NcTextField v-model="form.mailbox" :class="$style.field" :label="t('travelmanager', 'Mailbox / folder')" />
		<NcTextField v-model="form.intervalMinutes"
			:class="$style.field"
			type="number"
			:label="t('travelmanager', 'Check interval (minutes)')" />

		<div :class="$style.actions">
			<NcButton variant="primary" :disabled="saving" @click="onSave">
				{{ t('travelmanager', 'Save') }}
			</NcButton>
			<NcButton variant="secondary" @click="onTest">
				{{ t('travelmanager', 'Test connection') }}
			</NcButton>
		</div>
	</NcSettingsSection>

	<NcSettingsSection :name="t('travelmanager', 'Developer & debugging')"
		:description="t('travelmanager', 'Tools for testing the extraction pipeline without waiting for the background job. These act only on your own data.')">
		<div :class="$style.actions">
			<NcButton variant="primary" :disabled="running" @click="onRunNow">
				{{ t('travelmanager', 'Read mailbox now') }}
			</NcButton>
			<NcButton variant="secondary" :disabled="logsLoading" @click="refreshLogs">
				{{ t('travelmanager', 'Refresh log') }}
			</NcButton>
			<NcButton variant="tertiary" @click="onClearLogs">
				{{ t('travelmanager', 'Clear log') }}
			</NcButton>
			<NcButton variant="error" @click="wipeOpen = true">
				{{ t('travelmanager', 'Wipe my data') }}
			</NcButton>
		</div>

		<NcNoteCard type="info">
			{{ t('travelmanager', 'Reading the mailbox schedules extraction tasks; the model response arrives asynchronously, so refresh the log after a moment to see the result.') }}
		</NcNoteCard>

		<h4 :class="$style.logHeading">
			{{ t('travelmanager', 'Activity log') }}
		</h4>
		<p v-if="logs.length === 0" :class="$style.logEmpty">
			{{ t('travelmanager', 'No activity yet. Use “Read mailbox now” to start.') }}
		</p>
		<ol v-else :class="$style.log">
			<li v-for="entry in logs" :key="entry.id" :class="$style.logEntry">
				<div :class="$style.logLine">
					<span :class="[$style.level, $style['level_' + entry.level]]">{{ entry.level }}</span>
					<span :class="$style.step">{{ entry.step }}</span>
					<span :class="$style.time">{{ formatTime(entry.createdAt) }}</span>
				</div>
				<div :class="$style.message">
					{{ entry.message }}
				</div>
				<details v-if="entry.context" :class="$style.context" :open="entry.level !== 'info'">
					<summary>{{ t('travelmanager', 'Details') }}</summary>
					<div :class="$style.contextActions">
						<NcButton variant="tertiary" @click="copyDetails(entry)">
							{{ t('travelmanager', 'Copy') }}
						</NcButton>
					</div>
					<pre>{{ entry.context }}</pre>
				</details>
			</li>
		</ol>
	</NcSettingsSection>

	<NcDialog v-model:open="wipeOpen"
		:name="t('travelmanager', 'Wipe my Travel Manager data?')"
		size="small">
		{{ t('travelmanager', 'This deletes all your extracted bookings, segments and the record of which messages were already processed, so the same emails will be extracted again on the next run. Your trips are kept. This cannot be undone.') }}
		<template #actions>
			<NcButton variant="secondary" @click="wipeOpen = false">
				{{ t('travelmanager', 'Cancel') }}
			</NcButton>
			<NcButton variant="error" :disabled="wiping" @click="confirmWipe">
				{{ t('travelmanager', 'Wipe data') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
/* Give each form control breathing room; without it the outset field labels
   overlap the control above (NcTextField draws its label on the top border). */
.field {
	margin-block-end: 14px;
}

.selectField {
	display: flex;
	align-items: center;
	gap: 8px;
}

.actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 16px;
}

.logHeading {
	margin: 16px 0 8px;
}

.logEmpty {
	color: var(--color-text-maxcontrast);
}

.log {
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 420px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.logEntry {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.logEntry:last-child {
	border-bottom: none;
}

.logLine {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 0.85em;
}

.level {
	text-transform: uppercase;
	font-weight: bold;
	font-size: 0.8em;
	padding: 1px 6px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);
}

.level_warning {
	color: var(--color-warning-text, #8a6d00);
	background-color: var(--color-warning, #fdf7e6);
}

.level_error {
	color: var(--color-error-text, #fff);
	background-color: var(--color-error, #e9322d);
}

.step {
	font-family: monospace;
	color: var(--color-text-maxcontrast);
}

.time {
	margin-inline-start: auto;
	color: var(--color-text-maxcontrast);
}

.message {
	margin-top: 2px;
}

.context {
	margin-top: 4px;
}

.contextActions {
	display: flex;
	justify-content: flex-end;
	margin: 4px 0;
}

.context pre {
	white-space: pre-wrap;
	overflow-wrap: break-word;
	max-height: 480px;
	overflow-y: auto;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 8px;
	font-size: 0.85em;
}
</style>
