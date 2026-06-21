<script setup lang="ts">
import { reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { loadState } from '@nextcloud/initial-state'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { type UserSettings, saveSettings, testConnection } from './api'

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

		<NcCheckboxRadioSwitch :checked="form.enabled" @update:checked="form.enabled = $event">
			{{ t('travelmanager', 'Enable automatic extraction for my mailbox') }}
		</NcCheckboxRadioSwitch>

		<NcTextField v-model:value="form.imapHost" :label="t('travelmanager', 'IMAP host')" />
		<NcTextField v-model:value="form.imapPort" type="number" :label="t('travelmanager', 'IMAP port')" />
		<div :class="$style.field">
			<label>{{ t('travelmanager', 'Encryption') }}</label>
			<NcSelect v-model="form.imapSecurity" :options="securityOptions" :clearable="false" />
		</div>
		<NcTextField v-model:value="form.imapUser" :label="t('travelmanager', 'Account / username')" />
		<NcPasswordField v-model:value="form.imapPassword"
			:label="hasPassword ? t('travelmanager', 'App password (leave blank to keep current)') : t('travelmanager', 'App password')" />
		<NcTextField v-model:value="form.mailbox" :label="t('travelmanager', 'Mailbox / folder')" />
		<NcTextField v-model:value="form.intervalMinutes" type="number" :label="t('travelmanager', 'Check interval (minutes)')" />

		<div :class="$style.actions">
			<NcButton variant="primary" :disabled="saving" @click="onSave">
				{{ t('travelmanager', 'Save') }}
			</NcButton>
			<NcButton variant="secondary" @click="onTest">
				{{ t('travelmanager', 'Test connection') }}
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<style module>
.field {
	margin: 8px 0;
}

.actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}
</style>
