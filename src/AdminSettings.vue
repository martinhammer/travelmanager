<script setup lang="ts">
import { reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { loadState } from '@nextcloud/initial-state'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import LlmInfo from './LlmInfo.vue'
import { type AdminSettings, type LlmDiagnostics, saveAdminSettings } from './api'

const initial = loadState<AdminSettings>('travelmanager', 'adminSettings')
const llm = loadState<LlmDiagnostics>('travelmanager', 'llm')
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
		<NcCheckboxRadioSwitch v-model="form.enabled" :class="$style.field">
			{{ t('travelmanager', 'Enable the Travel Manager extraction pipeline') }}
		</NcCheckboxRadioSwitch>

		<!-- Reading and sending are separate steps, so these are separate settings:
		     the first decides how much mail is stored, the other two how fast it
		     reaches the model. Each helper text says what the setting cannot do as
		     well — the old "concurrent extractions" field promised a bound no app
		     can enforce, and a setting that overpromises is worse than none. -->
		<NcTextField v-model="form.fetchPerRun"
			:class="$style.field"
			type="number"
			min="1"
			:label="t('travelmanager', 'Messages read from each mailbox per run')"
			:helper-text="t('travelmanager', 'How many of the newest messages each mailbox read looks at. Reading only stores them; the two limits below decide when they reach the model.')" />
		<NcTextField v-model="form.maxInFlight"
			:class="$style.field"
			type="number"
			min="1"
			:label="t('travelmanager', 'Extractions waiting on the model at once')"
			:helper-text="t('travelmanager', 'Across all users. Keeps a large backlog from filling the AI queue other apps share. How many actually run in parallel is set by the server\'s AI workers, not here.')" />
		<NcTextField v-model="form.maxPerHour"
			:class="$style.field"
			type="number"
			min="0"
			:label="t('travelmanager', 'Extractions started per hour')"
			:helper-text="t('travelmanager', 'Across all users; 0 means no limit. Keep it below your AI provider\'s rate limit to avoid \'Too many requests\' failures.')" />

		<div :class="$style.actions">
			<NcButton variant="primary" :disabled="saving" @click="onSave">
				{{ t('travelmanager', 'Save') }}
			</NcButton>
		</div>
	</NcSettingsSection>

	<LlmInfo :provider="llm.provider"
		:prompt-template="llm.promptTemplate"
		:can-see-endpoint="llm.canSeeEndpoint" />
</template>

<style module>
/* Give each form control breathing room; without it the outset field labels
   overlap the control above (NcTextField draws its label on the top border). */
.field {
	margin-block-end: 14px;
}

.actions {
	margin-top: 16px;
}
</style>
