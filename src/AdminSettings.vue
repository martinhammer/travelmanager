<script setup lang="ts">
import { reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { loadState } from '@nextcloud/initial-state'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { type AdminSettings, saveAdminSettings } from './api'

const initial = loadState<AdminSettings>('travelmanager', 'adminSettings')
const form = reactive({ ...initial })
const saving = ref(false)

const onSave = async () => {
	saving.value = true
	try {
		await saveAdminSettings({ ...form })
		showSuccess(t('travelmanager', 'Settings saved'))
	} catch (e) {
		showError(t('travelmanager', 'Could not save settings'))
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<NcSettingsSection :name="t('travelmanager', 'Travel Manager')"
		:description="t('travelmanager', 'Controls the travel-booking email extraction pipeline. Extraction uses the AI text-processing provider configured in the Nextcloud AI admin settings.')">
		<NcCheckboxRadioSwitch :checked="form.enabled" @update:checked="form.enabled = $event">
			{{ t('travelmanager', 'Enable the Travel Manager extraction pipeline') }}
		</NcCheckboxRadioSwitch>

		<NcTextField v-model:value="form.rateLimitPerRun"
			type="number"
			:label="t('travelmanager', 'Max messages processed per user per run')" />
		<NcTextField v-model:value="form.localConcurrency"
			type="number"
			:label="t('travelmanager', 'Max concurrent local-model extractions')" />

		<div :class="$style.actions">
			<NcButton variant="primary" :disabled="saving" @click="onSave">
				{{ t('travelmanager', 'Save') }}
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<style module>
.actions {
	margin-top: 16px;
}
</style>
