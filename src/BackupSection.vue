<script setup lang="ts">
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { type BackupCounts, type BackupReport, type BackupSkip, exportBackup, importBackup, previewBackup } from './api'
import { backupFileName, exportedAtDate, importReportFileName, importReportText, nothingToImport, refusalMessage, skipRoute } from './backup'

const emit = defineEmits<{ changed: [] }>()

const saveFile = (blob: Blob, name: string) => {
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = name
	link.click()
	URL.revokeObjectURL(url)
}

/* ---------------------------------------------------------------- export */

const includeEmailText = ref(true)
const exporting = ref(false)

const onExport = async () => {
	exporting.value = true
	try {
		saveFile(await exportBackup(includeEmailText.value), backupFileName())
		emit('changed')
	} catch (e) {
		showError(t('travelmanager', 'Could not create the backup'))
	} finally {
		exporting.value = false
	}
}

/* ---------------------------------------------------------------- import */

const fileInput = ref<HTMLInputElement | null>(null)
const file = ref<File | null>(null)
const replace = ref(false)
const refusal = ref<string | null>(null)
const checking = ref(false)
const importing = ref(false)
const preview = ref<BackupReport | null>(null)
const result = ref<BackupReport | null>(null)
/* What the form said when the import ran; the form resets once it succeeds. */
const resultSource = ref({ fileName: '', replaced: false, importedAt: new Date() })

const previewOpen = computed({
	get: () => preview.value !== null,
	set: (open: boolean) => {
		if (!open) {
			preview.value = null
		}
	},
})

const resultOpen = computed({
	get: () => result.value !== null,
	set: (open: boolean) => {
		if (!open) {
			result.value = null
		}
	},
})

const onFileChosen = () => {
	file.value = fileInput.value?.files?.[0] ?? null
	refusal.value = null
}

const describeFailure = (error: unknown, fallback: string) => {
	const message = refusalMessage(error)
	if (message !== null) {
		refusal.value = message
	} else {
		showError(fallback)
	}
}

const onCheck = async () => {
	if (file.value === null) {
		return
	}
	checking.value = true
	refusal.value = null
	try {
		preview.value = await previewBackup(file.value, replace.value)
	} catch (e) {
		describeFailure(e, t('travelmanager', 'Could not read the backup file'))
	} finally {
		checking.value = false
	}
}

const onImport = async () => {
	if (file.value === null || importing.value) {
		return
	}
	importing.value = true
	try {
		result.value = await importBackup(file.value, replace.value)
		resultSource.value = { fileName: file.value.name, replaced: replace.value, importedAt: new Date() }
		preview.value = null
		file.value = null
		replace.value = false
		if (fileInput.value) {
			fileInput.value.value = ''
		}
		emit('changed')
	} catch (e) {
		preview.value = null
		describeFailure(e, t('travelmanager', 'The import failed. Your data was not changed.'))
	} finally {
		importing.value = false
	}
}

const skipLink = (skip: BackupSkip): string | null => {
	const route = skipRoute(skip)
	return route === null ? null : generateUrl('/apps/travelmanager/') + route
}

const skipReason = (skip: BackupSkip): string => {
	switch (skip.reason) {
	case 'trip_exists':
		return t('travelmanager', 'A trip with this name already exists; imported bookings were added to it.')
	case 'booking_exists':
		return t('travelmanager', 'You already have this booking from the same email.')
	case 'message_exists':
		return t('travelmanager', 'This email is already in your messages.')
	case 'unfinished_no_text':
		return t('travelmanager', 'It was waiting for the model, but this backup has no email text. It will be read again if it is still in the mailbox.')
	}
}

const describeCounts = (counts: BackupCounts): string => [
	n('travelmanager', '%n trip', '%n trips', counts.trips),
	n('travelmanager', '%n booking', '%n bookings', counts.bookings),
	n('travelmanager', '%n message', '%n messages', counts.messages),
].join(', ')

const formatExportedAt = (report: BackupReport): string => exportedAtDate(report.exportedAt).toLocaleString()

const onDownloadReport = () => {
	if (result.value === null) {
		return
	}
	const report = result.value
	const { fileName, replaced, importedAt } = resultSource.value
	const summary = [
		t('travelmanager', 'Travel Manager import report'),
		t('travelmanager', 'Imported on: {date}', { date: importedAt.toLocaleString() }),
		t('travelmanager', 'File: {name}', { name: fileName }),
		t('travelmanager', 'Backup from: {date}, Travel Manager {version}', { date: formatExportedAt(report), version: report.appVersion }),
		...(replaced ? [t('travelmanager', 'Deleted first: {counts}', { counts: describeCounts(report.existing) })] : []),
		t('travelmanager', 'Imported: {counts}', { counts: describeCounts(report.imported) }),
		...(report.requeued > 0 ? [n('travelmanager', 'Queued for the model: %n email', 'Queued for the model: %n emails', report.requeued)] : []),
		n('travelmanager', 'Skipped: %n item', 'Skipped: %n items', report.skipped.length),
	]
	const text = importReportText(summary, report.skipped, skipReason, (skip) => {
		const link = skipLink(skip)
		return link === null ? null : window.location.origin + link
	})
	saveFile(new Blob([text], { type: 'text/plain;charset=utf-8' }), importReportFileName(importedAt))
}
</script>

<template>
	<NcSettingsSection :name="t('travelmanager', 'Backup & restore')"
		:description="t('travelmanager', 'Keep a copy of your bookings, trips and messages, or move them to another Nextcloud server. Your settings, including your mailbox connection, are not included.')">
		<h4 :class="$style.heading">
			{{ t('travelmanager', 'Export') }}
		</h4>
		<NcNoteCard type="warning">
			{{ t('travelmanager', 'The backup file is not encrypted, and holds your travel details in plain text. Store it somewhere safe.') }}
		</NcNoteCard>
		<NcCheckboxRadioSwitch v-model="includeEmailText">
			{{ t('travelmanager', 'Include email text and model responses') }}
		</NcCheckboxRadioSwitch>
		<p v-if="!includeEmailText" :class="$style.hint">
			{{ t('travelmanager', 'The file will be smaller and less sensitive, but messages restored from it cannot be retried. Subjects and senders are still included.') }}
		</p>
		<div :class="$style.actions">
			<NcButton variant="primary" :disabled="exporting" @click="onExport">
				{{ t('travelmanager', 'Download backup') }}
			</NcButton>
		</div>

		<h4 :class="$style.heading">
			{{ t('travelmanager', 'Import') }}
		</h4>
		<p :class="$style.hint">
			{{ t('travelmanager', 'Anything you already have is kept, and matching items in the backup are skipped and listed afterwards. Emails in the backup are not read again from a connected mailbox.') }}
		</p>
		<input ref="fileInput"
			type="file"
			accept=".json,application/json"
			:class="$style.fileInput"
			@change="onFileChosen">
		<div :class="$style.fileRow">
			<NcButton variant="secondary" @click="fileInput?.click()">
				{{ t('travelmanager', 'Choose backup file') }}
			</NcButton>
			<span v-if="file" :class="$style.fileName">{{ file.name }}</span>
		</div>
		<NcCheckboxRadioSwitch v-model="replace" type="switch">
			{{ t('travelmanager', 'Replace existing data') }}
		</NcCheckboxRadioSwitch>
		<p v-if="replace" :class="$style.hint">
			{{ t('travelmanager', 'All your bookings, trips and messages are deleted first. The backup is checked before anything is deleted, and you will be asked to confirm.') }}
		</p>
		<NcNoteCard v-if="refusal" type="error">
			{{ refusal }}
		</NcNoteCard>
		<div :class="$style.actions">
			<NcButton variant="primary" :disabled="file === null || checking" @click="onCheck">
				{{ t('travelmanager', 'Import backup') }}
			</NcButton>
		</div>
	</NcSettingsSection>

	<NcDialog v-model:open="previewOpen"
		:name="replace ? t('travelmanager', 'Replace all your data?') : t('travelmanager', 'Import this backup?')"
		size="normal">
		<template v-if="preview">
			<p :class="$style.paragraph">
				{{ t('travelmanager', 'Backup from {date}, made with Travel Manager {version}.', { date: formatExportedAt(preview), version: preview.appVersion }) }}
			</p>
			<NcNoteCard v-if="replace" type="error">
				{{ t('travelmanager', 'This permanently deletes what you have now ({existing}) and replaces it with {incoming}. This cannot be undone.', { existing: describeCounts(preview.existing), incoming: describeCounts(preview.imported) }) }}
			</NcNoteCard>
			<p v-else :class="$style.paragraph">
				{{ nothingToImport(preview)
					? t('travelmanager', 'Everything in this backup is already here, so nothing would be imported.')
					: t('travelmanager', 'This adds {incoming}.', { incoming: describeCounts(preview.imported) }) }}
				{{ preview.skipped.length > 0
					? n('travelmanager', '%n item is already here or cannot be restored, and will be skipped.', '%n items are already here or cannot be restored, and will be skipped.', preview.skipped.length)
					: '' }}
			</p>
			<NcNoteCard v-if="preview.requeued > 0" type="info">
				{{ n('travelmanager', '%n email had not been answered by the model when this backup was made. It will be sent to the model configured on this server.', '%n emails had not been answered by the model when this backup was made. They will be sent to the model configured on this server.', preview.requeued) }}
			</NcNoteCard>
			<NcNoteCard v-if="!preview.includesEmailText" type="warning">
				{{ t('travelmanager', 'This backup has no email text, so its messages cannot be retried.') }}
			</NcNoteCard>
		</template>
		<template #actions>
			<NcButton variant="secondary" @click="previewOpen = false">
				{{ t('travelmanager', 'Cancel') }}
			</NcButton>
			<NcButton :variant="replace ? 'error' : 'primary'" :disabled="importing" @click="onImport">
				{{ replace ? t('travelmanager', 'Delete and replace') : t('travelmanager', 'Import') }}
			</NcButton>
		</template>
	</NcDialog>

	<NcDialog v-model:open="resultOpen"
		:name="t('travelmanager', 'Backup imported')"
		size="normal">
		<template v-if="result">
			<p :class="$style.paragraph">
				{{ t('travelmanager', 'Imported {counts}.', { counts: describeCounts(result.imported) }) }}
			</p>
			<NcNoteCard v-if="result.requeued > 0" type="info">
				{{ n('travelmanager', '%n email is queued for the model, and will be sent by the next background job.', '%n emails are queued for the model, and will be sent by the next background job.', result.requeued) }}
			</NcNoteCard>
			<template v-if="result.skipped.length > 0">
				<h4 :class="$style.heading">
					{{ n('travelmanager', '%n item skipped', '%n items skipped', result.skipped.length) }}
				</h4>
				<ul :class="$style.skipped">
					<li v-for="(skip, index) in result.skipped" :key="index" :class="$style.skip">
						<a v-if="skipLink(skip)"
							:href="skipLink(skip) ?? undefined"
							target="_blank"
							rel="noopener noreferrer"
							:class="$style.skipLabel">{{ skip.label }}</a>
						<span v-else :class="$style.skipLabel">{{ skip.label }}</span>
						<span :class="$style.hint">{{ skipReason(skip) }}</span>
					</li>
				</ul>
			</template>
		</template>
		<template #actions>
			<NcButton variant="secondary" @click="onDownloadReport">
				{{ t('travelmanager', 'Download import report') }}
			</NcButton>
			<NcButton variant="primary" @click="resultOpen = false">
				{{ t('travelmanager', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
.heading {
	margin: 16px 0 8px;
	font-weight: bold;
}

.paragraph {
	margin-block-end: 8px;
}

.hint {
	color: var(--color-text-maxcontrast);
}

.actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 12px;
}

.fileInput {
	display: none;
}

.fileRow {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-block: 8px;
}

.fileName {
	overflow-wrap: anywhere;
}

.skipped {
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 320px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.skip {
	display: flex;
	flex-direction: column;
	padding: 6px 12px;
	border-bottom: 1px solid var(--color-border);
}

.skip:last-child {
	border-bottom: none;
}

.skipLabel {
	overflow-wrap: anywhere;
}

a.skipLabel {
	text-decoration: underline;
}
</style>
